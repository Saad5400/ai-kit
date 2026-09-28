<?php

namespace Saad\AiKit\Gateway;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Tools\ToolNameResolver;
use Saad\AiKit\Catalog\ModelRouting;
use Saad\AiKit\Support\TurnContext;
use Throwable;

/**
 * Canonical OpenRouter gateway, consolidating the three app forks
 * (uqucc base + s-grade's non-stream generation-id capture).
 *
 * Deltas vs stock laravel/ai (forked from 0.10.3, reconciled against 1.0.0),
 * all additive:
 *  - client(): retry with linear backoff on transient statuses
 *  - validateTextResponse(): null-safe (OpenRouter can 200 with empty body)
 *  - buildStepBody(): injects the catalog's server-side routing (`models`
 *    chain, `provider.max_price` cap); withholds tools on the final step and
 *    injects an answer-now nudge (a tool call emitted on the final step would
 *    be silently discarded by TextGenerationLoop)
 *  - mapAttachments(): maps audio to `input_audio` content parts (0.10.3
 *    stock threw on every Audio subclass; 1.0 stock maps audio too, but its
 *    format lookup throws on a mime it does not list — retiring this
 *    override is the planned gateway diet)
 *  - parseTextResponse(): captures generation id + exact cost (non-streamed)
 *  - processTextStream(): copy of the stock 0.10.3 method with reasoning
 *    re-emission (1.0 stock now emits reasoning too), generation-id + cost
 *    capture, the markup-leak filter, and a time-to-first-token stamp at the
 *    first reasoning/text token
 *  - generateTextStep()/generateStreamStep(): circuit-breaker guard before
 *    the request, success/failure recording around it
 *  - overloadedStatusCodes(): widened from [503] so post-retry 5xx failures
 *    convert to FailoverableException and move chains to the next model
 *
 * processTextStream is the only wholesale copy; the drift-guard test pins the
 * vendor sources it was copied from and fails when upstream changes them.
 */
class ReasoningOpenRouterGateway extends OpenRouterGateway
{
    /**
     * Upstream providers the NEXT request built must exclude — set by the
     * markup-leak retry right before it re-runs a step and consumed by the
     * first body built after it, so a long-lived gateway instance (the
     * provider caches it per worker) never carries the exclusion into an
     * unrelated request.
     *
     * @var list<string>
     */
    protected array $ignoreProvidersOnce = [];

    /**
     * @param  array<string, mixed>  $config  The ai-kit.gateway config section.
     * @param  array<string, mixed>  $chat  The ai-kit.chat config section (wrap-up + tool-step knobs).
     */
    public function __construct(
        Dispatcher $events,
        protected SpendCollector $spend,
        protected array $config = [],
        protected ?ModelCircuitBreaker $breaker = null,
        protected ?ModelRouting $routing = null,
        protected array $chat = [],
    ) {
        parent::__construct($events);
    }

    /**
     * {@inheritdoc}
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->breaker?->guard($provider->name(), $model);

        try {
            $response = $this->guardedTextStep(
                $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
            );
        } catch (Throwable $exception) {
            $this->recordStepFailure($provider->name(), $model, $exception);

            throw $exception;
        }

        $this->breaker?->recordSuccess($provider->name(), $model);

        return $response;
    }

    /**
     * The parent is a generator function, so its body — including the POST —
     * only runs on first iteration. The breaker guard must run eagerly, and
     * outcomes are recorded from inside a delegating generator.
     *
     * {@inheritdoc}
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $this->breaker?->guard($provider->name(), $model);

        return $this->recordingStream($provider->name(), $model, $this->guardedStream(
            $invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
        ));
    }

    /**
     * One non-streamed step under the step guard — the same pipeline as
     * {@see guardedStream()} without events: strip → salvage → retry →
     * wrap-up. Nothing of a leaked first attempt has reached anyone here, so
     * a retry REPLACES it instead of being appended.
     */
    protected function guardedTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $step = parent::generateTextStep(
            $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
        );

        $offeredTools = $this->offeredTools($tools, $stepContext);

        if ($this->leakedWithoutToolCalls($step)) {
            $this->flagLeak($step, $provider, $model, $stepContext);

            $salvaged = $this->salvage($step, $tools, $offeredTools);

            if ($salvaged !== null) {
                $step = $salvaged;
            } elseif ($this->shouldRetryLeak()) {
                $this->excludeLeakingProviderOnce($step);

                $step = StepGuard::mergeRetry($step, parent::generateTextStep(
                    $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
                ), keepFirstText: false);
            }
        }

        $reason = $this->wrapUpReason($step, $messages, $stepContext, $offeredTools);

        if ($reason === null) {
            return $step;
        }

        $this->flagWrapUp($reason, $provider, $model, $stepContext);

        return StepGuard::merge($step, parent::generateTextStep(
            $provider,
            $model,
            $instructions,
            StepGuard::wrapUpMessages($messages, $step, $this->wrapUpInstruction($reason)),
            [],
            $schema,
            $options,
            $timeout,
            StepGuard::wrapUpContext($stepContext),
        ));
    }

    /**
     * One streamed step under the step guard. The parent's events pass
     * through untouched; what the guard adds is yielded INTO THE SAME
     * STEP, so the SDK loop, the mapper and the persisted assistant message
     * (the SDK combines every TextDelta it saw) all take the rescue as part
     * of this step's output. In order:
     *
     *  1. A step whose text carried tool-call markup but parsed no structured
     *     tool call is a provider failure. If the stripped block holds an
     *     intact DSML invoke of an OFFERED tool, it is salvaged — the tool
     *     call is yielded and the step returns as if the provider had parsed
     *     it. Otherwise the step is re-requested once, excluding the upstream
     *     that leaked; the retry's events follow the first attempt's clean
     *     narration in the same stream.
     *  2. A step that still needs a final answer ({@see StepGuard::wrapUpReason})
     *     gets one tool-less completion appended, on the same history plus
     *     the answer-now instruction, and is merged into a single response.
     *
     * A parent that ended on an `Error` event returns null; the guard steps
     * aside (the loop and the mapper already handle that path).
     *
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    protected function guardedStream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $step = yield from parent::generateStreamStep(
            $invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
        );

        if (! $step instanceof StepResponse) {
            return $step;
        }

        $offeredTools = $this->offeredTools($tools, $stepContext);

        if ($this->leakedWithoutToolCalls($step)) {
            $this->flagLeak($step, $provider, $model, $stepContext);

            $salvaged = $this->salvage($step, $tools, $offeredTools);

            if ($salvaged !== null) {
                foreach ($salvaged->toolCalls as $toolCall) {
                    yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
                }

                $step = $salvaged;
            } elseif ($this->shouldRetryLeak()) {
                $this->excludeLeakingProviderOnce($step);

                $retry = yield from $this->withinSameStep(parent::generateStreamStep(
                    $invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
                ));

                if (! $retry instanceof StepResponse) {
                    return $retry;
                }

                $step = StepGuard::mergeRetry($step, $retry, keepFirstText: true);
            }
        }

        $reason = $this->wrapUpReason($step, $messages, $stepContext, $offeredTools);

        if ($reason === null) {
            return $step;
        }

        $this->flagWrapUp($reason, $provider, $model, $stepContext);

        // The wire text must equal the merged step text the SDK persists, so
        // the paragraph break between narration and answer is yielded too —
        // and the wrap-up's own StreamStart is swallowed, because the SDK's
        // TextDelta::combine() cuts steps at StreamStart and joins them with
        // a blank line of its own, which would double this one.
        $separator = StepGuard::separator($step);

        if ($separator !== '') {
            yield (new TextDelta($this->generateEventId(), $this->generateEventId(), $separator, time()))->withInvocationId($invocationId);
        }

        $wrapUp = yield from $this->withinSameStep(parent::generateStreamStep(
            $invocationId,
            $provider,
            $model,
            $instructions,
            StepGuard::wrapUpMessages($messages, $step, $this->wrapUpInstruction($reason)),
            [],
            $schema,
            $options,
            $timeout,
            StepGuard::wrapUpContext($stepContext),
        ));

        return $wrapUp instanceof StepResponse ? StepGuard::merge($step, $wrapUp) : $step;
    }

    /**
     * Delegate a re-request the guard runs INSIDE the current step (a leak
     * retry, a wrap-up) minus its StreamStart. laravel/ai 1.0 treats every
     * StreamStart as a step boundary — TextDelta::combine() joins the text
     * on either side with a blank line, and the Vercel protocol closes the
     * step — so passing it through would split one step in two and make the
     * persisted text diverge from the wire and from the merged StepResponse.
     *
     * @param  Generator<int, StreamEvent, mixed, StepResponse|null>  $stream
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    protected function withinSameStep(Generator $stream): Generator
    {
        foreach ($stream as $event) {
            if (! $event instanceof StreamStart) {
                yield $event;
            }
        }

        return $stream->getReturn();
    }

    /**
     * Whether the model could have called a tool on this step: tools exist
     * and were not withheld by the final-step rule.
     *
     * @param  array<int, mixed>  $tools
     */
    protected function offeredTools(array $tools, StepContext $stepContext): bool
    {
        if ($tools === []) {
            return false;
        }

        $withhold = (bool) ($this->config['final_step']['withhold_tools'] ?? true);

        return ! ($withhold && $stepContext->isFinalStep);
    }

    protected function leakedWithoutToolCalls(StepResponse $step): bool
    {
        return $step instanceof InspectedStepResponse
            && $step->markupLeaked
            && $step->toolCalls === []
            && ($this->config['markup_leak']['enabled'] ?? true) !== false;
    }

    protected function shouldRetryLeak(): bool
    {
        return (bool) ($this->config['markup_leak']['retry'] ?? true);
    }

    /**
     * Turn an intact DSML invoke block into the step's tool calls, keeping
     * only calls that name a tool the step actually offered — a name the
     * loop cannot resolve would throw NoSuchToolException mid-turn.
     *
     * @param  array<int, mixed>  $tools
     */
    protected function salvage(StepResponse $step, array $tools, bool $offeredTools): ?StepResponse
    {
        if (! $offeredTools || ! ($this->config['markup_leak']['salvage'] ?? true) || ! $step instanceof InspectedStepResponse) {
            return null;
        }

        $calls = DsmlToolCallParser::parse($step->leakedMarkup);

        if ($calls === null) {
            return null;
        }

        $offered = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $offered[] = ToolNameResolver::resolve($tool);
            }
        }

        $calls = array_values(array_filter($calls, fn (ToolCall $call): bool => in_array($call->name, $offered, true)));

        if ($calls === []) {
            return null;
        }

        TurnContext::flag('markup_salvaged', true);

        return (new InspectedStepResponse(
            text: $step->text,
            toolCalls: $calls,
            finishReason: FinishReason::ToolCalls,
            usage: $step->usage,
            meta: $step->meta,
            structured: $step->structured,
            continuationToken: $step->continuationToken,
            replayBlocks: $step->replayBlocks,
            reasoning: $step->reasoning,
            providerToolCalls: $step->providerToolCalls,
        ))->inspected(true, $step->leakedMarkup, $step->providerName)->withRawResponse($step->raw);
    }

    /**
     * Arm the next request body to exclude the upstream that leaked, when
     * the response named one and the config allows it.
     */
    protected function excludeLeakingProviderOnce(StepResponse $step): void
    {
        TurnContext::flag('markup_retried', true);

        if (! ($this->config['markup_leak']['ignore_provider'] ?? true)) {
            return;
        }

        if ($step instanceof InspectedStepResponse && $step->providerName !== null) {
            $this->ignoreProvidersOnce = [$step->providerName];
        }
    }

    /**
     * @param  array<int, mixed>  $messages
     */
    protected function wrapUpReason(StepResponse $step, array $messages, StepContext $stepContext, bool $offeredTools): ?string
    {
        return StepGuard::wrapUpReason(
            $step,
            $messages,
            $stepContext,
            $offeredTools,
            $this->chat['wrap_up'] ?? [],
            leaked: $step instanceof InspectedStepResponse && $step->markupLeaked,
        );
    }

    protected function wrapUpInstruction(string $reason): string
    {
        return WrapUpInstruction::resolve($reason, $this->chat, $this->config);
    }

    protected function flagLeak(StepResponse $step, TextProvider $provider, string $model, StepContext $stepContext): void
    {
        TurnContext::flag('markup_leak', true);

        Log::warning('ai-kit: provider tool-call markup leaked into the text channel', [
            'provider' => $provider->name(),
            'upstream' => $step instanceof InspectedStepResponse ? $step->providerName : null,
            'model' => $model,
            'step' => $stepContext->stepNumber,
            'final_step' => $stepContext->isFinalStep,
            'markup_bytes' => $step instanceof InspectedStepResponse ? strlen($step->leakedMarkup) : 0,
        ]);
    }

    protected function flagWrapUp(string $reason, TextProvider $provider, string $model, StepContext $stepContext): void
    {
        TurnContext::flag('wrap_up', $reason);

        Log::info('ai-kit: step guard appended a wrap-up completion', [
            'reason' => $reason,
            'provider' => $provider->name(),
            'model' => $model,
            'step' => $stepContext->stepNumber,
            'final_step' => $stepContext->isFinalStep,
        ]);
    }

    /**
     * Delegate a step's stream while reporting its outcome to the breaker:
     * any throw (initial connection or mid-stream) counts as a failure, full
     * completion as a success.
     */
    protected function recordingStream(string $providerName, string $model, Generator $stream): Generator
    {
        try {
            yield from $stream;
        } catch (Throwable $exception) {
            $this->recordStepFailure($providerName, $model, $exception);

            throw $exception;
        }

        $this->breaker?->recordSuccess($providerName, $model);

        return $stream->getReturn();
    }

    /**
     * Only provider-health failures move the breaker: failoverable errors,
     * connection failures, and 5xx responses. Client errors (bad request,
     * auth) say nothing about the model being down.
     */
    protected function recordStepFailure(string $providerName, string $model, Throwable $exception): void
    {
        if ($this->breaker === null) {
            return;
        }

        $unhealthy = $exception instanceof FailoverableException
            || $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response?->status() >= 500);

        if ($unhealthy) {
            $this->breaker->recordFailure($providerName, $model);
        }
    }

    /**
     * Statuses that convert into ProviderOverloadedException — and therefore
     * fail over to the next model in a declared chain — once the client's
     * own retries are exhausted. Stock 1.0 maps 502/503/504/520/522/524; the
     * kit default adds 500 and 529.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return $this->config['failover']['overloaded_statuses'] ?? [500, 502, 503, 504, 520, 522, 524, 529];
    }

    /**
     * Retry transient upstream failures with linear backoff. Connection
     * exceptions are deliberately not retried: a call that already burned
     * its full timeout budget would only multiply latency.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $request = parent::client($provider, $timeout);

        $attempts = (int) ($this->config['retry']['attempts'] ?? 3);

        if ($attempts <= 1) {
            return $request;
        }

        $backoffMs = (int) ($this->config['retry']['backoff_ms'] ?? 500);
        $statuses = $this->config['retry']['statuses'] ?? [408, 409, 429, 500, 502, 503, 504];

        return $request->retry(
            $attempts,
            sleepMilliseconds: fn (int $attempt): int => $attempt * $backoffMs,
            when: fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response?->status(), $statuses, true),
            throw: true,
        );
    }

    /**
     * Stock declares array, but OpenRouter occasionally returns a 2xx whose
     * body decodes to null; that must surface as an AiException, not a
     * TypeError before stock's own emptiness guard can run.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function validateTextResponse(?array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenRouter Error: [%s] %s',
                $data['error']['type'] ?? $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Empty or invalid OpenRouter response.',
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        $withholdTools = (bool) ($this->config['final_step']['withhold_tools'] ?? true);

        if ($withholdTools && $stepContext->isFinalStep && $tools !== []) {
            $tools = [];

            $nudge = $this->config['final_step']['message'] ?? null;

            if (is_string($nudge) && $nudge !== '') {
                $messages[] = new UserMessage($nudge);
            }
        }

        $body = parent::buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        if ($withholdTools && $stepContext->isFinalStep) {
            unset($body['tools'], $body['tool_choice']);
        }

        // Only upstreams that support every requested parameter may serve a
        // tool step — an upstream without the model's tool-call parser is
        // where the markup leak comes from. Tool-less steps stay unrestricted.
        if (isset($body['tools']) && ($this->config['require_parameters'] ?? false)) {
            $body['provider'] = array_merge($body['provider'] ?? [], ['require_parameters' => true]);
        }

        // Benchmark seam: drop reasoning on the steps that follow tool results.
        if (($this->chat['reasoning_on_tool_steps'] ?? true) === false && StepGuard::followsToolActivity($messages)) {
            unset($body['reasoning']);
        }

        return $this->withServerSideRouting($body, $model);
    }

    /**
     * Hand the model's declared chain and price cap to OpenRouter to enforce.
     *
     * Anything the caller put on the body wins: an agent that passed its own
     * `models` list or provider preferences has said something more specific
     * than the catalog's default, and `provider` is merged key-wise so a
     * caller's `order`/`sort` survives alongside our `max_price`.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function withServerSideRouting(array $body, string $model): array
    {
        $fields = $this->routing?->requestFields($model) ?? [];

        if (isset($fields['models']) && ! isset($body['models'])) {
            $body['models'] = $fields['models'];
        }

        if (isset($fields['provider'])) {
            $body['provider'] = array_merge($fields['provider'], $body['provider'] ?? []);
        }

        // Consumed by exactly one body: the markup-leak retry armed it for
        // the request being built right now and for nothing after it.
        $ignore = $this->ignoreProvidersOnce;
        $this->ignoreProvidersOnce = [];

        if ($ignore !== []) {
            $body['provider'] = array_merge($body['provider'] ?? [], [
                'ignore' => array_values(array_unique([...($body['provider']['ignore'] ?? []), ...$ignore])),
            ]);
        }

        return $body;
    }

    /**
     * Map attachments to Chat Completions content parts, adding the audio
     * case stock does not have.
     *
     * OpenRouter carries audio on the chat endpoint as an `input_audio` part,
     * but stock MapsAttachments knows only images and documents and throws on
     * every `Files\Audio` subclass — which is why apps that wanted audio (and
     * the segments and cost that come with a chat completion) dropped to raw
     * HTTP instead of going through an agent. Non-audio attachments are handed
     * to the stock mapper one at a time, so its mapping and its throw on a
     * genuinely unsupported type are unchanged, and order is preserved.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function (mixed $attachment): array {
            $audio = $this->mapAudioAttachment($attachment);

            if ($audio !== null) {
                return $audio;
            }

            return array_values(parent::mapAttachments(collect([$attachment])))[0];
        })->values()->all();
    }

    /**
     * Build the `input_audio` part for an audio attachment, or null when the
     * attachment is not audio and belongs to the stock mapper.
     *
     * Every source ends up inline base64: OpenRouter has no URL form for
     * audio, so a remote or stored file is fetched here rather than passed
     * through the way a remote document is.
     *
     * @return array{type: string, input_audio: array{data: string, format: string}}|null
     */
    protected function mapAudioAttachment(mixed $attachment): ?array
    {
        if ($attachment instanceof Audio) {
            $mime = $attachment->mimeType();

            return $this->audioPart(
                $attachment instanceof Base64Audio ? $attachment->base64 : base64_encode($attachment->content()),
                is_string($mime) ? $mime : null,
                $attachment->name(),
            );
        }

        if ($attachment instanceof UploadedFile && str_starts_with($attachment->getClientMimeType(), 'audio/')) {
            return $this->audioPart(
                base64_encode($attachment->get()),
                $attachment->getClientMimeType(),
                $attachment->getClientOriginalName(),
            );
        }

        return null;
    }

    /**
     * @return array{type: string, input_audio: array{data: string, format: string}}
     */
    protected function audioPart(string $base64, ?string $mime, ?string $name): array
    {
        return [
            'type' => 'input_audio',
            'input_audio' => [
                'data' => $base64,
                'format' => $this->inputAudioFormat($mime, $name),
            ],
        ];
    }

    /**
     * Derive OpenRouter's `format` — a bare container token ("mp3", "wav"),
     * never a mime type — from the attachment's mime, falling back to its
     * filename extension. `mp3` is the last resort: it is one of the two
     * formats OpenRouter documents everywhere, and the one a browser recorder
     * or a phone upload most often produces.
     *
     * Stock's `audioFormat()` covers the same mimes but belongs to the
     * transcription endpoint and throws on anything outside its list; a chat
     * attachment can legitimately arrive with no mime at all (a stored blob,
     * a base64 string), so the fallbacks live here and an unrecognized
     * container passes through as its own token for the provider to reject.
     */
    protected function inputAudioFormat(?string $mime, ?string $name): string
    {
        $aliases = [
            'mpeg' => 'mp3',
            'mpga' => 'mp3',
            'mp4' => 'm4a',
            'x-m4a' => 'm4a',
            'wave' => 'wav',
            'vnd.wave' => 'wav',
            'x-wav' => 'wav',
            'x-pn-wav' => 'wav',
            'x-flac' => 'flac',
            'x-aac' => 'aac',
            'oga' => 'ogg',
        ];

        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        // Only an audio/* mime says anything about the container; a generic
        // application/octet-stream must not become format "octet-stream".
        $subtype = str_starts_with($mime, 'audio/') ? substr($mime, 6) : '';

        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        foreach ([$subtype, $extension] as $candidate) {
            if ($candidate !== '') {
                return $aliases[$candidate] ?? $candidate;
            }
        }

        return 'mp3';
    }

    /**
     * Capture the generation id and exact cost off non-streamed responses,
     * then delegate. Non-streamed calls are helper/pre-pass calls and are
     * recorded in the collector's non-streamed bucket.
     */
    protected function parseTextResponse(array $data, Provider $provider, bool $structured): StepResponse
    {
        if (isset($data['id']) && is_string($data['id']) && $data['id'] !== '') {
            $this->spend->recordGenerationId($data['id'], streamed: false);
        }

        $cost = $this->extractOpenRouterCost($data);

        if ($cost !== null) {
            $this->spend->recordCost($cost, streamed: false);
        }

        $step = parent::parseTextResponse($data, $provider, $structured);

        // Same leak treatment as the streamed path: markup never reaches the
        // caller's text, and what was stripped rides along for salvage.
        $filter = $this->markupLeakFilter();
        $text = $filter->push($step->text).$filter->flush();

        return (new InspectedStepResponse(
            text: $text,
            toolCalls: $step->toolCalls,
            finishReason: $step->finishReason,
            usage: $step->usage,
            meta: $step->meta,
            structured: $step->structured,
            continuationToken: $step->continuationToken,
            replayBlocks: $step->replayBlocks,
            pendingApprovals: $step->pendingApprovals,
            reasoning: $step->reasoning,
            providerToolCalls: $step->providerToolCalls,
        ))->inspected(
            $filter->leaked(),
            $filter->removed(),
            is_string($data['provider'] ?? null) ? $data['provider'] : null,
        )->withRawResponse($step->raw);
    }

    /**
     * A fresh sanitizer per step: it holds text between deltas, so it can
     * never be shared across steps or turns.
     */
    protected function markupLeakFilter(): MarkupLeakFilter
    {
        return MarkupLeakFilter::fromConfig($this->config['markup_leak'] ?? []);
    }

    /**
     * Copy of the stock 0.10.3 method with additive changes: reasoning
     * re-emission (state machine below), generation-id capture, exact cost
     * capture and the markup-leak filter. Everything else — including the
     * citation block the old app forks accidentally dropped — is stock.
     * Reconciled against 1.0.0: its only changes were reasoning emission
     * (ported: the `reasoning_details` fallback) and TextUsage.
     *
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $streamModel = $model;
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;

        // Fork state: reasoning re-emission + spend capture + leak guard.
        $reasoningId = '';
        $inReasoning = false;
        $generationId = '';
        $openRouterCost = null;
        $providerName = null;
        $leakFilter = $this->markupLeakFilter();

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            // Every chunk carries the generation id; keep the latest.
            if (isset($data['id']) && is_string($data['id']) && $data['id'] !== '') {
                $generationId = $data['id'];
            }

            // OpenRouter names the upstream it routed to on every chunk; a
            // retry after a markup leak excludes that provider.
            if (isset($data['provider']) && is_string($data['provider']) && $data['provider'] !== '') {
                $providerName = $data['provider'];
            }

            if (isset($data['error'])) {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['code'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                    $openRouterCost = $this->extractOpenRouterCost($data) ?? $openRouterCost;
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            // Handle error finish reason from OpenRouter...
            if (($choice['finish_reason'] ?? null) === 'error') {
                $error = $choice['error'] ?? [];

                yield (new Error(
                    $this->generateEventId(),
                    (string) ($error['code'] ?? 'provider_error'),
                    $error['message'] ?? 'An upstream provider error occurred.',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $streamModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $streamModel,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Close the reasoning block as soon as visible output begins.
            if ($inReasoning && ((isset($delta['content']) && $delta['content'] !== '') || isset($delta['tool_calls']))) {
                $inReasoning = false;

                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = '';
            }

            // Re-emit reasoning: OpenRouter's "reasoning" delta field,
            // DeepSeek-style "reasoning_content", and — as stock 1.0 does —
            // the text of "reasoning_details" when neither is present.
            $reasoning = $delta['reasoning'] ?? $delta['reasoning_content'] ?? null;

            if (! is_string($reasoning) || $reasoning === '') {
                $reasoning = $this->reasoningTextIn($delta['reasoning_details'] ?? []);
            }

            if (is_string($reasoning) && $reasoning !== '') {
                if (! $inReasoning) {
                    $inReasoning = true;
                    $reasoningId = $this->generateEventId();

                    TurnContext::stampTtftOnce();

                    yield (new ReasoningStart(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                yield (new ReasoningDelta(
                    $this->generateEventId(),
                    $reasoningId,
                    $reasoning,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Content runs through the leak filter first: a marker and
            // everything after it is swallowed (kept for salvage), a tail
            // that might begin a marker is held for the next chunk, and a
            // step whose text is nothing but markup never opens a text block.
            $visible = isset($delta['content']) && $delta['content'] !== ''
                ? $leakFilter->push($delta['content'])
                : '';

            if ($visible !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    TurnContext::stampTtftOnce();

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $visible;

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $visible,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = $tcDelta['index'];

                    if (! isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => $tcDelta['id'] ?? '',
                            'name' => $tcDelta['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($delta['annotations'])) {
                foreach ($delta['annotations'] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        $urlCitation = $annotation['url_citation'] ?? [];

                        yield (new CitationEvent(
                            $this->generateEventId(),
                            $messageId,
                            new UrlCitation(
                                $urlCitation['url'] ?? '',
                                $urlCitation['title'] ?? null,
                                isset($urlCitation['start_index']) ? (int) $urlCitation['start_index'] : null,
                                isset($urlCitation['end_index']) ? (int) $urlCitation['end_index'] : null,
                            ),
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }
            }

            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
                $openRouterCost = $this->extractOpenRouterCost($data) ?? $openRouterCost;
            }
        }

        // Close a reasoning block that never gave way to content/tools.
        if ($inReasoning) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        // Release a tail the filter was still holding (a `<` that never
        // became a marker), then close the text block.
        $tail = $leakFilter->flush();

        if ($tail !== '') {
            if (! $textStartEmitted) {
                $textStartEmitted = true;

                yield (new TextStart(
                    $this->generateEventId(),
                    $messageId,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $currentText .= $tail;

            yield (new TextDelta(
                $this->generateEventId(),
                $messageId,
                $tail,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            foreach (array_values($pendingToolCalls) as $pending) {
                $toolCall = new ToolCall(
                    $pending['id'] ?? '',
                    $pending['name'] ?? '',
                    json_decode($pending['arguments'] ?? '{}', true) ?? [],
                    $pending['id'] ?? null,
                );

                $toolCalls[] = $toolCall;

                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        // One push per step; the loop's tool rounds call processTextStream
        // again and push their own.
        if ($generationId !== '') {
            $this->spend->recordGenerationId($generationId, streamed: true);
        }

        if ($openRouterCost !== null) {
            $this->spend->recordCost($openRouterCost, streamed: true);
        }

        return (new InspectedStepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $streamModel),
        ))->inspected($leakFilter->leaked(), $leakFilter->removed(), $providerName);
    }

    /**
     * OpenRouter reports the exact charge as usage.cost when usage
     * accounting is requested. Zero/absent/non-numeric all return null —
     * the signal for callers to fall back to token-based estimation.
     */
    public function extractOpenRouterCost(array $data): ?float
    {
        $cost = $data['usage']['cost'] ?? null;

        if (! is_numeric($cost)) {
            return null;
        }

        $cost = (float) $cost;

        return $cost > 0 ? $cost : null;
    }
}
