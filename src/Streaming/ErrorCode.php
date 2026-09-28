<?php

namespace Saad\AiKit\Streaming;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Streaming\Events\Error;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Throwable;

/**
 * The machine-readable `code` on the kit's terminal `error {message, code}`
 * frame — the same role AG-UI's RUN_ERROR `code` plays. `message` stays the
 * display line; `code` is what a client branches on (offer a retry, show a
 * "try again later" state, stop retrying).
 *
 * The vocabulary is the kit's own and deliberately small: provider error
 * codes and exception classes never reach the wire (these apps are
 * public-facing), so the provider's `type` is folded into one of these.
 * `code` is OPTIONAL on the wire contract — a client must treat an unknown
 * or absent code as {@see STREAM_ERROR}-like, "something failed".
 */
final class ErrorCode
{
    /** The provider reported an error inside the stream (OpenRouter's `{"error":…}` frame). */
    public const STREAM_ERROR = 'stream_error';

    /** The provider is down, overloaded or unreachable (5xx, 408, a dropped connection). Retrying later can work. */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** The provider rate-limited the request (429). */
    public const RATE_LIMITED = 'rate_limited';

    /** The kill switch stopped the turn before it spent anything. */
    public const KILLED = 'killed';

    /** The stale watchdog failed a turn whose worker stopped heartbeating. */
    public const STALE = 'stale';

    /** Anything else that threw — a bug, a tool the loop could not settle, a store failure. */
    public const INTERNAL_ERROR = 'internal_error';

    /**
     * The code for a provider's in-stream {@see Error} event, from its `type`
     * (OpenRouter puts the upstream HTTP status there: `502`, `429`, …).
     */
    public static function forError(Error $error): string
    {
        $type = strtolower(trim($error->type));

        if ($type === '429' || str_contains($type, 'rate_limit') || str_contains($type, 'rate-limit')) {
            return self::RATE_LIMITED;
        }

        if ($type === '408'
            || (ctype_digit($type) && (int) $type >= 500)
            || str_contains($type, 'overloaded')
            || str_contains($type, 'unavailable')
            || str_contains($type, 'timeout')) {
            return self::PROVIDER_UNAVAILABLE;
        }

        return self::STREAM_ERROR;
    }

    /**
     * The code for a turn that ended by throwing. Null only for a null
     * exception with nothing else to go on.
     */
    public static function forException(?Throwable $exception): ?string
    {
        return match (true) {
            $exception === null => null,
            $exception instanceof StreamErrorException => $exception->error !== null
                ? self::forError($exception->error)
                : self::STREAM_ERROR,
            $exception instanceof RateLimitedException => self::RATE_LIMITED,
            $exception instanceof AiKilledException => self::KILLED,
            $exception instanceof ProviderOverloadedException,
            $exception instanceof ProviderConnectionException,
            $exception instanceof ConnectionException => self::PROVIDER_UNAVAILABLE,
            $exception instanceof RequestException => match (true) {
                $exception->response->status() === 429 => self::RATE_LIMITED,
                $exception->response->status() === 408,
                $exception->response->status() >= 500 => self::PROVIDER_UNAVAILABLE,
                default => self::INTERNAL_ERROR,
            },
            default => self::INTERNAL_ERROR,
        };
    }
}
