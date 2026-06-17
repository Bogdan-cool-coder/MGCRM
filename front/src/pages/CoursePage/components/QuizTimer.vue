<template>
  <div :class="['quiz-timer', { 'quiz-timer--danger': isDanger }]">
    <i class="pi pi-clock quiz-timer__icon" />
    <span class="quiz-timer__label">{{ t('onboarding.coursePage.quiz.timer') }}: </span>
    <span class="quiz-timer__time">{{ formattedTime }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  secondsLeft: number
}>()

const { t } = useI18n()

const isDanger = computed(() => props.secondsLeft < 120 && props.secondsLeft > 0)

const formattedTime = computed(() => {
  const s = Math.max(0, props.secondsLeft)
  const m = Math.floor(s / 60)
  const sec = s % 60
  return `${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
})
</script>

<style lang="scss" scoped>
.quiz-timer {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 1rem;
  font-weight: 600;
  color: var(--p-text-color);
  padding: 0.375rem 0.75rem;
  border-radius: 6px;
  background: var(--p-surface-100);

  &__icon {
    font-size: 0.875rem;
  }

  &--danger {
    color: var(--p-red-500);
    background: var(--p-red-50);
    animation: quiz-pulse 1s ease-in-out infinite;
  }

  @keyframes quiz-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
  }
}
</style>
