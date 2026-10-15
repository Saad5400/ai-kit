<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Saad\AiKit\Transcription\WordTimingClient;
use Saad\AiKit\Transcription\WordTimingFailed;
use Saad\AiKit\Usage\UsageEvent;

uses(RefreshDatabase::class);

const STT = 'openrouter.ai/api/v1/audio/transcriptions';

function sttAnswer(array $words, string $text = '', ?float $cost = 0.0011): array
{
    return array_filter([
        'text' => $text,
        'words' => $words,
        'usage' => $cost === null ? null : ['seconds' => 300, 'cost' => $cost],
    ], fn ($v) => $v !== null);
}

it('asks the lane timing model for verbose_json word timestamps and reads them in ms, in time order', function () {
    Http::fake([STT => Http::response(sttAnswer([
        ['word' => ' نعرف', 'start' => 0.9, 'end' => 1.3],
        ['word' => 'الحين', 'start' => 0.5, 'end' => 0.9],
        ['word' => '   ', 'start' => 1.3, 'end' => 1.4],
        ['word' => 'بلا وقت'],
    ], 'الحين نعرف'))]);

    $timings = (new WordTimingClient)->transcribe('AUDIO', 'mp3', 'ar-SA');

    expect(array_map(fn ($w) => [$w->startMs, $w->endMs, $w->text], $timings->words))->toBe([[500, 900, 'الحين'], [900, 1300, 'نعرف']])
        ->and($timings->text)->toBe('الحين نعرف')
        ->and($timings->costUsd)->toBe(0.0011)
        ->and($timings->model)->toBe('elevenlabs/scribe-v2')
        ->and($timings->silent())->toBeFalse();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://openrouter.ai/api/v1/audio/transcriptions'
        && $r->hasHeader('Authorization', 'Bearer test-key')
        && $r['model'] === 'elevenlabs/scribe-v2'
        && $r['response_format'] === 'verbose_json'
        && $r['timestamp_granularities'] === ['word']
        && $r['language'] === 'ar'
        && $r['input_audio'] === ['data' => base64_encode('AUDIO'), 'format' => 'mp3']);
});

it('records each answered call in ai_usage_events under the ambient feature', function () {
    Context::add((string) config('ai-kit.usage.feature_context_key'), 'transcription');
    Http::fake([STT => Http::response(sttAnswer([['word' => 'a', 'start' => 0, 'end' => 0.2]], 'a'))]);

    (new WordTimingClient)->transcribe('AUDIO', 'mp3');

    $row = UsageEvent::query()->sole();

    expect($row->agent)->toBe(WordTimingClient::class)
        ->and($row->feature)->toBe('transcription')
        ->and($row->model)->toBe('elevenlabs/scribe-v2')
        ->and((float) $row->cost_usd)->toBe(0.0011)
        ->and($row->cost_source)->toBe('provider')
        ->and($row->context)->toEqual(['audio_seconds' => 300, 'words' => 1]);
});

it('tells silence (no words, no text) apart from a model that ignored word timestamps', function () {
    Http::fake([STT => Http::sequence()
        ->push(sttAnswer([], ''))
        ->push(sttAnswer([], 'كلام بلا توقيت'))]);

    $client = new WordTimingClient;

    expect($client->transcribe('A', 'mp3')->silent())->toBeTrue()
        ->and($client->transcribe('A', 'mp3')->silent())->toBeFalse();
});

it('retries the primary once, then the fallback, then throws', function () {
    Http::fake([STT => fn (Request $r) => $r['model'] === 'deepgram/nova-3'
        ? Http::response(sttAnswer([['word' => 'ok', 'start' => 0, 'end' => 1]], 'ok'))
        : Http::response(['error' => 'busy'], 503)]);

    expect((new WordTimingClient)->transcribe('A', 'mp3')->model)->toBe('deepgram/nova-3');
    expect(collect(Http::recorded())->map(fn ($p) => $p[0]['model'])->all())
        ->toBe(['elevenlabs/scribe-v2', 'elevenlabs/scribe-v2', 'deepgram/nova-3']);

    Http::fake([STT => Http::response(['no' => 'words'])]);

    expect(fn () => (new WordTimingClient)->transcribe('A', 'mp3', model: 'x/clock', fallbackModel: 'y/clock'))
        ->toThrow(WordTimingFailed::class, 'Word timestamps unavailable from x/clock and y/clock');
});
