<script setup lang="ts">
/**
 * An `AskUser` pause, in the Claude-style shape: the question, the model's
 * suggested answers as one-tap chips, and a free-text input as the last
 * option — because a suggestion list that cannot be escaped is a worse question
 * than an open one. Mirror of `js/svelte/QuestionCard.svelte`.
 *
 * REDESIGNED IN v0.9.0 from prod screenshots (owner ruling #22 — "very bad and
 * meh and issues with rtl / bidi"). What the old card got wrong, and what
 * replaced it:
 *
 *   - The suggested options read as bare text runs. They are chips now:
 *     bordered, rounded, hover/focus-visible, and the one the user taps stays
 *     marked (`aria-pressed`) while the decision is in flight, so choosing
 *     reads as a choice being made rather than as text vanishing.
 *   - The answer row had no hierarchy — the send button looked like the input's
 *     twin and "skip" like an underlined scrap. Send is the primary control,
 *     skip is a quiet bordered tertiary one on its own row.
 *   - Latin fragments inside Arabic copy scrambled («الشابتر"Web"»). Every text
 *     node that can carry mixed direction is `<bdi dir="auto">` now: the
 *     question, each option, the recorded answer.
 *   - The card sat buried against the thinking disclosure. A pending card
 *     carries an accent rail and its own surface, and — this part is the app's
 *     — must be rendered OUTSIDE the process disclosure; `groupSegments()`
 *     already hands it over as a top-level group.
 *
 * COLORS THROUGH LONGHANDS, never the `border` / `outline` shorthand. A
 * `--ai-kit-*` token mapped to something that is not a color (raw HSL channels,
 * the shadcn-v3 convention: `--border: 240 4% 16%`) makes the whole shorthand
 * invalid at computed-value time — which is how the prod card lost every border
 * and fill at once. Split into `border-color` / `border-width`, the same
 * mapping degrades to `currentColor` and the card still reads.
 *
 * DECIDED STATE. Pass `answer` once the user has answered (or `skipped`) and the
 * card settles into a record of what was said, instead of a bare "answered"
 * label that loses the answer. Keep passing it after a reload: a persisted
 * thread can render its own history this way.
 *
 * `answer` (the event) carries the chosen or typed text; resume the turn with it
 * as an edit decision, which is how the answer reaches the tool:
 *
 *     ResumeDecisions::fromClient(
 *         [$id => ['action' => 'edit', 'arguments' => ['answer' => $text]]],
 *         $cards->editGuard($pending),
 *     )
 *
 * `skip` is a reject: the model reads a denial result and continues without the
 * information, saying what it assumed.
 *
 * Every user-facing string is a prop. The defaults are Arabic because the fleet
 * is Arabic-first and both consumers render Arabic threads; an app in another
 * language passes its own copy.
 */
import { computed, ref } from 'vue'
import type { QuestionPayload } from '../core/events'

const props = withDefaults(
    defineProps<{
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
        /** Badge on an undecided card; pass null to drop it. */
        pendingLabel?: string | null
    }>(),
    {
        disabled: false,
        answer: null,
        skipped: false,
        placeholder: 'اكتب إجابتك…',
        sendLabel: 'إرسال',
        skipLabel: 'تخطٍّ',
        answeredLabel: 'إجابتك',
        skippedLabel: 'تم التخطي',
        pendingLabel: 'بانتظار ردّك',
    },
)

const emit = defineEmits<{
    answer: [answer: string]
    skip: []
}>()

const decided = computed(() => props.skipped || (props.answer ?? '') !== '')

const typed = ref('')
const chosen = ref<string | null>(null)

const send = (value: string, option: string | null = null): void => {
    const text = value.trim()

    if (text !== '' && !props.disabled) {
        chosen.value = option

        emit('answer', text)
    }
}
</script>

<template>
    <section class="ai-kit-question" :class="{ 'is-decided': decided, 'is-pending': !decided }">
        <p v-if="pendingLabel && !decided" class="ai-kit-question__pending">
            <span class="ai-kit-question__dot" aria-hidden="true" />
            <bdi dir="auto">{{ pendingLabel }}</bdi>
        </p>

        <p class="ai-kit-question__text"><bdi dir="auto">{{ card.question }}</bdi></p>

        <!-- Settled: what was answered, not merely that something was. -->
        <div v-if="decided" class="ai-kit-question__answer">
            <span class="ai-kit-question__answer-label">
                <bdi dir="auto">{{ skipped ? skippedLabel : answeredLabel }}</bdi>
            </span>
            <p v-if="!skipped" class="ai-kit-question__answer-text">
                <bdi dir="auto">{{ answer }}</bdi>
            </p>
        </div>

        <template v-else>
            <div v-if="card.options?.length" class="ai-kit-question__options">
                <button
                    v-for="option in card.options"
                    :key="option"
                    type="button"
                    class="ai-kit-question__option"
                    :class="{ 'is-chosen': chosen === option }"
                    :aria-pressed="chosen === option"
                    :disabled="disabled"
                    @click="send(option, option)"
                >
                    <bdi dir="auto">{{ option }}</bdi>
                </button>
            </div>

            <form class="ai-kit-question__form" @submit.prevent="send(typed)">
                <input
                    v-model="typed"
                    type="text"
                    class="ai-kit-question__input"
                    dir="auto"
                    :placeholder="placeholder"
                    :disabled="disabled"
                />
                <button type="submit" class="ai-kit-question__send" :disabled="disabled || typed.trim() === ''">
                    <bdi dir="auto">{{ sendLabel }}</bdi>
                </button>
            </form>

            <div class="ai-kit-question__footer">
                <button type="button" class="ai-kit-question__skip" :disabled="disabled" @click="emit('skip')">
                    <bdi dir="auto">{{ skipLabel }}</bdi>
                </button>
            </div>
        </template>
    </section>
</template>

<style scoped>
.ai-kit-question {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 0.875rem;
    border-width: 1px;
    border-style: solid;
    border-color: var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
    border-radius: var(--ai-kit-radius, 0.5rem);
    background-color: var(--ai-kit-surface, transparent);
    /*
     * A static tint ON TOP of the mapped surface, so the card separates from
     * whatever it sits on even when an app maps `--ai-kit-surface` to the page
     * background (uqucc does) or to something invalid (catodemy did).
     * currentColor-derived, so it works on dark and light alike.
     */
    background-image: linear-gradient(
        color-mix(in oklab, currentColor 5%, transparent),
        color-mix(in oklab, currentColor 5%, transparent)
    );
    text-align: start;
}

/* Unmissable: the accent rail on the inline-start edge, logical so Arabic
   needs no mirrored rule. */
.ai-kit-question.is-pending {
    border-inline-start-width: 3px;
    border-inline-start-color: var(--ai-kit-accent, #3b82f6);
}

.ai-kit-question.is-decided {
    opacity: 0.85;
}

.ai-kit-question__pending {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    margin: 0;
    font-size: var(--ai-kit-label-size, 0.75rem);
    font-weight: 500;
    color: var(--ai-kit-accent, inherit);
}

.ai-kit-question__dot {
    inline-size: 0.375rem;
    block-size: 0.375rem;
    border-radius: 999px;
    background-color: currentColor;
}

.ai-kit-question__text {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-weight: 600;
    line-height: 1.6;
}

.ai-kit-question__answer {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    padding-inline-start: 0.625rem;
    border-inline-start-width: 2px;
    border-inline-start-style: solid;
    border-inline-start-color: var(--ai-kit-accent, #3b82f6);
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
    padding: 0.375rem 0.75rem;
    border-width: 1px;
    border-style: solid;
    /* Not `--ai-kit-border`: an app that maps that token to a hairline divider
       color leaves a tappable chip looking like plain text, which is exactly
       what prod showed. Chips carry their own token. */
    border-color: var(--ai-kit-control-border, color-mix(in oklab, currentColor 30%, transparent));
    border-radius: 999px;
    background-color: transparent;
    color: inherit;
    font: inherit;
    font-size: var(--ai-kit-chip-size, 0.875rem);
    line-height: 1.4;
    cursor: pointer;
    transition: background-color 0.12s ease, border-color 0.12s ease;
}

.ai-kit-question__option:hover:not(:disabled) {
    border-color: var(--ai-kit-accent, #3b82f6);
    background-color: var(--ai-kit-hover, color-mix(in oklab, currentColor 10%, transparent));
}

.ai-kit-question__option.is-chosen {
    border-color: var(--ai-kit-accent, #3b82f6);
    background-color: color-mix(in oklab, var(--ai-kit-accent, #3b82f6) 18%, transparent);
    font-weight: 600;
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
    padding: 0.4375rem 0.625rem;
    border-width: 1px;
    border-style: solid;
    border-color: var(--ai-kit-control-border, color-mix(in oklab, currentColor 30%, transparent));
    border-radius: var(--ai-kit-radius, 0.5rem);
    background-color: var(--ai-kit-input-bg, color-mix(in oklab, currentColor 6%, transparent));
    color: inherit;
    font: inherit;
    text-align: start;
}

.ai-kit-question__send {
    padding: 0.4375rem 1rem;
    border-width: 1px;
    border-style: solid;
    /* The border does the work when a broken token collapses the fill — a
       primary button that paints nothing still reads as a button. */
    border-color: var(--ai-kit-accent, #3b82f6);
    border-radius: var(--ai-kit-radius, 0.5rem);
    background-color: var(--ai-kit-accent, #3b82f6);
    color: var(--ai-kit-accent-fg, #fff);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.ai-kit-question__footer {
    display: flex;
}

.ai-kit-question__skip {
    padding: 0.25rem 0.625rem;
    border-width: 1px;
    border-style: solid;
    border-color: transparent;
    border-radius: var(--ai-kit-radius, 0.5rem);
    background-color: transparent;
    color: var(--ai-kit-muted, color-mix(in oklab, currentColor 65%, transparent));
    font: inherit;
    font-size: var(--ai-kit-label-size, 0.8125rem);
    cursor: pointer;
}

.ai-kit-question__skip:hover:not(:disabled) {
    border-color: var(--ai-kit-control-border, color-mix(in oklab, currentColor 30%, transparent));
    background-color: var(--ai-kit-hover, color-mix(in oklab, currentColor 8%, transparent));
}

.ai-kit-question__option:focus-visible,
.ai-kit-question__send:focus-visible,
.ai-kit-question__skip:focus-visible,
.ai-kit-question__input:focus-visible {
    outline-width: 2px;
    outline-style: solid;
    outline-color: var(--ai-kit-accent, #3b82f6);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .ai-kit-question__option {
        transition: none;
    }
}
</style>
