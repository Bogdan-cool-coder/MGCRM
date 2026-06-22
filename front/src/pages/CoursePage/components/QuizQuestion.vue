<template>
  <div class="quiz-question card p-3 mb-3">
    <p class="quiz-question__text">{{ question.text }}</p>

    <!-- Single choice -->
    <div v-if="question.kind === 'single_choice'" class="d-flex flex-column gap-2">
      <div
        v-for="option in question.options"
        :key="option.id"
        class="d-flex align-items-center gap-2"
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
    <div v-else class="d-flex flex-column gap-2">
      <div
        v-for="option in question.options"
        :key="option.id"
        class="d-flex align-items-center gap-2"
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
