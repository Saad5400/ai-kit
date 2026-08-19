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

function derivedFormat(?string $mime, ?string $name): string
{
    return (new ReflectionMethod(GatewayFactory::gateway(), 'inputAudioFormat'))
        ->invoke(GatewayFactory::gateway(), $mime, $name);
}

it('maps base64 audio to an input_audio part', function () {
    $parts = attachmentParts([Audio::fromBase64(base64_encode('fake-mp3-bytes'), 'audio/mpeg')]);

    expect($parts)->toBe([[
        'type' => 'input_audio',
        'input_audio' => [
            'data' => base64_encode('fake-mp3-bytes'),
            'format' => 'mp3',
        ],
    ]]);
});

it('inlines a local audio file as base64', function () {
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-audio').'.wav';
    file_put_contents($path, 'fake-wav-bytes');

    $parts = attachmentParts([Audio::fromPath($path, 'audio/wav')]);

    expect($parts[0]['input_audio'])->toBe([
        'data' => base64_encode('fake-wav-bytes'),
        'format' => 'wav',
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
            'data' => base64_encode('fake-ogg-bytes'),
            'format' => 'ogg',
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
    expect(derivedFormat($mime, null))->toBe($expected);
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
]);

it('falls back to the filename extension when the mime says nothing', function () {
    expect(derivedFormat(null, 'lecture.WAV'))->toBe('wav')
        ->and(derivedFormat('application/octet-stream', 'clip.m4a'))->toBe('m4a')
        ->and(derivedFormat(null, 'https://example.com/audio/clip.ogg'))->toBe('ogg');
});

it('defaults to mp3 when neither mime nor name says anything', function () {
    expect(derivedFormat(null, null))->toBe('mp3')
        ->and(derivedFormat('application/octet-stream', 'recording'))->toBe('mp3');
});

it('reads the format off a base64 audio with no mime through the extension', function () {
    $parts = attachmentParts([(new Base64Audio('YXVkaW8='))->as('interview.flac')]);

    expect($parts[0]['input_audio']['format'])->toBe('flac');
});
