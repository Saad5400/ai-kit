<?php

use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Streaming\Events\Error;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Streaming\ErrorCode;

function codeFor(string $type): string
{
    return ErrorCode::forError(new Error('e1', $type, 'message', false, 1));
}

it('folds a provider error type into the kit vocabulary', function (string $type, string $code) {
    expect(codeFor($type))->toBe($code);
})->with([
    'OpenRouter 502' => ['502', ErrorCode::PROVIDER_UNAVAILABLE],
    'OpenRouter 503' => ['503', ErrorCode::PROVIDER_UNAVAILABLE],
    'timeout status' => ['408', ErrorCode::PROVIDER_UNAVAILABLE],
    'Anthropic overloaded' => ['overloaded_error', ErrorCode::PROVIDER_UNAVAILABLE],
    'rate limit status' => ['429', ErrorCode::RATE_LIMITED],
    'rate limit type' => ['rate_limit_error', ErrorCode::RATE_LIMITED],
    'anything else' => ['provider_error', ErrorCode::STREAM_ERROR],
    'a client status' => ['400', ErrorCode::STREAM_ERROR],
]);

it('codes a thrown exception', function () {
    expect(ErrorCode::forException(null))->toBeNull()
        ->and(ErrorCode::forException(new StreamErrorException(new Error('e1', '502', 'm', false, 1))))->toBe(ErrorCode::PROVIDER_UNAVAILABLE)
        ->and(ErrorCode::forException(new StreamErrorException))->toBe(ErrorCode::STREAM_ERROR)
        ->and(ErrorCode::forException(new ProviderOverloadedException('down')))->toBe(ErrorCode::PROVIDER_UNAVAILABLE)
        ->and(ErrorCode::forException(new ConnectionException('reset')))->toBe(ErrorCode::PROVIDER_UNAVAILABLE)
        ->and(ErrorCode::forException(new RateLimitedException('slow')))->toBe(ErrorCode::RATE_LIMITED)
        ->and(ErrorCode::forException(new AiKilledException('off')))->toBe(ErrorCode::KILLED)
        ->and(ErrorCode::forException(new RuntimeException('bug')))->toBe(ErrorCode::INTERNAL_ERROR);
});
