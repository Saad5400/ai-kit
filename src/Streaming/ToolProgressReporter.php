<?php

namespace Saad\AiKit\Streaming;

/**
 * A {@see ToolProgress} view pinned to one tool call id, so a tool reports
 * without threading its id through every helper:
 *
 *     $progress = ToolProgress::current()->for($request);
 *
 *     $progress->each($items, function (Item $item) use ($progress): void {
 *         $this->classify($item);
 *         $progress->report(label: $item->name);
 *     }, label: 'classifying');
 *
 * All throttling and cancellation semantics are the parent's; this class
 * only carries the id.
 */
final class ToolProgressReporter
{
    public function __construct(
        private readonly ToolProgress $progress,
        private readonly ?string $toolCallId,
    ) {}

    public function report(?string $label = null, ?float $percent = null, ?int $current = null, ?int $total = null): void
    {
        if ($this->toolCallId !== null) {
            $this->progress->report($this->toolCallId, $label, $percent, $current, $total);
        }
    }

    public function isCancelled(): bool
    {
        return $this->progress->isCancelled();
    }

    /**
     * Iterate with automatic `current/total` reporting (total only when the
     * iterable is countable — a generator's total is reported once it is
     * known, i.e. at the end) and a cancellation check per item.
     *
     * On cancel it simply STOPS ITERATING — no exception, deliberately:
     * laravel/ai's tool executor would only fold a throw into a tool-error
     * string the model then reasons about. The tool returns whatever it
     * accumulated, and the runner's cancel generator ends the turn on the
     * next stream event.
     *
     * @param  callable(mixed, array-key): void  $callback
     */
    public function each(iterable $items, callable $callback, ?string $label = null): void
    {
        $total = is_countable($items) ? count($items) : null;
        $done = 0;

        if ($total !== null) {
            $this->report($label, current: 0, total: $total);
        }

        foreach ($items as $key => $item) {
            if ($this->isCancelled()) {
                return;
            }

            $callback($item, $key);
            $done++;

            $this->report($label, current: $done, total: $total);
        }

        // A drained generator finally has a total; reporting it makes the
        // final state ("7/7") land unthrottled, same as the countable path.
        if ($total === null && $done > 0) {
            $this->report($label, current: $done, total: $done);
        }
    }
}
