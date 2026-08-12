<?php

namespace Saad\AiKit\Gateway;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
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
use Throwable;

/**
 * Canonical OpenRouter gateway, consolidating the three app forks
 * (uqucc base + s-grade's non-stream generation-id capture).
 *
 * Deltas vs stock laravel/ai 0.10.3, all additive:
 *  - client(): retry with linear backoff on transient statuses
 *  - validateTextResponse(): null-safe (OpenRouter can 200 with empty body)
 *  - buildStepBody(): forces usage accounting; withholds tools on the final
 *    step and injects an answer-now nudge (a tool call emitted on the final
 *    step would be silently discarded by TextGenerationLoop)
 *  - parseTextResponse(): captures generation id + exact cost (non-streamed)
 *  - processTextStream(): copy of the stock method with reasoning re-emission
 *    (stock drops delta.reasoning), generation-id + cost capture
 *
 * processTextStream is the only wholesale copy; the drift-guard test pins the
 * vendor sources it was copied from and fails when upstream changes them.
 */
class ReasoningOpenRouterGateway extends OpenRouterGateway
{
    /**
     * @param  array<string, mixed>  $config  The ai-kit.gateway config section.
     */
    public function __construct(
        Dispatcher $events,
        protected SpendCollector $spend,
        protected array $config = [],
    ) {
        parent::__construct($events);
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

        if ($this->config['force_usage_accounting'] ?? true) {
            $body['usage'] = array_merge($body['usage'] ?? [], ['include' => true]);
        }

        return $body;
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

        return parent::parseTextResponse($data, $provider, $structured);
    }

    /**
     * Copy of the stock 0.10.3 method with three additive changes: reasoning
     * re-emission (state machine below), generation-id capture, and exact
     * cost capture. Everything else — including the citation block the old
     * app forks accidentally dropped — is stock.
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

        // Fork state: reasoning re-emission + spend capture.
        $reasoningId = '';
        $inReasoning = false;
        $generationId = '';
        $openRouterCost = null;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            // Every chunk carries the generation id; keep the latest.
            if (isset($data['id']) && is_string($data['id']) && $data['id'] !== '') {
                $generationId = $data['id'];
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

            // Emit the reasoning the stock gateway drops (OpenRouter uses the
            // "reasoning" delta field; DeepSeek-style "reasoning_content" too).
            $reasoning = $delta['reasoning'] ?? $delta['reasoning_content'] ?? null;

            if (is_string($reasoning) && $reasoning !== '') {
                if (! $inReasoning) {
                    $inReasoning = true;
                    $reasoningId = $this->generateEventId();

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

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
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

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $streamModel),
        );
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
