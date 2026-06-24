<template>
  <div class="remarks-tab">
    <!-- Attempt filter -->
    <div class="d-flex align-items-center gap-3 mb-3">
      <label class="remarks-tab__label mb-0">{{ t('documents.remarks.filterAttempt') }}:</label>
      <Select
        v-model="selectedAttempt"
        :options="attemptOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('documents.remarks.filterAttempt')"
        class="remarks-tab__attempt-select"
      />
    </div>

    <!-- Loading -->
    <div v-if="loading">
      <Skeleton height="80px" class="mb-2" v-for="i in 2" :key="i" />
    </div>

    <!-- Remarks list -->
    <div v-else-if="remarks.length > 0" class="d-flex flex-column gap-2">
      <Card
        v-for="remark in remarks"
        :key="remark.id"
        class="remarks-tab__card"
        :class="{ 'remarks-tab__card--resolved': remark.is_resolved }"
      >
        <template #content>
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="flex-1">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="fw-medium">{{ remark.author?.full_name ?? '—' }}</span>
                <span class="text-secondary remarks-tab__meta">
                  {{ t('documents.approval.stage') }} {{ remark.stage_order }}
                  · {{ formatDate(remark.created_at) }}
                </span>
                <Tag
                  v-if="remark.is_resolved"
                  severity="success"
                  icon="pi pi-check"
                  :value="t('documents.remarks.resolved')"
                  class="remarks-tab__status-tag"
                />
                <Tag
                  v-else
                  severity="warn"
                  icon="pi pi-clock"
                  :value="t('documents.remarks.open')"
                  class="remarks-tab__status-tag"
                />
              </div>
              <p class="mb-1 remarks-tab__body">{{ remark.text }}</p>
              <p v-if="remark.is_resolved && remark.resolved_by?.full_name" class="text-secondary mb-0 remarks-tab__meta">
                {{ t('documents.remarks.resolved') }}: {{ remark.resolved_by?.full_name }}
                · {{ remark.resolved_at ? formatDate(remark.resolved_at) : '' }}
              </p>
            </div>
            <Button
              v-if="!remark.is_resolved && canResolve"
              icon="pi pi-check"
              :label="t('documents.remarks.resolve')"
              severity="success"
              text
              size="small"
              :loading="resolvingId === remark.id"
              @click="resolveRemark(remark.id)"
            />
          </div>
        </template>
      </Card>
    </div>

    <div v-else class="remarks-tab__empty">
      <i class="pi pi-comment" />
      <span>{{ t('documents.remarks.empty') }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import type { DocumentRemarkDto } from '@/entities/document'

const props = defineProps<{
  docId: number
  attempt: number
  canResolve: boolean
}>()

const emit = defineEmits<{
  resolved: []
}>()

const { t } = useI18n()
const toast = useToast()

const selectedAttempt = ref<number | null>(null)

const resource = useAsyncResource<DocumentRemarkDto[]>(() => [])
const remarks = computed(() => resource.data.value)
const loading = computed(() => resource.loading.value)

const unresolvedCount = computed(() => remarks.value.filter((r) => !r.is_resolved).length)

defineExpose({ unresolvedCount })

async function fetchRemarks() {
  await resource.run(() =>
    documentsApi.getDocumentRemarks(
      props.docId,
      selectedAttempt.value ?? undefined,
    ),
  )
}

watch([() => props.docId, selectedAttempt], () => void fetchRemarks(), { immediate: true })

const attemptOptions = computed(() => {
  const maxAttempt = props.attempt
  return Array.from({ length: maxAttempt }, (_, i) => ({
    label: `#${i + 1}`,
    value: i + 1,
  }))
})

const resolvingId = ref<number | null>(null)

async function resolveRemark(remarkId: number) {
  resolvingId.value = remarkId
  try {
    const updated = await documentsApi.resolveRemark(props.docId, remarkId)
    const idx = resource.data.value.findIndex((r) => r.id === remarkId)
    if (idx >= 0) {
      resource.data.value[idx] = updated
    }
    emit('resolved')
    toast.add({ severity: 'success', summary: t('documents.remarks.resolved'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    resolvingId.value = null
  }
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}
</script>

<style lang="scss" scoped>
.remarks-tab {
  &__attempt-select {
    width: 100px;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
  }

  &__card {
    border: 1px solid var(--p-surface-200);

    &--resolved {
      opacity: 0.7;
    }

    :deep(.p-card-body) {
      padding: 0.75rem;
    }
  }

  &__meta {
    font-size: $font-size-xs;
  }

  &__body {
    font-size: $font-size-sm;
    color: var(--p-text-color);
  }

  &__status-tag {
    font-size: $font-size-xs;
  }

  &__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 2rem;
    color: var(--p-text-muted-color);
  }
}
</style>
