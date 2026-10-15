<?php

namespace Saad\AiKit\Transcription;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Saad\AiKit\Catalog\Catalog;
use Saad\AiKit\Rag\Support\OpenRouterEndpoint;
use Saad\AiKit\Usage\UsageEvent;
use Throwable;

/**
 * The CLOCK half of a transcript (DECISIONS.md #30): word-level timestamps for
 * one audio clip from the `transcription` lane's timing model, on OpenRouter's
 * `/audio/transcriptions` endpoint (`response_format: verbose_json`,
 * `timestamp_granularities: [word]` — the shape ElevenLabs Scribe, Deepgram,
 * OpenAI and the Whisper hosts all answer on).
 *
 * Raw HTTP rather than laravel/ai's Transcription: its response keeps segments
 * and drops the `words` array, the only part wanted here.
 *
 * primary → primary → fallback; when every attempt fails it throws
 * {@see WordTimingFailed} and the caller decides how to degrade. Each answered
 * call writes an `ai_usage_events` row (agent = this class, feature = the
 * ambient usage feature, provider-reported cost) when the usage module is on,
 * so speech-to-text spend is visible beside the agent turns.
 */
class WordTimingClient
{
    public function __construct(
        private readonly ?OpenRouterEndpoint $endpoint = null,
    ) {}

    /** The lane's timing model, or the given override. */
    public function model(?string $override = null): string
    {
        return $override ?? app(Catalog::class)->transcriptionTimingModel();
    }

    /**
     * @param  string  $audio  raw audio bytes
     * @param  string  $format  container token: mp3, wav, m4a…
     * @param  string|null  $language  BCP-47; only the primary subtag is sent ("ar-SA" → "ar")
     *
     * @throws WordTimingFailed when the timing model and its fallback all fail
     */
    public function transcribe(
        string $audio,
        string $format,
        ?string $language = null,
        ?string $model = null,
        ?string $fallbackModel = null,
        int $timeoutSeconds = 120,
    ): WordTimings {
        $primary = $this->model($model);
        $fallback = $fallbackModel ?? app(Catalog::class)->transcriptionTimingFallbackModel();
        $last = null;

        foreach ([$primary, $primary, $fallback !== '' ? $fallback : $primary] as $slug) {
            try {
                return $this->request($slug, $audio, $format, $language, $timeoutSeconds);
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        throw new WordTimingFailed(
            "Word timestamps unavailable from {$primary}".($fallback !== $primary ? " and {$fallback}" : '').': '.($last?->getMessage() ?? 'unknown'),
            previous: $last,
        );
    }

    private function request(string $model, string $audio, string $format, ?string $language, int $timeoutSeconds): WordTimings
    {
        $endpoint = $this->endpoint ?? OpenRouterEndpoint::make('openrouter');
        $started = hrtime(true);

        $response = $endpoint->request()
            ->timeout($timeoutSeconds)
            ->post($endpoint->url('audio/transcriptions'), array_filter([
                'model' => $model,
                'input_audio' => ['data' => base64_encode($audio), 'format' => $format],
                'language' => $language !== null && $language !== '' ? strtolower(explode('-', $language)[0]) : null,
                'response_format' => 'verbose_json',
                'timestamp_granularities' => ['word'],
            ]));

        if ($response->failed()) {
            throw new WordTimingFailed("{$model} answered HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        $rows = $response->json('words');

        if (! is_array($rows)) {
            throw new WordTimingFailed("{$model} answered with no `words` array.");
        }

        $words = [];
        foreach ($rows as $row) {
            $text = is_array($row) ? trim((string) ($row['word'] ?? $row['text'] ?? '')) : '';

            if ($text === '' || ! is_numeric($row['start'] ?? null) || ! is_numeric($row['end'] ?? null)) {
                continue;
            }

            $words[] = new TimedWord((int) round((float) $row['start'] * 1000), (int) round((float) $row['end'] * 1000), $text);
        }

        usort($words, fn (TimedWord $a, TimedWord $b): int => $a->startMs <=> $b->startMs);

        $timings = new WordTimings(
            words: $words,
            text: trim((string) $response->json('text', '')),
            costUsd: OpenRouterEndpoint::cost($response->json('usage')),
            model: $model,
        );

        $this->recordUsage($timings, $endpoint->provider(), (int) ((hrtime(true) - $started) / 1_000_000), $response->json('usage.seconds'));

        return $timings;
    }

    /** One `ai_usage_events` row per answered call — never allowed to fail the call. */
    private function recordUsage(WordTimings $timings, string $provider, int $durationMs, mixed $audioSeconds): void
    {
        if (config('ai-kit.modules.usage', true) !== true) {
            return;
        }

        rescue(fn () => UsageEvent::create([
            'invocation_id' => (string) Str::uuid(),
            'agent' => self::class,
            'feature' => Context::get((string) config('ai-kit.usage.feature_context_key', 'ai-kit.feature')),
            'provider' => $provider,
            'model' => $timings->model,
            'streamed' => false,
            'cost_usd' => $timings->costUsd,
            'cost_source' => $timings->costUsd !== null ? 'provider' : null,
            'duration_ms' => $durationMs,
            'status' => 'ok',
            'context' => array_filter([
                'audio_seconds' => is_numeric($audioSeconds) ? (float) $audioSeconds : null,
                'words' => count($timings->words),
            ], fn ($v) => $v !== null),
            'created_at' => now(),
        ]), report: false);
    }
}
