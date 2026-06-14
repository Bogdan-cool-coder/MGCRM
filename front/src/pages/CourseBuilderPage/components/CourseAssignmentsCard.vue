<template>
  <Card class="mb-3">
    <template #title>{{ t('onboarding.builder.assignmentsCard.title') }}</template>
    <template #content>
      <template v-if="loading">
        <Skeleton height="32px" class="mb-2" />
        <Skeleton height="32px" class="mb-2" />
        <Skeleton height="32px" />
      </template>
      <template v-else>
        <p class="assignments-card__count">
          {{ t('onboarding.builder.assignmentsCard.count', { n: assignments.length }) }}
        </p>

        <div v-if="assignments.length === 0" class="assignments-card__empty">
          <span class="text-muted">{{ t('onboarding.builder.assignmentsCard.empty') }}</span>
        </div>

        <div v-else class="assignments-card__list">
          <div
            v-for="a in displayedAssignments"
            :key="a.id"
            class="assignment-row"
          >
            <span class="assignment-row__name">{{ a.user?.full_name }}</span>
            <AssignmentStatusTag :status="a.status" />
            <ProgressBar :value="a.progress_pct" style="height: 4px; flex: 1;" />
            <span class="assignment-row__pct">{{ a.progress_pct }}%</span>
          </div>
          <div v-if="assignments.length > 3" class="assignments-card__more">
            <span class="text-muted">... ещё {{ assignments.length - 3 }}</span>
          </div>
        </div>

        <Divider />
        <Button
          :label="t('onboarding.builder.assignmentsCard.assign')"
          icon="pi pi-plus"
          text
          severity="secondary"
          class="w-100"
          @click="emit('assign')"
        />
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import ProgressBar from 'primevue/progressbar'
import Divider from 'primevue/divider'
import AssignmentStatusTag from '@/components/shared/AssignmentStatusTag.vue'
import type { CourseAssignment } from '@/entities/assignment'

const props = defineProps<{
  assignments: CourseAssignment[]
  loading: boolean
}>()

const emit = defineEmits<{
  assign: []
}>()

const { t } = useI18n()

const displayedAssignments = computed(() => props.assignments.slice(0, 3))
</script>

<style lang="scss" scoped>
.assignments-card {
  &__count {
    font-size: $font-size-sm;
    color: var(--p-surface-600);
    margin-bottom: $space-2;
  }

  &__empty {
    padding: $space-2 0;
    font-size: $font-size-sm;
  }

  &__list {
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }

  &__more {
    font-size: $font-size-xs;
    padding-top: $space-1;
  }
}

.assignment-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-sm;

  &__name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__pct {
    font-size: $font-size-xs;
    color: var(--p-surface-500);
    white-space: nowrap;
  }
}
</style>
