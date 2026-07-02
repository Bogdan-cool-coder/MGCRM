<template>
  <Dialog
    v-model:visible="localVisible"
    :header="dialogTitle"
    modal
    :draggable="false"
    :closable="true"
    append-to="body"
    block-scroll
    style="width: 860px; max-width: 96vw; max-height: 92vh"
    :pt="{ content: { style: 'overflow-y: auto; max-height: calc(92vh - 120px)' } }"
    @hide="onHide"
  >
    <!-- ── Шаг 1: Scan (только dedup-режим) ─────────────────────────────────── -->
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

    <!-- ── Шаг 2: Candidates (только dedup-режим) ────────────────────────────── -->
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
              <!-- Per-pair: 2 записи — прямой dismiss -->
              <Button
                v-if="group.entities.length === 2"
                icon="pi pi-times"
                :label="t('dedup.dialog.candidates.notDuplicate')"
                severity="secondary"
                text
                size="small"
                :loading="isDismissing"
                @click="dismissPair(group.entities[0]!.id, group.entities[1]!.id, group.key)"
              />
              <!-- Per-pair: 3+ записей — Popover со списком пар -->
              <template v-else>
                <Button
                  :ref="(el) => setDismissBtnRef(group.key, el)"
                  icon="pi pi-chevron-down"
                  :label="t('dedup.dialog.candidates.notDuplicatePairs')"
                  severity="secondary"
                  text
                  size="small"
                  :loading="isDismissing"
                  @click="toggleDismissPopover(group.key, $event)"
                />
                <Popover :ref="(el) => setDismissPopRef(group.key, el)">
                  <div class="dedup-dialog__dismiss-pop">
                    <p class="dedup-dialog__dismiss-pop-title">
                      {{ t('dedup.dialog.candidates.notDuplicatePairs') }}
                    </p>
                    <button
                      v-for="pair in getGroupPairs(group)"
                      :key="`${pair.a.id}-${pair.b.id}`"
                      class="dedup-dialog__dismiss-pair-row"
                      @click="onDismissPairClick(pair.a.id, pair.b.id, group.key, group.key)"
                    >
                      <span class="dedup-dialog__dismiss-pair-name">{{ getCandidateName(pair.a) }}</span>
                      <span class="dedup-dialog__dismiss-pair-sep">✕</span>
                      <span class="dedup-dialog__dismiss-pair-name">{{ getCandidateName(pair.b) }}</span>
                    </button>
                  </div>
                </Popover>
              </template>
            </div>
          </div>

          <!-- Candidates DataTable with drill-in links -->
          <DataTable :value="group.entities" size="small" class="dedup-dialog__group-table">
            <Column
              field="id"
              :header="t('dedup.dialog.candidates.columns.id')"
              style="width: 80px"
            />
            <Column :header="t('dedup.dialog.candidates.columns.name')">
              <template #body="{ data }">
                <a
                  :href="entityRoute(data)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="dedup-dialog__entity-link"
                  @click.stop
                >
                  {{ getCandidateName(data) }}
                  <i class="pi pi-external-link dedup-dialog__entity-link-icon" />
                </a>
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

    <!-- ── Шаг 3: Merge ──────────────────────────────────────────────────────── -->
    <div v-else-if="step === 'merge' && selectedGroup" class="dedup-dialog__step">
      <!-- Скелетон пока bulkEntities ещё не пришёл -->
      <template v-if="isBulkLoading">
        <Skeleton height="20px" class="mb-2" />
        <Skeleton height="20px" class="mb-2" />
        <Skeleton height="20px" class="mb-2" />
        <Skeleton height="20px" />
      </template>

      <template v-else>
        <!-- ── Секция: Главная запись ──────────────────────────────────────── -->
        <div class="dedup-dialog__section">
          <div class="dedup-dialog__section-header">
            <i class="pi pi-star" />
            <span>{{ t('dedup.dialog.merge.masterLabel') }}</span>
          </div>
          <div class="dedup-dialog__master-options">
            <div
              v-for="entity in selectedGroup.entities"
              :key="entity.id"
              class="dedup-dialog__master-option"
              :class="{ 'dedup-dialog__master-option--selected': entity.id === masterId }"
              @click="masterId = entity.id"
            >
              <RadioButton
                v-model="masterId"
                :value="entity.id"
                :input-id="`master-${entity.id}`"
              />
              <EntityAvatar
                :name="getCandidateName(entity)"
                :pixel-size="32"
                class="dedup-dialog__master-avatar"
              />
              <label :for="`master-${entity.id}`" class="dedup-dialog__master-label">
                <span class="dedup-dialog__master-name">{{ getCandidateName(entity) }}</span>
                <Tag :value="`ID ${entity.id}`" severity="secondary" size="small" />
                <span v-if="entity.email" class="dedup-dialog__master-meta">{{ entity.email }}</span>
                <span v-if="entity.phone" class="dedup-dialog__master-meta">{{ entity.phone }}</span>
              </label>
              <a
                :href="entityRoute(entity)"
                target="_blank"
                rel="noopener noreferrer"
                class="dedup-dialog__drill-btn"
                :title="t('dedup.dialog.merge.openCard')"
                @click.stop
              >
                <i class="pi pi-external-link" />
              </a>
            </div>
          </div>
        </div>

        <!-- ── Секция: Per-field выбор источника ──────────────────────────── -->
        <div class="dedup-dialog__section">
          <div class="dedup-dialog__section-header">
            <i class="pi pi-list" />
            <span>{{ t('dedup.dialog.merge.fieldsLabel') }}</span>
          </div>
          <DataTable
            :value="mergePreviewRows"
            size="small"
            :scrollable="selectedGroup.entities.length > 3"
            scroll-direction="horizontal"
            class="dedup-dialog__fields-table"
          >
            <Column
              :header="''"
              style="width: 140px; min-width: 140px"
              frozen
            >
              <template #body="{ data }">
                <span class="dedup-dialog__field-key">{{ data.label }}</span>
              </template>
            </Column>
            <Column
              v-for="entity in selectedGroup.entities"
              :key="entity.id"
              :header="`ID ${entity.id}${entity.id === masterId ? ' ✓' : ''}`"
              style="min-width: 120px"
            >
              <template #body="{ data }">
                <MergeFieldCell
                  :field-key="data.key"
                  :entity-id="entity.id"
                  :value="getCandidateFieldValue(entity, data.key)"
                  :selected-id="fieldOverrides[data.key]"
                  @select="setFieldOverride(data.key, entity.id)"
                />
              </template>
            </Column>
          </DataTable>
        </div>

        <!-- ── Секция: Append-блок ─────────────────────────────────────────── -->
        <div class="dedup-dialog__section">
          <div class="dedup-dialog__section-header dedup-dialog__section-header--append">
            <i class="pi pi-plus-circle" />
            <span>{{ t('dedup.dialog.merge.appendLabel') }}</span>
          </div>
          <div class="dedup-dialog__append-block">
            <!-- Channels: phones -->
            <div v-if="appendPhones.length > 0" class="dedup-dialog__append-row">
              <span class="dedup-dialog__append-label">{{ t('dedup.dialog.merge.phones') }}:</span>
              <div class="dedup-dialog__append-tags">
                <Tag
                  v-for="val in appendPhones"
                  :key="val"
                  :value="val"
                  severity="secondary"
                  size="small"
                />
                <span v-if="skippedPhones > 0" class="dedup-dialog__append-skipped">
                  {{
                    t('dedup.dialog.merge.uniqueOf', {
                      unique: appendPhones.length,
                      skipped: skippedPhones,
                    })
                  }}
                </span>
              </div>
            </div>

            <!-- Channels: emails -->
            <div v-if="appendEmails.length > 0" class="dedup-dialog__append-row">
              <span class="dedup-dialog__append-label">{{ t('dedup.dialog.merge.emails') }}:</span>
              <div class="dedup-dialog__append-tags">
                <Tag
                  v-for="val in appendEmails"
                  :key="val"
                  :value="val"
                  severity="secondary"
                  size="small"
                />
                <span v-if="skippedEmails > 0" class="dedup-dialog__append-skipped">
                  {{
                    t('dedup.dialog.merge.uniqueOf', {
                      unique: appendEmails.length,
                      skipped: skippedEmails,
                    })
                  }}
                </span>
              </div>
            </div>

            <!-- Aggregate counters -->
            <div v-if="totalDealLinks > 0" class="dedup-dialog__append-row">
              <span class="dedup-dialog__append-label">{{ t('dedup.dialog.merge.dealLinks') }}:</span>
              <span class="dedup-dialog__append-count">+{{ totalDealLinks }}</span>
            </div>

            <div v-if="scope === 'contact' && totalCompanyLinks > 0" class="dedup-dialog__append-row">
              <span class="dedup-dialog__append-label">{{ t('dedup.dialog.merge.companyLinks') }}:</span>
              <span class="dedup-dialog__append-count">+{{ totalCompanyLinks }}</span>
            </div>

            <div v-if="totalActivities > 0" class="dedup-dialog__append-row">
              <span class="dedup-dialog__append-label">{{ t('dedup.dialog.merge.activities') }}:</span>
              <span class="dedup-dialog__append-count">+{{ totalActivities }}</span>
            </div>

            <Message severity="info" :closable="false" class="dedup-dialog__append-note">
              {{ t('dedup.dialog.merge.appendChannelsDupNote') }}
            </Message>
          </div>
        </div>

        <!-- ── Секция: Delete-блок ─────────────────────────────────────────── -->
        <div class="dedup-dialog__section">
          <div class="dedup-dialog__section-header dedup-dialog__section-header--delete">
            <i class="pi pi-trash" />
            <span>{{ t('dedup.dialog.merge.deleteLabel') }}</span>
          </div>
          <div class="dedup-dialog__delete-block">
            <div
              v-for="entity in duplicateEntities"
              :key="entity.id"
              class="dedup-dialog__delete-row"
            >
              <i class="pi pi-exclamation-triangle dedup-dialog__delete-warn-icon" />
              <Tag :value="`ID ${entity.id}`" severity="secondary" size="small" />
              <span class="dedup-dialog__delete-name">{{ getCandidateName(entity) }}</span>
              <a
                :href="entityRoute(entity)"
                target="_blank"
                rel="noopener noreferrer"
                class="dedup-dialog__drill-btn"
                :title="t('dedup.dialog.merge.openCard')"
                @click.stop
              >
                <i class="pi pi-external-link" />
              </a>
            </div>
            <Message severity="warn" :closable="false" class="dedup-dialog__delete-warning">
              {{ t('dedup.dialog.merge.deleteWarning') }}
            </Message>
          </div>
        </div>

        <!-- Error banner -->
        <Message v-if="mergeError" severity="error" :closable="false">
          {{ mergeError }}
        </Message>
      </template>
    </div>

    <!-- ── Footer ────────────────────────────────────────────────────────────── -->
    <template #footer>
      <div class="dedup-dialog__footer">
        <!-- BUG-CLOSE-1: candidates empty-state → close dialog, not goBack to scan -->
        <!-- BUG-CLOSE-2: bulk mode also gets a Close button -->
        <Button
          v-if="isBulk || step === 'candidates'"
          :label="t('common.close')"
          severity="secondary"
          text
          @click="close"
        />
        <!-- dedup merge step: Back button -->
        <Button
          v-if="step === 'merge' && !isBulk"
          :label="t('dedup.dialog.merge.back')"
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
import { computed, h, defineComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import RadioButton from 'primevue/radiobutton'
import ProgressSpinner from 'primevue/progressspinner'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import Popover from 'primevue/popover'
import Skeleton from 'primevue/skeleton'
import { useDedupFlow } from './useDedupFlow'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import type { DedupCandidate, DedupScope } from '@/entities/crm'
import type { DedupGroup } from '@/api/crm/dedup'

// ── MergeFieldCell — inline render-fn component to avoid vue/no-mutating-props ─
const MergeFieldCell = defineComponent({
  props: {
    fieldKey: { type: String, required: true },
    entityId: { type: Number, required: true },
    value: { type: String, required: true },
    selectedId: { type: Number, default: undefined },
  },
  emits: ['select'],
  setup(props, { emit }) {
    return () => {
      const isEmpty = !props.value
      const isSelected = props.selectedId === props.entityId
      return h('div', {
        class: [
          'merge-field-cell',
          isEmpty ? 'merge-field-cell--empty' : '',
          isSelected ? 'merge-field-cell--selected' : '',
        ].filter(Boolean).join(' '),
      }, [
        h(RadioButton, {
          modelValue: props.selectedId,
          value: props.entityId,
          name: `field-${props.fieldKey}`,
          disabled: isEmpty,
          onChange: () => { if (!isEmpty) emit('select') },
        }),
        h('span', { class: 'merge-field-cell__value' }, props.value || '—'),
      ])
    }
  },
})

const props = defineProps<{
  visible: boolean
  mode?: 'dedup' | 'bulk'
  bulkEntities?: DedupCandidate[]
  entityType?: DedupScope
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  merged: []
}>()

const { t } = useI18n()

const isBulk = computed(() => props.mode === 'bulk')

// Wrap bulkEntities + entityType as refs for composable
const bulkEntitiesRef = computed(() => props.bulkEntities ?? [])
const entityTypeRef = computed(() => props.entityType ?? 'contact')

const {
  step,
  scope,
  groups,
  scanning,
  selectedGroup,
  masterId,
  fieldOverrides,
  isMerging,
  isDismissing,
  mergeError,
  scan,
  selectGroup,
  submitMerge,
  dismissPair,
  goBack,
  reset,
  getFieldKeys,
  getFieldLabel,
  getCandidateFieldValue,
  setFieldOverride,
} = useDedupFlow({
  onMerged: () => emit('merged'),
  mode: props.mode ?? 'dedup',
  bulkEntities: bulkEntitiesRef,
  entityType: entityTypeRef,
})

const localVisible = computed({
  get: () => props.visible,
  set: (v) => emit('update:visible', v),
})

// ── Scope options ─────────────────────────────────────────────────────────────

const scopeOptions = [
  { label: t('dedup.dialog.scan.type.company'), value: 'company' as const },
  { label: t('dedup.dialog.scan.type.contact'), value: 'contact' as const },
]

// ── Dialog title ──────────────────────────────────────────────────────────────

const dialogTitle = computed(() => {
  if (step.value === 'merge') {
    if (isBulk.value && selectedGroup.value) {
      return t('dedup.dialog.merge.titleBulk', { count: selectedGroup.value.entities.length })
    }
    return t('dedup.dialog.merge.title')
  }
  if (step.value === 'candidates' && groups.value.length > 0) {
    return t('dedup.dialog.titleWithCount', { count: groups.value.length })
  }
  return t('dedup.dialog.title')
})

// ── Bulk loading state (skeleton) ─────────────────────────────────────────────

const isBulkLoading = computed(
  () => isBulk.value && (!selectedGroup.value || selectedGroup.value.entities.length === 0),
)

// ── Helpers ───────────────────────────────────────────────────────────────────

function getCandidateName(candidate: DedupCandidate | undefined): string {
  if (!candidate) return ''
  return candidate.full_name ?? candidate.name ?? `ID ${candidate.id}`
}

function entityRoute(candidate: DedupCandidate): string {
  if (candidate.type === 'contact') return `/contacts/${candidate.id}`
  return `/companies/${candidate.id}`
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ru-RU')
}

// ── Merge preview rows ────────────────────────────────────────────────────────

const mergePreviewRows = computed(() => {
  if (!selectedGroup.value) return []
  const first = selectedGroup.value.entities[0]
  if (!first) return []
  return getFieldKeys(first).map((key) => ({
    key,
    label: getFieldLabel(key),
  }))
})

// ── Duplicate entities (non-master) ──────────────────────────────────────────

const duplicateEntities = computed(() => {
  if (!selectedGroup.value) return []
  return selectedGroup.value.entities.filter((e) => e.id !== masterId.value)
})

// ── Append-block computeds ────────────────────────────────────────────────────

const masterEntity = computed(() => {
  if (!selectedGroup.value || !masterId.value) return null
  return selectedGroup.value.entities.find((e) => e.id === masterId.value) ?? null
})

/**
 * Collect all phone/email channel values from all candidates,
 * then split into "unique new" vs "already in master" (skipped).
 */
function collectChannels(type: 'phone' | 'email') {
  if (!selectedGroup.value || !masterEntity.value) return { unique: [], skipped: 0 }

  const masterCandidate = masterEntity.value
  // Collect master values from channels or direct fields
  const masterVals = new Set<string>()
  if (masterCandidate.channels) {
    for (const ch of masterCandidate.channels) {
      if (ch.type === type) masterVals.add(ch.value.toLowerCase().trim())
    }
  }
  if (type === 'phone' && masterCandidate.phone) {
    masterVals.add(masterCandidate.phone.toLowerCase().trim())
  }
  if (type === 'email' && masterCandidate.email) {
    masterVals.add(masterCandidate.email.toLowerCase().trim())
  }

  const dupes = duplicateEntities.value
  const allVals: string[] = []
  for (const candidate of dupes) {
    if (candidate.channels) {
      for (const ch of candidate.channels) {
        if (ch.type === type) allVals.push(ch.value)
      }
    }
    if (type === 'phone' && candidate.phone) allVals.push(candidate.phone)
    if (type === 'email' && candidate.email) allVals.push(candidate.email)
  }

  // Deduplicate across dupes themselves
  const seenGlobal = new Set<string>()
  const uniqueNew: string[] = []
  let skipped = 0

  for (const v of allVals) {
    const norm = v.toLowerCase().trim()
    if (seenGlobal.has(norm)) continue
    seenGlobal.add(norm)
    if (masterVals.has(norm)) {
      skipped++
    } else {
      uniqueNew.push(v)
    }
  }

  return { unique: uniqueNew, skipped }
}

const appendPhones = computed(() => collectChannels('phone').unique)
const skippedPhones = computed(() => collectChannels('phone').skipped)
const appendEmails = computed(() => collectChannels('email').unique)
const skippedEmails = computed(() => collectChannels('email').skipped)

const totalDealLinks = computed(() =>
  duplicateEntities.value.reduce((sum, e) => sum + (e.open_deals_count ?? 0), 0),
)

const totalCompanyLinks = computed(() =>
  duplicateEntities.value.reduce((sum, e) => sum + (e.company_links_count ?? 0), 0),
)

const totalActivities = computed(() =>
  duplicateEntities.value.reduce((sum, e) => sum + (e.activities_count ?? 0), 0),
)

// ── Per-pair dismiss with Popover refs ────────────────────────────────────────

const dismissPopRefs = new Map<string, unknown>()
const dismissBtnRefs = new Map<string, unknown>()

function setDismissPopRef(key: string, el: unknown) {
  if (el) dismissPopRefs.set(key, el)
}

function setDismissBtnRef(key: string, el: unknown) {
  if (el) dismissBtnRefs.set(key, el)
}

function toggleDismissPopover(key: string, event: MouseEvent) {
  const pop = dismissPopRefs.get(key) as { toggle?: (e: MouseEvent) => void } | undefined
  pop?.toggle?.(event)
}

function getGroupPairs(group: DedupGroup): Array<{ a: DedupCandidate; b: DedupCandidate }> {
  const pairs: Array<{ a: DedupCandidate; b: DedupCandidate }> = []
  const entities = group.entities
  for (let i = 0; i < entities.length; i++) {
    for (let j = i + 1; j < entities.length; j++) {
      pairs.push({ a: entities[i]!, b: entities[j]! })
    }
  }
  return pairs
}

async function onDismissPairClick(
  aId: number,
  bId: number,
  groupKey: string,
  popKey: string,
) {
  // Close popover
  const pop = dismissPopRefs.get(popKey) as { hide?: () => void } | undefined
  pop?.hide?.()
  await dismissPair(aId, bId, groupKey)
}

// ── Close (unified) ───────────────────────────────────────────────────────────
// All close paths (X, Escape, backdrop, "Закрыть" buttons) go through here.
// Only emit — reset() must NOT run here because the dialog is still animating
// (leave-transition). Running reset() synchronously would change `step` while
// the mask is live, causing a flash and potentially leaking pointer events
// through the mask before the animation completes.

function close() {
  localVisible.value = false
}

// onHide fires after PrimeVue Dialog fully closes (after leave-transition).
// This is the ONLY place reset() must run so state is clean for next open.
function onHide() {
  reset()
}
</script>

<style lang="scss" scoped>
.dedup-dialog {
  &__step {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    min-height: 160px;
  }

  // ── Scan step ──────────────────────────────────────────────────────────────

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

  // ── Empty state ────────────────────────────────────────────────────────────

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
      color: var(--p-green-500);
    }
  }

  &__empty-title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  &__empty-subtitle {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }

  // ── Groups (candidates) ────────────────────────────────────────────────────

  &__groups {
    display: flex;
    flex-direction: column;
    gap: $space-5;
  }

  &__group {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    overflow: hidden;
  }

  &__group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: $space-3 $space-4;
    background: $surface-50;
    border-bottom: 1px solid var(--p-surface-200);
    gap: $space-2;
    flex-wrap: wrap;

    .app-dark & {
      background: var(--p-surface-50);
    }
  }

  &__group-title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-800;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  &__group-actions {
    display: flex;
    gap: $space-2;
    align-items: center;
    flex-wrap: wrap;
  }

  &__group-table {
    border: none;
  }

  // Drill-in link in candidates table
  &__entity-link {
    color: $primary-900;
    text-decoration: none;
    font-weight: $font-weight-semibold;
    font-size: $font-size-sm;

    &:hover {
      text-decoration: underline;
    }

    .app-dark & {
      color: var(--p-primary-color);
    }
  }

  &__entity-link-icon {
    font-size: $font-size-xs;
    margin-left: $space-1;
  }

  // ── Dismiss popover ────────────────────────────────────────────────────────

  &__dismiss-pop {
    min-width: 280px;
    max-width: 400px;
    padding: $space-2;
  }

  &__dismiss-pop-title {
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    color: $surface-500;
    margin: 0 0 $space-2;
    padding: 0 $space-2;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  &__dismiss-pair-row {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-2 $space-2;
    border-radius: $radius-sm;
    cursor: pointer;
    background: none;
    border: none;
    width: 100%;
    text-align: left;

    &:hover {
      background: $surface-50;

      .app-dark & {
        background: var(--p-surface-200);
      }
    }
  }

  &__dismiss-pair-name {
    font-size: $font-size-sm;
    color: var(--p-text-color);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
  }

  &__dismiss-pair-sep {
    font-size: $font-size-xs;
    color: $surface-400;
    flex-shrink: 0;
  }

  // ── Merge step sections ────────────────────────────────────────────────────

  &__section {
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }

  &__section-header {
    display: flex;
    align-items: center;
    gap: $space-2;
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    padding-bottom: $space-2;
    border-bottom: 1px solid var(--p-surface-200);

    .app-dark & {
      color: var(--p-surface-200);
    }

    &--append i {
      color: $green-900;
    }

    &--delete i {
      color: $red-500;
    }
  }

  // ── Master selection ───────────────────────────────────────────────────────

  &__master-options {
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }

  &__master-option {
    display: flex;
    align-items: center;
    gap: $space-3;
    padding: $space-2 $space-3;
    border-radius: $radius-md;
    border: 1px solid var(--p-surface-200);
    cursor: pointer;
    transition: background 0.15s;

    &:hover {
      background: $surface-50;

      .app-dark & {
        background: var(--p-surface-50);
      }
    }

    &--selected {
      background: var(--p-primary-50);
      border-color: var(--p-primary-200);

      // dark: brand navy rgba — #172747 is a brand-invariant constant, allowed per DS rules
      .app-dark & {
        background: rgba(23, 39, 71, 0.18);
        border-color: rgba(23, 39, 71, 0.4);
      }
    }
  }

  &__master-avatar {
    flex-shrink: 0;
  }

  &__master-label {
    flex: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: $space-2;
    cursor: pointer;
    min-width: 0;
  }

  &__master-name {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
  }

  &__master-meta {
    font-size: $font-size-xs;
    color: $surface-500;
  }

  // Drill-in button (external-link icon)
  &__drill-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: $radius-sm;
    color: $surface-400;
    text-decoration: none;
    flex-shrink: 0;
    transition: color 0.15s, background 0.15s;

    &:hover {
      color: $primary-900;
      background: $surface-50;

      .app-dark & {
        color: var(--p-primary-color);
        background: var(--p-surface-100);
      }
    }

    i {
      font-size: $font-size-xs;
    }
  }

  // ── Per-field table ────────────────────────────────────────────────────────

  &__fields-table {
    // Override DataTable styles for per-field selector
    :deep(.p-datatable-tbody > tr > td) {
      padding: $space-2 $space-3;
    }
    :deep(.p-datatable-thead > tr > th) {
      padding: $space-2 $space-3;
      font-size: $font-size-xs;
      font-weight: $font-weight-semibold;
    }
  }

  &__field-key {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  // ── Append block ──────────────────────────────────────────────────────────

  &__append-block {
    border: 1px solid $green-100;
    background: var(--p-green-50, #f0fdf4);
    border-radius: $radius-md;
    padding: $space-3 $space-4;
    display: flex;
    flex-direction: column;
    gap: $space-2;

    .app-dark & {
      background: rgba(21, 128, 61, 0.12);
      border-color: rgba(21, 128, 61, 0.3);
    }
  }

  &__append-row {
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: $space-2;
  }

  &__append-label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
    white-space: nowrap;
    flex-shrink: 0;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  &__append-tags {
    display: flex;
    flex-wrap: wrap;
    gap: $space-1;
    align-items: center;
  }

  &__append-skipped {
    font-size: $font-size-xs;
    color: $surface-400;
  }

  &__append-count {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $green-900;
  }

  &__append-note {
    margin-top: $space-1;
  }

  // ── Delete block ──────────────────────────────────────────────────────────

  &__delete-block {
    border: 1px solid $red-200;
    background: $red-50;
    border-radius: $radius-md;
    padding: $space-3 $space-4;
    display: flex;
    flex-direction: column;
    gap: $space-2;

    .app-dark & {
      background: rgba(155, 25, 23, 0.15);
      border-color: rgba(230, 28, 20, 0.4);
    }
  }

  &__delete-row {
    display: flex;
    align-items: center;
    gap: $space-2;
  }

  &__delete-warn-icon {
    color: $orange-500;
    font-size: $font-size-sm;
    flex-shrink: 0;
  }

  &__delete-name {
    font-size: $font-size-sm;
    color: $surface-700;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  &__delete-warning {
    margin-top: $space-1;
  }

  // ── Footer ─────────────────────────────────────────────────────────────────

  &__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: $space-2;
    width: 100%;
  }
}

// ── Per-field cell ────────────────────────────────────────────────────────────

.merge-field-cell {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1;
  border-radius: $radius-sm;
  transition: background 0.1s;

  &__value {
    font-size: $font-size-sm;
    color: var(--p-text-color);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &--empty {
    opacity: 0.4;
    pointer-events: none;
  }

  &--selected {
    background: var(--p-primary-50);

    // dark: brand navy rgba — #172747 is a brand-invariant constant, allowed per DS rules
    .app-dark & {
      background: rgba(23, 39, 71, 0.18);
    }
  }
}
</style>
