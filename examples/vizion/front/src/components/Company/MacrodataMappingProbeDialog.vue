<template>
  <Dialog
    :visible="visible"
    @update:visible="(value) => emit('update:visible', value)"
    modal
    :header="t('macrodataMapping.probeDialog.title')"
    :breakpoints="{ '1199px': '90vw', '575px': '95vw' }"
    :style="{ width: '880px' }"
    :closable="!applying"
  >
    <p class="probe-subtitle">{{ t('macrodataMapping.probeDialog.subtitle') }}</p>

    <div v-if="unresolved.length" class="unresolved-block">
      <div class="unresolved-header">
        <i class="pi pi-exclamation-circle" />
        <span class="unresolved-title">{{
          t('macrodataMapping.probeDialog.unresolvedTitle')
        }}</span>
      </div>
      <ul class="unresolved-list">
        <li v-for="key in unresolved" :key="key">
          <code>{{ key }}</code>
        </li>
      </ul>
      <p class="unresolved-hint">{{ t('macrodataMapping.probeDialog.unresolvedHint') }}</p>
    </div>

    <div v-if="!rows.length" class="probe-empty">
      {{ t('macrodataMapping.probeDialog.noResults') }}
    </div>

    <DataTable
      v-else
      :value="rows"
      :row-class="rowClass"
      v-model:expanded-rows="expandedRows"
      data-key="semantic_key"
      class="probe-table"
    >
      <Column expander style="width: 3rem" />
      <Column style="width: 3rem">
        <template #header>
          <Checkbox
            v-model="selectAllProxy"
            binary
            :disabled="applying || !selectableKeys.length"
          />
        </template>
        <template #body="{ data }">
          <Checkbox
            :model-value="selected[data.semantic_key]"
            binary
            :disabled="applying"
            @update:model-value="(v) => onRowToggle(data.semantic_key, v)"
          />
        </template>
      </Column>
      <Column
        field="semantic_key"
        :header="t('macrodataMapping.probeDialog.columnSemanticKey')"
      >
        <template #body="{ data }">
          <code class="semantic-key">{{ data.semantic_key }}</code>
        </template>
      </Column>
      <Column :header="t('macrodataMapping.probeDialog.columnNewValue')">
        <template #body="{ data }">
          <span class="value-cell">{{ formatValue(data.value) }}</span>
        </template>
      </Column>
      <Column :header="t('macrodataMapping.probeDialog.columnCurrentValue')">
        <template #body="{ data }">
          <span v-if="data.current_value === undefined" class="value-muted">—</span>
          <span v-else class="value-cell">{{ formatValue(data.current_value) }}</span>
        </template>
      </Column>
      <Column :header="t('macrodataMapping.probeDialog.columnDiff')">
        <template #body="{ data }">
          <Tag
            :value="diffLabel(data.diff)"
            :severity="diffSeverity(data.diff)"
            rounded
          />
        </template>
      </Column>
      <Column :header="t('macrodataMapping.probeDialog.columnMatchedBy')">
        <template #body="{ data }">
          <span class="matched-by">{{ data.matched_by || '—' }}</span>
        </template>
      </Column>

      <template #expansion="{ data }">
        <div class="candidates-block">
          <div class="candidates-title">
            {{ t('macrodataMapping.probeDialog.columnCandidates') }}
          </div>
          <ul v-if="data.candidates.length" class="candidates-list">
            <li v-for="cand in data.candidates" :key="cand.id">
              <code>{{ cand.id }}</code>
              <span class="candidate-name">{{ cand.name }}</span>
            </li>
          </ul>
          <div v-else class="candidates-empty">
            {{ t('macrodataMapping.probeDialog.candidatesNone') }}
          </div>
        </div>
      </template>
    </DataTable>

    <template #footer>
      <div class="probe-footer">
        <span class="selection-summary">{{ selectionSummary }}</span>
        <div class="probe-footer-buttons">
          <Button
            :label="t('macrodataMapping.probeDialog.cancelButton')"
            severity="secondary"
            :disabled="applying"
            @click="onCancel"
          />
          <Button
            :label="t('macrodataMapping.probeDialog.applyButton')"
            severity="success"
            :loading="applying"
            :disabled="!selectedKeys.length"
            @click="onApply"
          />
        </div>
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import type {
  MacrodataMappingDto,
  MacrodataProbeMappingDto,
  MacrodataProbeResultDto,
} from '@/api/types/macrodataMappings'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  visible: boolean
  result: MacrodataProbeResultDto | null
  currentMappings: MacrodataMappingDto[]
  applying: boolean
}
const props = defineProps<Props>()

interface Emits {
  (e: 'update:visible', value: boolean): void
  (e: 'apply', selectedKeys: string[]): void
  (e: 'cancel'): void
}
const emit = defineEmits<Emits>()

type Diff = 'new' | 'changed' | 'unchanged'

interface ProbeRow extends MacrodataProbeMappingDto {
  current_value: unknown | undefined
  diff: Diff
}

const valueSignature = (value: unknown): string => {
  if (value === undefined) return '__undefined__'
  if (value === null) return 'null'
  if (Array.isArray(value)) {
    // Sort numeric/string IDs so [1,2] and [2,1] are treated as the same set
    // — order shouldn't matter for ID-list mappings.
    const items = [...value].map((v) => (typeof v === 'object' ? JSON.stringify(v) : String(v)))
    items.sort()
    return `[${items.join(',')}]`
  }
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

const currentByKey = computed<Record<string, unknown>>(() => {
  const map: Record<string, unknown> = {}
  for (const m of props.currentMappings) {
    map[m.semantic_key] = m.value
  }
  return map
})

const rows = computed<ProbeRow[]>(() => {
  if (!props.result) return []
  return props.result.mappings.map((m) => {
    const current = currentByKey.value[m.semantic_key]
    let diff: Diff
    if (current === undefined) {
      diff = 'new'
    } else if (valueSignature(current) === valueSignature(m.value)) {
      diff = 'unchanged'
    } else {
      diff = 'changed'
    }
    return {
      ...m,
      current_value: current,
      diff,
    }
  })
})

const unresolved = computed<string[]>(() => props.result?.unresolved ?? [])

// `selectableKeys` excludes 'unchanged' rows by default — applying them is a
// no-op but pre-checking them clutters the summary count. User can still
// flip them on manually.
const selectableKeys = computed<string[]>(() =>
  rows.value.filter((r) => r.diff !== 'unchanged').map((r) => r.semantic_key),
)

const selected = ref<Record<string, boolean>>({})

// Seed selection whenever the result changes (new probe run / dialog re-open):
// new + changed rows pre-checked, unchanged left off.
watch(
  () => props.result,
  () => {
    const next: Record<string, boolean> = {}
    for (const row of rows.value) {
      next[row.semantic_key] = row.diff !== 'unchanged'
    }
    selected.value = next
  },
  { immediate: true },
)

const expandedRows = ref<Record<string, boolean>>({})

const onRowToggle = (key: string, value: boolean) => {
  selected.value = { ...selected.value, [key]: value }
}

const selectAllProxy = computed<boolean>({
  get() {
    if (!selectableKeys.value.length) return false
    return selectableKeys.value.every((k) => selected.value[k])
  },
  set(value: boolean) {
    const next: Record<string, boolean> = { ...selected.value }
    for (const key of selectableKeys.value) {
      next[key] = value
    }
    selected.value = next
  },
})

const selectedKeys = computed<string[]>(() =>
  Object.entries(selected.value)
    .filter(([, v]) => v)
    .map(([k]) => k),
)

const selectionSummary = computed(() => {
  const count = selectedKeys.value.length
  // Russian plural form selection — vue-i18n's named-pluralisation is more
  // complex than the inline fallback we need here; using a small manual
  // branch keeps the localisation explicit and predictable.
  if (count === 0) return t('macrodataMapping.probeDialog.selectionSummaryZero')
  const mod10 = count % 10
  const mod100 = count % 100
  if (mod10 === 1 && mod100 !== 11) {
    return t('macrodataMapping.probeDialog.selectionSummaryOne', { count })
  }
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) {
    return t('macrodataMapping.probeDialog.selectionSummaryFew', { count })
  }
  return t('macrodataMapping.probeDialog.selectionSummaryMany', { count })
})

const diffLabel = (diff: Diff): string => {
  switch (diff) {
    case 'new':
      return t('macrodataMapping.probeDialog.diffNew')
    case 'changed':
      return t('macrodataMapping.probeDialog.diffChanged')
    default:
      return t('macrodataMapping.probeDialog.diffUnchanged')
  }
}

const diffSeverity = (diff: Diff): 'success' | 'warn' | 'secondary' => {
  switch (diff) {
    case 'new':
      return 'success'
    case 'changed':
      return 'warn'
    default:
      return 'secondary'
  }
}

const rowClass = (data: ProbeRow): string => `diff-${data.diff}`

const formatValue = (value: unknown): string => {
  if (value === null || value === undefined) return '—'
  if (Array.isArray(value)) return value.join(', ')
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

const onApply = () => {
  emit('apply', selectedKeys.value)
}

const onCancel = () => {
  emit('cancel')
  emit('update:visible', false)
}
</script>

<style lang="scss" scoped>
.probe-subtitle {
  margin: 0 0 1rem;
  color: $surface-600;
  font-size: $font-size-sm;
}

.unresolved-block {
  background: $surface-50;
  border: 1px solid $surface-200;
  border-left: 3px solid $orange-500;
  border-radius: $border-radius;
  padding: 0.75rem 1rem;
  margin-bottom: 1rem;

  .unresolved-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: $surface-800;
    font-size: $font-size-sm;
    margin-bottom: 0.5rem;

    .pi {
      color: $orange-500;
    }

    .unresolved-title {
      font-weight: $font-weight-semibold;
    }
  }

  .unresolved-list {
    list-style: none;
    margin: 0 0 0.5rem;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;

    li code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
      font-size: $font-size-xs;
      background: $surface-100;
      color: $surface-900;
      padding: 0.125rem 0.375rem;
      border-radius: $border-radius;
    }
  }

  .unresolved-hint {
    margin: 0;
    font-size: $font-size-xs;
    color: $surface-600;
  }
}

.probe-empty {
  padding: 1.5rem 1rem;
  text-align: center;
  color: $surface-600;
  font-size: $font-size-sm;
}

.probe-table {
  .semantic-key {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
    font-size: $font-size-sm;
    color: $surface-900;
    background: $surface-100;
    padding: 0.125rem 0.375rem;
    border-radius: $border-radius;
  }

  .value-cell {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
    font-size: $font-size-sm;
    color: $surface-800;
  }

  .value-muted {
    color: $surface-400;
  }

  .matched-by {
    font-size: $font-size-xs;
    color: $surface-600;
  }

  :deep(.diff-unchanged) {
    opacity: 0.7;
  }
}

.candidates-block {
  background: $surface-50;
  padding: 0.75rem 1rem;
  border-radius: $border-radius;

  .candidates-title {
    font-size: $font-size-xs;
    text-transform: uppercase;
    color: $surface-600;
    font-weight: $font-weight-semibold;
    margin-bottom: 0.5rem;
  }

  .candidates-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;

    li {
      display: flex;
      align-items: baseline;
      gap: 0.5rem;
      font-size: $font-size-sm;

      code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
        background: $surface-100;
        color: $surface-900;
        padding: 0.125rem 0.375rem;
        border-radius: $border-radius;
      }

      .candidate-name {
        color: $surface-700;
      }
    }
  }

  .candidates-empty {
    font-size: $font-size-sm;
    color: $surface-500;
  }
}

.probe-footer {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;

  .selection-summary {
    font-size: $font-size-sm;
    color: $surface-700;
  }

  .probe-footer-buttons {
    display: flex;
    gap: 0.5rem;
  }
}
</style>
