<script lang="ts">
    /**
     * An `AskUser` pause, in the Claude-style shape: the question, the model's
     * suggested answers as one-tap chips, and a free-text input as the last
     * option — because a suggestion list that cannot be escaped is a worse
     * question than an open one. Mirror of `js/vue/QuestionCard.vue`.
     *
     * DECIDED STATE. Pass `answer` once the user has answered (or `skipped`) and
     * the card settles into a record of what was said, instead of a bare
     * "answered" label that loses the answer. Keep passing it after a reload: a
     * persisted thread can render its own history this way.
     *
     * `onanswer` carries the chosen or typed text; resume the turn with it as an
     * edit decision, which is how the answer reaches the tool:
     *
     *     ResumeDecisions::fromClient(
     *         [$id => ['action' => 'edit', 'arguments' => ['answer' => $text]]],
     *         $cards->editGuard($pending),
     *     )
     *
     * `onskip` is a reject: the model reads a denial result and continues without
     * the information, saying what it assumed.
     */
    import type { QuestionPayload } from '../core/events'

    let {
        card,
        disabled = false,
        answer = null,
        skipped = false,
        placeholder = 'اكتب إجابتك…',
        sendLabel = 'إرسال',
        skipLabel = 'تخطٍّ',
        answeredLabel = 'إجابتك',
        skippedLabel = 'تم التخطي',
        onanswer,
        onskip,
    }: {
        card: QuestionPayload
        /** Set while a decision is in flight. */
        disabled?: boolean
        /** The answer already given — renders the settled card. */
        answer?: string | null
        /** Whether the question was dismissed instead of answered. */
        skipped?: boolean
        placeholder?: string
        sendLabel?: string
        skipLabel?: string
        answeredLabel?: string
        skippedLabel?: string
        onanswer?: (answer: string) => void
        onskip?: () => void
    } = $props()

    const decided = $derived(skipped || (answer ?? '') !== '')

    let typed = $state('')

    function send(value: string): void {
        const text = value.trim()

        if (text !== '' && !disabled) {
            onanswer?.(text)
        }
    }
</script>

<div class="ai-kit-question" class:is-decided={decided}>
    <p class="ai-kit-question__text" dir="auto">{card.question}</p>

    {#if decided}
        <!-- Settled: what was answered, not merely that something was. -->
        <div class="ai-kit-question__answer">
            <span class="ai-kit-question__answer-label">{skipped ? skippedLabel : answeredLabel}</span>
            {#if !skipped}
                <p class="ai-kit-question__answer-text" dir="auto">{answer}</p>
            {/if}
        </div>
    {:else}
        {#if card.options && card.options.length > 0}
            <div class="ai-kit-question__options">
                {#each card.options as option (option)}
                    <button
                        type="button"
                        class="ai-kit-question__option"
                        dir="auto"
                        {disabled}
                        onclick={() => send(option)}
                    >
                        {option}
                    </button>
                {/each}
            </div>
        {/if}

        <form
            class="ai-kit-question__form"
            onsubmit={(event) => {
                event.preventDefault()
                send(typed)
            }}
        >
            <input
                type="text"
                class="ai-kit-question__input"
                dir="auto"
                {placeholder}
                {disabled}
                bind:value={typed}
            />
            <button type="submit" class="ai-kit-question__send" disabled={disabled || typed.trim() === ''}>
                {sendLabel}
            </button>
        </form>

        <button type="button" class="ai-kit-question__skip" {disabled} onclick={() => onskip?.()}>
            {skipLabel}
        </button>
    {/if}
</div>

<style>
    .ai-kit-question {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.75rem;
        border: 1px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
        border-radius: var(--ai-kit-radius, 0.5rem);
        background: var(--ai-kit-surface, color-mix(in oklab, currentColor 4%, transparent));
    }

    .ai-kit-question.is-decided {
        opacity: 0.85;
    }

    .ai-kit-question__text {
        margin: 0;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        font-weight: 500;
    }

    .ai-kit-question__answer {
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
        padding-inline-start: 0.625rem;
        border-inline-start: 2px solid var(--ai-kit-accent, #3b82f6);
    }

    .ai-kit-question__answer-label {
        font-size: var(--ai-kit-label-size, 0.75rem);
        color: var(--ai-kit-muted, color-mix(in oklab, currentColor 65%, transparent));
    }

    .ai-kit-question__answer-text {
        margin: 0;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .ai-kit-question__options {
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem;
    }

    .ai-kit-question__option {
        padding: 0.25rem 0.625rem;
        border: 1px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
        border-radius: 999px;
        background: transparent;
        color: inherit;
        font: inherit;
        cursor: pointer;
    }

    .ai-kit-question__option:hover:not(:disabled) {
        background: var(--ai-kit-surface, color-mix(in oklab, currentColor 10%, transparent));
    }

    .ai-kit-question__option:disabled,
    .ai-kit-question__send:disabled,
    .ai-kit-question__skip:disabled {
        cursor: default;
        opacity: 0.5;
    }

    .ai-kit-question__form {
        display: flex;
        gap: 0.375rem;
    }

    .ai-kit-question__input {
        flex: 1;
        min-width: 0;
        padding: 0.3125rem 0.5rem;
        border: 1px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
        border-radius: var(--ai-kit-radius, 0.5rem);
        background: var(--ai-kit-input-bg, color-mix(in oklab, currentColor 4%, transparent));
        color: inherit;
        font: inherit;
    }

    .ai-kit-question__send {
        padding: 0.3125rem 0.75rem;
        border: 1px solid transparent;
        border-radius: var(--ai-kit-radius, 0.5rem);
        background: var(--ai-kit-accent, #3b82f6);
        color: var(--ai-kit-accent-fg, #fff);
        font: inherit;
        cursor: pointer;
    }

    .ai-kit-question__skip {
        align-self: flex-start;
        padding: 0;
        border: 0;
        background: none;
        color: var(--ai-kit-muted, color-mix(in oklab, currentColor 65%, transparent));
        font: inherit;
        font-size: var(--ai-kit-label-size, 0.75rem);
        text-decoration: underline;
        cursor: pointer;
    }
</style>
