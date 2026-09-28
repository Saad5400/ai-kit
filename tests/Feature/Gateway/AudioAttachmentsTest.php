<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Saad\AiKit\Tests\Support\GatewayFactory;

/** The content parts a user message's attachments become on the request body. */
function attachmentParts(array $attachments): array
{
    $body = GatewayFactory::buildStepBody(
        GatewayFactory::gateway(),
        GatewayFactory::provider(),
        'test/model',
        null,
        [new UserMessage('transcribe this', $attachments)],
        [],
        null,
        null,
        new StepContext(stepNumber: 1, isFinalStep: false),
    );

    // Index 0 is the text part the vendor mapper puts ahead of attachments.
    return array_slice($body['messages'][0]['content'], 1);
}

function derivedFormat(?string $mime): string
{
    return (new ReflectionMethod(GatewayFactory::gateway(), 'audioFormat'))
        ->invoke(GatewayFactory::gateway(), $mime);
}

it('maps base64 audio to an input_audio part', function () {
    $parts = attachmentParts([Audio::fromBase64(base64_encode('fake-mp3-bytes'), 'audio/mpeg')]);

    expect($parts)->toBe([[
        'type' => 'input_audio',
        'input_audio' => [
            'format' => 'mp3',
            'data' => base64_encode('fake-mp3-bytes'),
        ],
    ]]);
});

it('inlines a local audio file as base64', function () {
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-audio').'.wav';
    file_put_contents($path, 'fake-wav-bytes');

    $parts = attachmentParts([Audio::fromPath($path, 'audio/wav')]);

    expect($parts[0]['input_audio'])->toBe([
        'format' => 'wav',
        'data' => base64_encode('fake-wav-bytes'),
    ]);

    unlink($path);
});

it('inlines a stored audio file as base64', function () {
    Storage::fake('audio');
    Storage::disk('audio')->put('clips/lecture.mp3', 'fake-stored-bytes');

    $parts = attachmentParts([Audio::fromStorage('clips/lecture.mp3', 'audio')]);

    expect($parts[0]['type'])->toBe('input_audio')
        ->and($parts[0]['input_audio']['data'])->toBe(base64_encode('fake-stored-bytes'))
        ->and($parts[0]['input_audio']['format'])->toBe('mp3');
});

it('maps an uploaded audio file', function () {
    $parts = attachmentParts([UploadedFile::fake()->createWithContent('note.ogg', 'fake-ogg-bytes')]);

    expect($parts[0])->toBe([
        'type' => 'input_audio',
        'input_audio' => [
            'format' => 'ogg',
            'data' => base64_encode('fake-ogg-bytes'),
        ],
    ]);
})->skip(fn () => ! str_starts_with(
    UploadedFile::fake()->createWithContent('note.ogg', '')->getClientMimeType(),
    'audio/',
), 'The test environment does not resolve .ogg to an audio mime type.');

it('leaves images and documents to the stock mapper', function () {
    $parts = attachmentParts([
        Image::fromBase64('aW1n', 'image/png'),
        Document::fromBase64('ZG9j', 'application/pdf')->as('report.pdf'),
    ]);

    expect($parts[0])->toBe([
        'type' => 'image_url',
        'image_url' => ['url' => 'data:image/png;base64,aW1n'],
    ])->and($parts[1])->toBe([
        'type' => 'file',
        'file' => ['filename' => 'report.pdf', 'file_data' => 'data:application/pdf;base64,ZG9j'],
    ]);
});

it('keeps mixed attachments in the order they were given', function () {
    $parts = attachmentParts([
        Image::fromBase64('aW1n', 'image/png'),
        Audio::fromBase64('YXVkaW8=', 'audio/wav'),
        Document::fromBase64('ZG9j', 'application/pdf')->as('report.pdf'),
    ]);

    expect(array_column($parts, 'type'))->toBe(['image_url', 'input_audio', 'file']);
});

it('still throws on an attachment type nothing supports', function () {
    attachmentParts([new stdClass]);
})->throws(InvalidArgumentException::class, 'Unsupported attachment type');

it('derives the format from an audio mime type', function (string $mime, string $expected) {
    expect(derivedFormat($mime))->toBe($expected);
})->with([
    ['audio/mpeg', 'mp3'],
    ['audio/mp3', 'mp3'],
    ['audio/mpga', 'mp3'],
    ['audio/wav', 'wav'],
    ['audio/x-wav', 'wav'],
    ['audio/wave', 'wav'],
    ['audio/vnd.wave', 'wav'],
    ['audio/mp4', 'm4a'],
    ['audio/x-m4a', 'm4a'],
    ['audio/ogg', 'ogg'],
    ['audio/webm', 'webm'],
    ['audio/flac', 'flac'],
    ['audio/x-flac', 'flac'],
    ['AUDIO/MPEG; codecs=mp3', 'mp3'],
    ['audio/aiff', 'aiff'],
    ['audio/x-aiff', 'aiff'],
    ['audio/aac', 'aac'],
]);

it('tolerates the mimes stock 1.0 throws on', function (?string $mime, string $expected) {
    expect(derivedFormat($mime))->toBe($expected);
})->with([
    'MediaRecorder webm/opus' => ['audio/webm;codecs=opus', 'webm'],
    'spaced codecs parameter' => ['audio/webm; codecs="opus"', 'webm'],
    'finfo on a webm recording' => ['video/webm', 'webm'],
    'finfo on an m4a' => ['video/mp4', 'm4a'],
    'mpga' => ['audio/mpga', 'mp3'],
    'unlisted container passes through' => ['audio/opus', 'opus'],
    'generic binary' => ['application/octet-stream', 'mp3'],
    'no mime at all' => [null, 'mp3'],
    'empty mime' => ['', 'mp3'],
]);

it('maps a MediaRecorder webm/opus attachment instead of throwing', function () {
    $parts = attachmentParts([Audio::fromBase64('YXVkaW8=', 'audio/webm;codecs=opus')]);

    expect($parts)->toBe([[
        'type' => 'input_audio',
        'input_audio' => ['format' => 'webm', 'data' => 'YXVkaW8='],
    ]]);
});

it('defaults a base64 audio with no mime to mp3, as stock does', function () {
    $parts = attachmentParts([(new Base64Audio('YXVkaW8='))->as('interview.flac')]);

    expect($parts[0]['input_audio']['format'])->toBe('mp3');
});
