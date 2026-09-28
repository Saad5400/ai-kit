<?php

namespace Saad\AiKit\Tests\Support;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A remembering agent with one auto-run WRITE tool (SaveNote) — the
 * smallest agent whose failed turn has something at stake: a tool that
 * already changed the world before the turn died.
 */
class RememberingNoteAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /** @var list<string> every note the tool saved, in order */
    public static array $saved = [];

    /** Runs inside the tool, after it saved — a test's hook to act mid-turn. */
    public static ?Closure $afterSave = null;

    public function instructions(): string
    {
        return 'You keep notes.';
    }

    public function tools(): iterable
    {
        return [new class implements Tool
        {
            public function name(): string
            {
                return 'SaveNote';
            }

            public function description(): Stringable|string
            {
                return 'Saves a note.';
            }

            public function handle(Request $request): Stringable|string
            {
                RememberingNoteAgent::$saved[] = $request->string('text')->value();

                if (RememberingNoteAgent::$afterSave !== null) {
                    (RememberingNoteAgent::$afterSave)();
                }

                return 'saved';
            }

            public function schema(JsonSchema $schema): array
            {
                return ['text' => $schema->string()->required()];
            }
        }];
    }
}
