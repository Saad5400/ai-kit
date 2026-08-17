<?php

namespace Saad\AiKit\Credits;

use Illuminate\Contracts\Support\Responsable;
use RuntimeException;

/**
 * The canonical 402 for a refused AI action. Thrown (or returned via
 * toResponse) by app gates; the body is the contract every AI UI maps:
 *
 *   { "code": "<reason>", "reason": "<reason>", "message": "<localized>", "balance": <int?> }
 *
 * `code` is the machine surface and `reason` its legacy alias — both carry
 * the same slug (e.g. insufficient_credits, no_course_access,
 * subscription_required). `balance` is included when the gate resolved one,
 * so the client can render the upsell with the real figure.
 */
class InsufficientCreditsException extends RuntimeException implements Responsable
{
    public function __construct(
        public readonly string $reason = 'insufficient_credits',
        public readonly ?int $balance = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? __('ai-kit::credits.insufficient'));
    }

    public function toResponse($request)
    {
        $body = [
            'code' => $this->reason,
            'reason' => $this->reason,
            'message' => $this->getMessage(),
        ];

        if ($this->balance !== null) {
            $body['balance'] = $this->balance;
        }

        return response()->json($body, 402);
    }
}
