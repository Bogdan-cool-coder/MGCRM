<template>
  <Card class="mb-3">
    <template #title>Meta</template>
    <template #content>
      <div class="meta-row">
        <span class="meta-row__label">{{ t('onboarding.builder.metaCard.created') }}</span>
        <span>{{ formattedDate }}</span>
      </div>
      <div class="meta-row">
        <span class="meta-row__label">{{ t('onboarding.builder.metaCard.lessonsCount') }}</span>
        <span>{{ course.lessons_count }}</span>
      </div>
      <div class="meta-row">
        <span class="meta-row__label">{{ t('onboarding.builder.metaCard.passingScore') }}</span>
        <span>{{ course.passing_score_pct }}%</span>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import type { Course } from '@/entities/course'

const props = defineProps<{
  course: Course
}>()

const { t, locale } = useI18n()

const formattedDate = computed(() =>
  new Date(props.course.created_at).toLocaleDateString(locale.value, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }),
)
</script>

<style lang="scss" scoped>
.meta-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: $space-1 0;
  font-size: $font-size-sm;
  border-bottom: 1px solid var(--p-surface-100);

  &:last-child {
    border-bottom: none;
  }

  &__label {
    color: var(--p-surface-500);
    font-weight: $font-weight-medium;
  }
}
</style>
