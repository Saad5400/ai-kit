<?php

namespace Saad\AiKit\Approvals\Classified;

use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Saad\AiKit\Approvals\Contracts\Classified;
use Saad\AiKit\Streaming\StreamEventMapper;

/**
 * Renders a paused turn's `pendingApprovals` into the canonical client
 * cards, with every trust-bearing field resolved SERVER-side from the
 * agent's own tool instances — the model contributes only the arguments,
 * which are exactly what a resume will execute (preview == execution).
 *
 * Card shapes (owner decision #4):
 * - `question {id, question}` — an {@see AskUser} pause; answered, not
 *   approved.
 * - `approval {id, tool, title, destructive, undoable, editable,
 *   arguments, preview, reason}` — destructive renders as a one-click
 *   card (`editable: false`); payload writes render as an editable form
 *   prefilled from `arguments` and resubmitted as an edit decision through
 *   the same schema-validated tool path.
 *
 * Streaming: {@see attachTo()} hooks the mapper's ToolApprovalRequest and
 * emits one wire event per card. Non-streaming: {@see cards()} builds the
 * same payloads from a response's `pendingApprovals`.
 */
class ApprovalCards
{
    /** @var list<Tool> */
    protected array $tools;

    /**
     * @param  iterable<Tool>  $tools  the agent's tools() — the classification source
     */
    public function __construct(iterable $tools)
    {
        $this->tools = collect($tools)->values()->all();
    }

    /**
     * @param  iterable<PendingApproval>  $pendingApprovals
     * @return list<array<string, mixed>>
     */
    public function cards(iterable $pendingApprovals): array
    {
        return collect($pendingApprovals)
            ->map(fn (PendingApproval $approval): array => $this->card($approval))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function card(PendingApproval $approval): array
    {
        $tool = $this->findTool($approval->tool);

        if ($tool instanceof AskUser) {
            return [
                'kind' => 'question',
                'id' => $approval->id,
                'question' => (string) ($approval->arguments['question'] ?? $approval->reason ?? ''),
            ];
        }

        $capability = $tool instanceof Classified ? $tool->capability() : null;
        $destructive = $capability?->effect === Effect::Destructive;
        $request = new Request($approval->arguments, $approval->id);

        return [
            'kind' => 'approval',
            'id' => $approval->id,
            'tool' => $approval->tool,
            'title' => $tool instanceof ClassifiedTool
                ? $tool->title($request)
                : Str::headline($approval->tool),
            // Unclassified Approvable tools fail safe: treated as
            // destructive (one-click, no auto-anything) until classified.
            'destructive' => $capability === null || $destructive,
            'undoable' => $capability?->undoable ?? false,
            'editable' => $capability !== null && ! $destructive,
            'arguments' => $approval->arguments,
            'preview' => $tool instanceof ClassifiedTool ? $tool->preview($request) : [],
            'reason' => $approval->reason,
        ];
    }

    /**
     * Hook the mapper: each pause emits one `question` or `approval` wire
     * event per card, then the turn's `done` follows as usual.
     */
    public function attachTo(StreamEventMapper $mapper): StreamEventMapper
    {
        return $mapper->on(
            ToolApprovalRequest::class,
            function (ToolApprovalRequest $event, callable $emit): void {
                foreach ($event->pendingApprovals as $approval) {
                    $card = $this->card($approval);

                    $emit($card['kind'] === 'question' ? 'question' : 'approval', $card);
                }
            },
        );
    }

    protected function findTool(string $name): ?Tool
    {
        foreach ($this->tools as $tool) {
            if (ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }
}
