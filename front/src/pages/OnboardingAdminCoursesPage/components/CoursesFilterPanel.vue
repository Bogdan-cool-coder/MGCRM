<template>
  <div class="courses-filter-panel row g-3 align-items-end mb-4">
    <div class="col-auto">
      <Select
        v-model="localFilters.status"
        :options="statusOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('onboarding.courses.filter.status')"
        class="courses-filter-panel__select"
        @change="emit('change')"
      />
    </div>
    <div class="col-auto">
      <Select
        v-model="localFilters.completion_policy"
        :options="policyOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('onboarding.courses.filter.policy')"
        class="courses-filter-panel__select"
        @change="emit('change')"
      />
    </div>
    <div class="col">
      <InputText
        v-model="localFilters.search"
        :placeholder="t('onboarding.courses.filter.search')"
        class="w-100"
        @keyup.enter="emit('change')"
      />
    </div>
    <div class="col-auto">
      <Button
        :label="t('onboarding.courses.filter.reset')"
        severity="secondary"
        outlined
        icon="pi pi-filter-slash"
        @click="emit('reset')"
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
  &__select {
    min-width: 160px;
  }
}
</style>
