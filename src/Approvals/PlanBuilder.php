<?php

namespace Saad\AiKit\Approvals;

use Closure;
use Illuminate\Support\Str;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Exceptions\UnknownActionException;

/**
 * Builds a {@see Plan} from steps whose risk flags are derived from the
 * REGISTERED actions — `destructive`, `undoable` and `typed_confirm` come
 * from the {@see Contracts\ProposableAction}, never from the caller (the
 * model's own flags are advisory at best).
 *
 * `auto_approve` classifies the finished plan: by default a plan skips the
 * approval card only when it is a single non-destructive, undoable step with
 * no retype gate — and only while `ai-kit.approvals.auto_approve` is on.
 * Apps with their own policy swap the predicate via
 * {@see self::deriveAutoApproveUsing()}.
 */
class PlanBuilder
{
    /** @var (Closure(list<ProposedWrite>, array<string, int|string>): bool)|null */
    protected static ?Closure $autoApproveResolver = null;

    protected string $summary = '';

    /** @var list<ProposedWrite> */
    protected array $steps = [];

    /** @var array<string, int|string> */
    protected array $scope = [];

    public function __construct(protected readonly ActionRegistry $registry) {}

    /**
     * Replace (or, with null, restore) the auto-approve predicate. The
     * closure receives the built steps and scope, and runs only when the
     * `ai-kit.approvals.auto_approve` toggle is on.
     *
     * @param  (Closure(list<ProposedWrite>, array<string, int|string>): bool)|null  $resolver
     */
    public static function deriveAutoApproveUsing(?Closure $resolver): void
    {
        static::$autoApproveResolver = $resolver;
    }

    public function summary(string $summary): static
    {
        $this->summary = trim($summary);

        return $this;
    }

    /**
     * @param  array<string, int|string>  $scope
     */
    public function scope(array $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    /**
     * Add a step, deriving its risk flags from the registered action.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $preview
     *
     * @throws UnknownActionException when no action is registered for $type
     */
    public function step(
        string $type,
        string $title,
        array $input,
        array $preview = [],
        bool $createsRecord = false,
        mixed $actor = null,
    ): static {
        $this->steps[] = ProposedWrite::derive($this->registry, $type, $title, $input, $preview, $createsRecord, $actor);

        return $this;
    }

    /**
     * Add an already-derived write (the {@see ProposalBag} path — its writes
     * were built through the registry too, so derivation is preserved).
     */
    public function add(ProposedWrite $write): static
    {
        $this->steps[] = $write;

        return $this;
    }

    public function build(?string $id = null): Plan
    {
        $summary = $this->summary !== ''
            ? $this->summary
            : $this->defaultSummary();

        return new Plan(
            id: $id ?? (string) Str::uuid(),
            summary: $summary,
            steps: $this->steps,
            scope: $this->scope,
            autoApprove: $this->autoApprove(),
        );
    }

    protected function defaultSummary(): string
    {
        return match (count($this->steps)) {
            0 => '',
            1 => $this->steps[0]->title,
            default => count($this->steps).' changes to apply',
        };
    }

    protected function autoApprove(): bool
    {
        if (config('ai-kit.approvals.auto_approve', true) !== true) {
            return false;
        }

        if (static::$autoApproveResolver !== null) {
            return (static::$autoApproveResolver)($this->steps, $this->scope);
        }

        return count($this->steps) === 1
            && ! $this->steps[0]->destructive
            && $this->steps[0]->undoable
            && $this->steps[0]->typedConfirm === null;
    }
}
