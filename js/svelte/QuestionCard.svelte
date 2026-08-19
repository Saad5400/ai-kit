<script lang="ts">
    /**
     * An `AskUser` pause, in the Claude-style shape: the question, the model's
     * suggested answers as one-tap chips, and a free-text input as the last
     * option — because a suggestion list that cannot be escaped is a worse
     * question than an open one. Mirror of `js/vue/QuestionCard.vue`.
     *
     * `onanswer` carries the chosen or typed text; resume the turn with it as
     * an edit decision, which is how the answer reaches the tool:
     *
     *     ResumeDecisions::fromClient(
     *         [$id => ['action' => 'edit', 'arguments' => ['answer' => $text]]],
     *         $cards->editGuard($pending),
     *     )
     *
     * `onskip` is a reject: the model reads a denial result and continues
     * without the information, saying what it assumed.
     */
    import type { QuestionPayload } from '../core/events'

    let {
        card,
        disabled = false,
        placeholder = 'اكتب إجابتك…',
        sendLabel = 'إرسال',
        skipLabel = 'تخطٍّ',
        onanswer,
        onskip,
    }: {
        card: QuestionPayload
        /** Set while a decision is in flight. */
        disabled?: boolean
        placeholder?: string
        sendLabel?: string
        skipLabel?: string
        onanswer?: (answer: string) => void
        onskip?: () => void
    } = $props()

    let typed = $state('')

    function answer(value: string): void {
        const text = value.trim()

        if (text !== '' && !disabled) {
            onanswer?.(text)
        }
    }
</script>

<div class="ai-kit-question">
    <p class="ai-kit-question__text" dir="auto">{card.question}</p>

    {#if card.options && card.options.length > 0}
        <div class="ai-kit-question__options">
            {#each card.options as option (option)}
                <button
                    type="button"
                    class="ai-kit-question__option"
                    dir="auto"
                    {disabled}
                    onclick={() => answer(option)}
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
            answer(typed)
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
</div>

<style>
    .ai-kit-question {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.75rem;
        border: 1px solid var(--ai-kit-border, rgba(107, 114, 128, 0.4));
        border-radius: var(--ai-kit-radius, 0.375rem);
    }

    .ai-kit-question__text {
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
        border: 1px solid var(--ai-kit-border, rgba(107, 114, 128, 0.4));
        border-radius: 999px;
        background: var(--ai-kit-chip-bg, rgba(107, 114, 128, 0.12));
        color: inherit;
        font: inherit;
        cursor: pointer;
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
        padding: 0.25rem 0.5rem;
        border: 1px solid var(--ai-kit-border, rgba(107, 114, 128, 0.4));
        border-radius: var(--ai-kit-radius, 0.375rem);
        background: var(--ai-kit-input-bg, transparent);
        color: inherit;
        font: inherit;
    }

    .ai-kit-question__send {
        padding: 0.25rem 0.75rem;
        border: 1px solid transparent;
        border-radius: var(--ai-kit-radius, 0.375rem);
        background: var(--ai-kit-accent, #2563eb);
        color: var(--ai-kit-accent-fg, #fff);
        font: inherit;
        cursor: pointer;
    }

    .ai-kit-question__skip {
        align-self: flex-start;
        padding: 0;
        border: 0;
        background: none;
        color: var(--ai-kit-muted, #6b7280);
        font: inherit;
        font-size: var(--ai-kit-label-size, 0.75rem);
        text-decoration: underline;
        cursor: pointer;
    }
</style>
