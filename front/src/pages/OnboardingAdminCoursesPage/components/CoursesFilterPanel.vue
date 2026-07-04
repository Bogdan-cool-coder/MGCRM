<template>
  <div class="courses-filter-panel">
    <div class="courses-filter-panel__field">
      <label class="courses-filter-panel__label">{{ t('onboarding.courses.filter.status') }}</label>
      <Select
        v-model="localFilters.status"
        :options="statusOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('onboarding.courses.filter.all')"
        class="courses-filter-panel__control"
        @change="emit('change')"
      />
    </div>
    <div class="courses-filter-panel__field">
      <label class="courses-filter-panel__label">{{ t('onboarding.courses.filter.policy') }}</label>
      <Select
        v-model="localFilters.completion_policy"
        :options="policyOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('onboarding.courses.filter.all')"
        class="courses-filter-panel__control"
        @change="emit('change')"
      />
    </div>
    <div class="courses-filter-panel__field">
      <label class="courses-filter-panel__label">{{ t('onboarding.courses.filter.search') }}</label>
      <InputText
        v-model="localFilters.search"
        :placeholder="t('onboarding.courses.filter.search')"
        class="courses-filter-panel__control"
        @keyup.enter="emit('change')"
      />
    </div>
    <div class="courses-filter-panel__actions">
      <Button
        :label="t('onboarding.courses.filter.reset')"
        severity="secondary"
        outlined
        size="small"
        icon="pi pi-filter-slash"
        @click="emit('reset')"
      />
      <Button
        :label="t('common.apply')"
        size="small"
        icon="pi pi-check"
        @click="emit('change')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import type { CourseListParams } from '@/entities/course'

const props = defineProps<{
  filters: CourseListParams
}>()

const emit = defineEmits<{
  change: []
  reset: []
}>()

const { t } = useI18n()

// Two-way binding proxy
const localFilters = computed(() => props.filters)

const statusOptions = computed(() => [
  { label: t('onboarding.courses.filter.all'), value: '' },
  { label: t('onboarding.courses.statuses.draft'), value: 'draft' },
  { label: t('onboarding.courses.statuses.published'), value: 'published' },
])

const policyOptions = computed(() => [
  { label: t('onboarding.courses.filter.all'), value: '' },
  { label: t('onboarding.courses.policy.informational'), value: 'informational' },
  { label: t('onboarding.courses.policy.soft_gate'), value: 'soft_gate' },
])
</script>

<style lang="scss" scoped>
.courses-filter-panel {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  min-width: 240px;

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    color: var(--p-text-muted-color);
  }

  &__control {
    width: 100%;
  }

  &__actions {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
    margin-top: $space-1;
  }
}
</style>
