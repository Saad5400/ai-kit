<?php

namespace Saad\AiKit\Bench;

use Illuminate\Contracts\Auth\Authenticatable;
use Saad\AiKit\Testing\FakeTurnDriver;

/**
 * The app's half of the bench: drives ONE assistant turn the way the app's
 * real chat surface does (same agent, same tools, same approval seam) and
 * folds what happened into a {@see TurnRecord}. The kit never implements
 * this — each app binds its own; {@see FakeTurnDriver}
 * replays scripted records for tests.
 */
interface TurnDriver
{
    /**
     * Run ONE turn as $actor. $conversationId continues an existing thread
     * (null starts a new one). $files is a list of local attachments.
     *
     * @param  list<array{mime: string, name: string, path: string}>  $files
     */
    public function run(
        Authenticatable $actor,
        string $prompt,
        array $files = [],
        ?string $conversationId = null,
        ?string $model = null,
    ): TurnRecord;

    /**
     * Resume a paused turn. $decisions is keyed by pending card id; each
     * value is one of ['approve' => true], ['approve' => false] or
     * ['answer' => string] — the driver maps them onto the app's resume
     * contract (approval decisions / AskUser answers).
     *
     * @param  array<string, array{approve?: bool, answer?: string}>  $decisions
     */
    public function decide(
        Authenticatable $actor,
        string $conversationId,
        array $decisions,
        ?string $model = null,
    ): TurnRecord;
}
