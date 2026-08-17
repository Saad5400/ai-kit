<?php

namespace Saad\AiKit\Tests\Support;

use Closure;
use Saad\AiKit\Approvals\Contracts\ProposableAction;
use Saad\AiKit\Approvals\Exceptions\ActionValidationException;

/**
 * A configurable {@see ProposableAction} for the approvals feature tests:
 * every behavior is constructor-injected or closure-overridable, and
 * executions are counted so tests can assert exactly-once semantics.
 */
class FakeProposableAction implements ProposableAction
{
    public int $validations = 0;

    public int $executions = 0;

    /** @var list<array<string, mixed>> */
    public array $executedInputs = [];

    public function __construct(
        protected string $type = 'update_widget',
        protected string $category = 'widgets',
        protected bool $destructive = false,
        protected bool $undoable = true,
        protected ?string $typedConfirmPhrase = null,
        protected ?Closure $validateUsing = null,
        protected ?Closure $executeUsing = null,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function destructive(): bool
    {
        return $this->destructive;
    }

    public function undoable(): bool
    {
        return $this->undoable;
    }

    public function typedConfirmPhrase(array $input, mixed $actor): ?string
    {
        return $this->typedConfirmPhrase;
    }

    public function validate(array $input, mixed $actor): array
    {
        $this->validations++;

        if ($this->validateUsing !== null) {
            return ($this->validateUsing)($input, $actor);
        }

        return $input;
    }

    public function summarize(array $normalized, mixed $actor): string
    {
        return 'Summary of '.$this->type;
    }

    public function execute(array $input, mixed $actor): mixed
    {
        $this->executions++;
        $this->executedInputs[] = $input;

        if ($this->executeUsing !== null) {
            return ($this->executeUsing)($input, $actor);
        }

        return ['applied' => true];
    }

    public function validateUsing(?Closure $callback): static
    {
        $this->validateUsing = $callback;

        return $this;
    }

    public function executeUsing(?Closure $callback): static
    {
        $this->executeUsing = $callback;

        return $this;
    }

    public function failValidationWith(string $message): static
    {
        return $this->validateUsing(fn () => throw new ActionValidationException($message));
    }
}
