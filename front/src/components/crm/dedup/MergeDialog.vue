<template>
  <Dialog
    v-model:visible="visible"
    :header="dialogTitle"
    modal
    style="width: 700px; max-width: 95vw"
    @hide="onHide"
  >
    <!-- Step 1: Scan -->
    <div v-if="step === 'scan'" class="dedup-dialog__step">
      <div class="dedup-dialog__scan-controls">
        <SelectButton
          v-model="scope"
          :options="scopeOptions"
          option-label="label"
          option-value="value"
        />
        <Button
          icon="pi pi-search"
          :label="t('dedup.dialog.scan.button')"
          :loading="scanning"
          @click="scan"
        />
      </div>

      <div v-if="scanning" class="dedup-dialog__scanning">
        <ProgressSpinner style="width: 48px; height: 48px" />
        <p>{{ t('dedup.dialog.scan.scanning') }}</p>
      </div>
    </div>

    <!-- Step 2: Groups list -->
    <div v-else-if="step === 'candidates'" class="dedup-dialog__step">
      <div v-if="groups.length === 0" class="dedup-dialog__empty">
        <i class="pi pi-check-circle dedup-dialog__empty-icon dedup-dialog__empty-icon--success" />
        <p class="dedup-dialog__empty-title">{{ t('dedup.dialog.scan.empty') }}</p>
        <p class="dedup-dialog__empty-subtitle">{{ t('dedup.dialog.scan.emptySubtitle') }}</p>
      </div>

      <div v-else class="dedup-dialog__groups">
        <div
          v-for="(group, idx) in groups"
          :key="group.key"
          class="dedup-dialog__group"
        >
          <div class="dedup-dialog__group-header">
            <span class="dedup-dialog__group-title">
              {{
                t('dedup.dialog.candidates.group', {
                  n: idx + 1,
                  name: getCandidateName(group.entities[0]),
                  count: group.entities.length,
                })
              }}
            </span>
            <div class="dedup-dialog__group-actions">
              <Button
                icon="pi pi-objects-column"
                :label="t('dedup.dialog.candidates.merge')"
                severity="warning"
                size="small"
                @click="selectGroup(group)"
              />
              <Button
                icon="pi pi-times"
                :label="t('dedup.dialog.candidates.notDuplicate')"
                severity="secondary"
                text
                size="small"
                :loading="isDismissing"
                :disabled="group.entities.length < 2"
                @click="dismissGroup(group)"
              />
            </div>
          </div>
          <DataTable :value="group.entities" size="small" class="dedup-dialog__group-table">
            <Column field="id" :header="t('dedup.dialog.candidates.columns.id')" style="width: 80px" />
            <Column :header="t('dedup.dialog.candidates.columns.name')">
              <template #body="{ data }">
                {{ getCandidateName(data) }}
              </template>
            </Column>
            <Column :header="t('dedup.dialog.candidates.columns.key')">
              <template #body="{ data }">
                {{ data.tax_id || data.email || '—' }}
              </template>
            </Column>
            <Column :header="t('dedup.dialog.candidates.columns.createdAt')">
              <template #body="{ data }">
                {{ formatDate(data.created_at) }}
              </template>
            </Column>
          </DataTable>
        </div>
      </div>
    </div>

    <!-- Step 3: Merge — select master record -->
    <div v-else-if="step === 'merge' && selectedGroup" class="dedup-dialog__step">
      <Message severity="info" class="dedup-dialog__info">
        {{ t('dedup.dialog.merge.wholeRecordNote') }}
      </Message>

      <p class="dedup-dialog__section-label">{{ t('dedup.dialog.merge.masterLabel') }}</p>
      <div class="dedup-dialog__master-options">
        <div
          v-for="entity in selectedGroup.entities"
          :key="entity.id"
          class="dedup-dialog__master-option"
        >
          <RadioButton
            v-model="masterId"
            :value="entity.id"
            :input-id="`master-${entity.id}`"
          />
          <label :for="`master-${entity.id}`" class="dedup-dialog__master-label">
            <span class="dedup-dialog__master-id">ID {{ entity.id }}</span>
            {{ getCandidateName(entity) }}
            <span v-if="entity.email" class="dedup-dialog__master-meta">{{ entity.email }}</span>
            <span v-if="entity.phone" class="dedup-dialog__master-meta">{{ entity.phone }}</span>
          </label>
        </div>
      </div>

      <p class="dedup-dialog__section-label">{{ t('dedup.dialog.merge.fieldsLabel') }}</p>
      <DataTable
        :value="mergePreviewRows"
        size="small"
        class="dedup-dialog__fields-table"
      >
        <Column :header="t('dedup.dialog.merge.columns.field')" style="width: 140px">
          <template #body="{ data }">
            <span class="dedup-dialog__field-key">{{ data.label }}</span>
          </template>
        </Column>
        <Column
          v-for="entity in selectedGroup.entities"
          :key="entity.id"
          :header="`ID ${entity.id}${entity.id === masterId ? ' ✓' : ''}`"
        >
          <template #body="{ data }">
            <span :class="{ 'dedup-dialog__field-master': entity.id === masterId }">
              {{ getCandidateFieldValue(entity, data.key) }}
            </span>
          </template>
        </Column>
      </DataTable>

      <Message v-if="mergeWarning" severity="warn" class="dedup-dialog__warning">
        {{ mergeWarning }}
      </Message>

      <Message v-if="mergeError" severity="error" class="dedup-dialog__error">
        {{ mergeError }}
      </Message>
    </div>

    <!-- Footer -->
    <template #footer>
      <div class="dedup-dialog__footer">
        <Button
          v-if="step !== 'scan'"
          :label="step === 'merge' ? t('dedup.dialog.merge.back') : t('common.close')"
          severity="secondary"
          text
          @click="goBack"
        />
        <Button
          v-if="step === 'merge'"
          icon="pi pi-check"
          :label="t('dedup.dialog.merge.submit')"
          severity="danger"
          :loading="isMerging"
          :disabled="!masterId"
          @click="submitMerge"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import RadioButton from 'primevue/radiobutton'
import ProgressSpinner from 'primevue/progressspinner'
import Message from 'primevue/message'
import { useDedupFlow } from './useDedupFlow'
import type { DedupCandidate } from '@/entities/crm'

const props = defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  merged: []
}>()

const { t } = useI18n()

const {
  step,
  scope,
  groups,
  scanning,
  selectedGroup,
  masterId,
  isMerging,
  isDismissing,
  mergeError,
  scan,
  selectGroup,
  submitMerge,
  dismissGroup,
  goBack,
  reset,
  getFieldKeys,
  getFieldLabel,
  getCandidateFieldValue,
} = useDedupFlow({
  onMerged: () => emit('merged'),
})

const visible = computed({
  get: () => props.visible,
  set: (v) => emit('update:visible', v),
})

const scopeOptions = [
  { label: t('dedup.dialog.scan.type.company'), value: 'company' as const },
  { label: t('dedup.dialog.scan.type.contact'), value: 'contact' as const },
]

const dialogTitle = computed(() => {
  if (step.value === 'merge') return t('dedup.dialog.merge.title')
  if (step.value === 'candidates' && groups.value.length > 0) {
    return t('dedup.dialog.titleWithCount', { count: groups.value.length })
  }
  return t('dedup.dialog.title')
})

const mergeWarning = computed(() => {
  if (!selectedGroup.value || !masterId.value) return null
  const dupes = selectedGroup.value.entities.filter((e) => e.id !== masterId.value)
  if (dupes.length === 0) return null
  return t('dedup.dialog.merge.warning', { id: dupes.map((d) => d.id).join(', ') })
})

const mergePreviewRows = computed(() => {
  if (!selectedGroup.value) return []
  const first = selectedGroup.value.entities[0]
  if (!first) return []
  return getFieldKeys(first).map((key) => ({
    key,
    label: getFieldLabel(key),
  }))
})

function getCandidateName(candidate: DedupCandidate | undefined): string {
  if (!candidate) return ''
  return candidate.full_name ?? candidate.name ?? `ID ${candidate.id}`
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ru-RU')
}

function onHide() {
  reset()
}
</script>

<style lang="scss" scoped>
.dedup-dialog {
  &__step {
    min-height: 200px;
    display: flex;
    flex-direction: column;
    gap: $space-4;
  }

  &__scan-controls {
    display: flex;
    align-items: center;
    gap: $space-4;
  }

  &__scanning {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-4;
    padding: $space-8;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-lg;
    color: $surface-400;

    &--success {
      color: var(--p-green-500, #a7efaa);
    }
  }

  &__empty-title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0;
  }

  &__empty-subtitle {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }

  &__info {
    margin-bottom: $space-1;
  }

  &__groups {
    display: flex;
    flex-direction: column;
    gap: $space-5;
  }

  &__group {
    border: 1px solid $surface-200;
    border-radius: $radius-md;
    overflow: hidden;
  }

  &__group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: $space-3 $space-4;
    background: $surface-50;
    border-bottom: 1px solid $surface-200;
    gap: $space-2;
  }

  &__group-title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-800;
  }

  &__group-actions {
    display: flex;
    gap: $space-2;
  }

  &__group-table {
    border: none;
  }

  &__section-label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0;
  }

  &__master-options {
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }

  &__master-option {
    display: flex;
    align-items: flex-start;
    gap: $space-2;
  }

  &__master-label {
    font-size: $font-size-sm;
    color: $surface-800;
    cursor: pointer;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: $space-2;
  }

  &__master-id {
    font-weight: $font-weight-semibold;
    color: $surface-500;
    font-size: $font-size-xs;
  }

  &__master-meta {
    font-size: $font-size-xs;
    color: $surface-500;
  }

  &__field-master {
    font-weight: $font-weight-semibold;
    color: var(--p-primary-color, #172747);
  }

  &__field-key {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  &__warning,
  &__error {
    margin-top: $space-2;
  }

  &__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: $space-2;
    width: 100%;
  }
}
</style>
