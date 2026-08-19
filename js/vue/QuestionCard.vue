<script setup lang="ts">
/**
 * An `AskUser` pause, in the Claude-style shape: the question, the model's
 * suggested answers as one-tap chips, and a free-text input as the last
 * option — because a suggestion list that cannot be escaped is a worse
 * question than an open one.
 *
 * `answer` carries the chosen or typed text; resume the turn with it as an
 * edit decision, which is how the answer reaches the tool:
 *
 *     ResumeDecisions::fromClient(
 *         [$id => ['action' => 'edit', 'arguments' => ['answer' => $text]]],
 *         $cards->editGuard($pending),
 *     )
 *
 * `skip` is a reject: the model reads a denial result and continues without
 * the information, saying what it assumed.
 */
import { ref } from 'vue'
import type { QuestionPayload } from '../core/events'

const props = withDefaults(
    defineProps<{
        card: QuestionPayload
        /** Set while a decision is in flight. */
        disabled?: boolean
        placeholder?: string
        sendLabel?: string
        skipLabel?: string
    }>(),
    {
        disabled: false,
        placeholder: 'اكتب إجابتك…',
        sendLabel: 'إرسال',
        skipLabel: 'تخطٍّ',
    },
)

const emit = defineEmits<{
    answer: [answer: string]
    skip: []
}>()

const typed = ref('')

const answer = (value: string): void => {
    const text = value.trim()

    if (text !== '' && !props.disabled) {
        emit('answer', text)
    }
}
</script>

<template>
    <div class="ai-kit-question">
        <p class="ai-kit-question__text" dir="auto">{{ card.question }}</p>

        <div v-if="card.options?.length" class="ai-kit-question__options">
            <button
                v-for="option in card.options"
                :key="option"
                type="button"
                class="ai-kit-question__option"
                dir="auto"
                :disabled="disabled"
                @click="answer(option)"
            >
                {{ option }}
            </button>
        </div>

        <form class="ai-kit-question__form" @submit.prevent="answer(typed)">
            <input
                v-model="typed"
                type="text"
                class="ai-kit-question__input"
                dir="auto"
                :placeholder="placeholder"
                :disabled="disabled"
            />
            <button
                type="submit"
                class="ai-kit-question__send"
                :disabled="disabled || typed.trim() === ''"
            >
                {{ sendLabel }}
            </button>
        </form>

        <button
            type="button"
            class="ai-kit-question__skip"
            :disabled="disabled"
            @click="emit('skip')"
        >
            {{ skipLabel }}
        </button>
    </div>
</template>

<style scoped>
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
