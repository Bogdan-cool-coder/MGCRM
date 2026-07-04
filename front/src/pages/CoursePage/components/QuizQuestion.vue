<template>
  <div class="quiz-question p-3 mb-3">
    <p class="quiz-question__text">{{ question.text }}</p>

    <!-- Single choice -->
    <div v-if="question.kind === 'single_choice'" class="quiz-question__options d-flex flex-column">
      <div
        v-for="option in question.options"
        :key="option.id"
        class="quiz-question__option d-flex align-items-center"
      >
        <RadioButton
          :modelValue="selectedIds[0] ?? null"
          :value="option.id"
          :inputId="`opt-${option.id}`"
          @update:modelValue="$emit('toggle', question.id, option.id, 'single_choice')"
        />
        <label :for="`opt-${option.id}`" class="quiz-question__option-label">
          {{ option.text }}
        </label>
      </div>
    </div>

    <!-- Multiple choice -->
    <div v-else class="quiz-question__options d-flex flex-column">
      <div
        v-for="option in question.options"
        :key="option.id"
        class="quiz-question__option d-flex align-items-center"
      >
        <Checkbox
          :modelValue="selectedIds.includes(option.id)"
          binary
          :inputId="`opt-${option.id}`"
          @update:modelValue="$emit('toggle', question.id, option.id, 'multiple_choice')"
        />
        <label :for="`opt-${option.id}`" class="quiz-question__option-label">
          {{ option.text }}
        </label>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import RadioButton from 'primevue/radiobutton'
import Checkbox from 'primevue/checkbox'
import type { QuizQuestion as QuizQuestionType } from '@/entities/quiz'

defineProps<{
  question: QuizQuestionType
  selectedIds: number[]
}>()

defineEmits<{
  toggle: [questionId: number, optionId: number, kind: string]
}>()
</script>

<style lang="scss" scoped>
.quiz-question {
  // .card is a dead full-Bootstrap class → card surface scaffold via tokens.
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-lg;

  &__options {
    gap: $space-2;
  }

  &__option {
    gap: $space-2;
  }

  &__text {
    font-weight: $font-weight-semibold;
    margin-bottom: 0.75rem;
    line-height: 1.5;
  }

  &__option-label {
    cursor: pointer;
    font-size: $font-size-sm; // snap from 15px (0.9375rem)
    line-height: 1.4;
  }
}
</style>
