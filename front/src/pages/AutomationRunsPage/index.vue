<template>
  <div class="automation-runs-page" :class="{ 'automation-runs-page--embedded': embedded }">
    <!-- Toolbar canon (hidden inside /settings — same contract as the old PageHeader) -->
    <AutomationRunsToolbar
      v-if="!embedded"
      :loaded-count="page.runs.value.length"
      :filter-open="filterOpen"
      :filter-count="activeFilterCount"
      :dry-run-disabled="!page.filterAutomationId.value"
      @toggle-filter="filterOpen = !filterOpen"
      @dry-run="openDryRun"
    />

    <!-- Filter panel (embedded: always shown; standalone: toggled by trigger) -->
    <AutomationRunsFilterPanel
      v-if="embedded || filterOpen"
      v-model:automation-id="page.filterAutomationId.value"
      v-model:status="page.filterStatus.value"
      v-model:action-kind="page.filterActionKind.value"
      v-model:date-range="page.filterDateRange.value"
      :automations="page.automations.value"
      :status-options="statusOptions"
      :action-options="actionOptions"
      @apply="() => page.fetchRuns()"
      @reset="onResetFilters"
    />

    <div class="automation-runs-page__content">
      <!-- Error banner -->
      <Message
        v-if="page.loadError.value"
        severity="error"
        :closable="false"
        class="automation-runs-page__error"
      >
        {{ page.loadError.value }}
      </Message>

      <!-- DataTable (card-backed) -->
      <div class="automation-runs-page__card">
        <DataTable
          :value="page.runs.value"
          :loading="page.loading.value"
          striped-rows
          class="automation-runs-page__table"
          :empty-message="' '"
        >
          <template #empty>
            <div class="automation-runs-page__empty">
              <i
                :class="[
                  'automation-runs-page__empty-icon',
                  activeFilterCount > 0 ? 'pi pi-filter-slash' : 'pi pi-clock',
                ]"
              />
              <p class="automation-runs-page__empty-title">
                {{ activeFilterCount > 0 ? t('automation.runs.emptyFiltered') : t('automation.runs.empty') }}
              </p>
              <Button
                v-if="activeFilterCount > 0"
                :label="t('common.reset')"
                severity="secondary"
                outlined
                size="small"
                @click="onResetFilters"
              />
            </div>
          </template>

        <!-- Automation name -->
        <Column :header="t('automation.runs.col.automation')">
          <template #body="{ data }">
            {{ data.automation_name ?? '—' }}
          </template>
        </Column>

        <!-- Action kind -->
        <Column :header="t('automation.runs.col.action')" style="width: 160px">
          <template #body="{ data }">
            <span v-if="data.action_kind">{{ t(`automation.action.${data.action_kind}`) }}</span>
            <span v-else class="automation-runs-page__muted">—</span>
          </template>
        </Column>

        <!-- Target -->
        <Column :header="t('automation.runs.col.target')" style="width: 120px">
          <template #body="{ data }">
            <span class="automation-runs-page__target">
              {{ formatTarget(data) }}
            </span>
          </template>
        </Column>

        <!-- Status -->
        <Column :header="t('automation.runs.col.status')" style="width: 120px">
          <template #body="{ data }">
            <Tag
              :value="t(`automation.runs.status.${data.status}`)"
              :severity="statusSeverity(data.status)"
              size="small"
            />
          </template>
        </Column>

        <!-- Time -->
        <Column :header="t('automation.runs.col.startedAt')" style="width: 150px">
          <template #body="{ data }">
            {{ formatDateTime(data.started_at) }}
          </template>
        </Column>

        <!-- Error -->
        <Column :header="t('automation.runs.col.error')">
          <template #body="{ data }">
            <span
              v-if="data.error_message"
              v-tooltip.top="data.error_message"
              class="automation-runs-page__error-text"
            >
              {{ truncate(data.error_message, 60) }}
            </span>
            <span v-else class="automation-runs-page__muted">—</span>
          </template>
        </Column>
        </DataTable>
      </div>

      <!-- Load more + showing counter (canon: growing cursor log, no total → «Показано N») -->
      <div v-if="page.runs.value.length > 0" class="automation-runs-page__footer">
        <Button
          v-if="page.hasMore.value"
          :label="t('automation.runs.loadMore')"
          severity="secondary"
          outlined
          :loading="page.loading.value"
          @click="page.loadMore()"
        />
        <span class="automation-runs-page__count">
          {{ t('automation.runs.showing', { n: page.runs.value.length }) }}
        </span>
      </div>
    </div>

    <!-- Dry-run Drawer (separate component) -->
    <DryRunDrawer
      v-model:visible="dryRunVisible"
      :automation="page.selectedAutomation.value"
      @ran="() => page.fetchRuns()"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import type { AutomationRunDto, RunStatus, ActionKind } from '@/entities/automation'
import { useAutomationRuns } from './composables/useAutomationRuns'
import AutomationRunsToolbar from './components/AutomationRunsToolbar.vue'
import AutomationRunsFilterPanel from './components/AutomationRunsFilterPanel.vue'
import DryRunDrawer from './DryRunDrawer.vue'

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

// ─── Page composable (orchestrator) ──────────────────────────────────────────
const page = useAutomationRuns()

// ─── Filter panel toggle (pattern Б: trigger opens the inline panel) ─────────
const filterOpen = ref(false)

const activeFilterCount = computed(() => {
  let n = 0
  if (page.filterAutomationId.value) n++
  if (page.filterStatus.value) n++
  if (page.filterActionKind.value) n++
  if (page.filterDateRange.value?.[0]) n++
  return n
})

function onResetFilters(): void {
  page.filterAutomationId.value = null
  page.filterStatus.value = null
  page.filterActionKind.value = null
  page.filterDateRange.value = null
  void page.fetchRuns()
}

// ─── Dry-run drawer state ─────────────────────────────────────────────────────
const dryRunVisible = ref(false)

function openDryRun(): void {
  if (!page.filterAutomationId.value) return
  dryRunVisible.value = true
}

// ─── Options ──────────────────────────────────────────────────────────────────

const STATUS_OPTIONS: RunStatus[] = ['success', 'failed', 'skipped', 'pending', 'queued']
const statusOptions = computed(() =>
  STATUS_OPTIONS.map((s) => ({ label: t(`automation.runs.status.${s}`), value: s })),
)

const ACTION_KINDS: ActionKind[] = [
  'tg_notify',
  'create_task',
  'set_field',
  'generate_document',
  'change_owner',
  'change_stage',
  'webhook',
  'email',
]
const actionOptions = computed(() =>
  ACTION_KINDS.map((k) => ({ label: t(`automation.action.${k}`), value: k })),
)

// ─── Bootstrap ────────────────────────────────────────────────────────────────
onMounted(async () => {
  await Promise.all([page.fetchAutomations(), page.fetchRuns()])
})

// ─── Helpers ──────────────────────────────────────────────────────────────────
function statusSeverity(status: string): 'success' | 'danger' | 'info' | 'warning' | 'secondary' {
  const map: Record<string, 'success' | 'danger' | 'info' | 'warning' | 'secondary'> = {
    success: 'success',
    failed: 'danger',
    skipped: 'info',
    pending: 'warning',
    queued: 'secondary',
  }
  return map[status] ?? 'secondary'
}

function formatTarget(run: AutomationRunDto): string {
  const type = run.target_type === 'deal' ? t('automation.runs.targetDeal') : run.target_type
  return `${type} #${run.target_id}`
}

function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const yyyy = d.getFullYear()
  const hh = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  const ss = String(d.getSeconds()).padStart(2, '0')
  return `${dd}.${mm}.${yyyy} ${hh}:${min}:${ss}`
}

function truncate(s: string, len: number): string {
  return s.length > len ? s.slice(0, len) + '…' : s
}
</script>

<style lang="scss" scoped>
.automation-runs-page {
  display: flex;
  flex-direction: column;
  height: 100%;

  &--embedded {
    padding: 0;
    margin: 0;
  }

  &__content {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    padding: $space-6;
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  &__error {
    margin: 0;
  }

  // Card-backed table (canon: ProductsPage __card recipe)
  &__card {
    background: $surface-card;
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-lg;
    box-shadow: $shadow-card;
    overflow: hidden;

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__table {
    // var(--p-surface-50) is theme-reactive: #F9FAFB (light) / #0F1F3D (navy dark) —
    // already a correct dark table-header bg. Dead `:global(.app-dark) &__table` block
    // removed (interpolated `&__table` inside :global() is dropped by the compiler).
    :deep(th) {
      background-color: var(--p-surface-50);
      text-transform: uppercase;
      font-size: $font-size-xs;
      letter-spacing: 0.03em;
      color: var(--p-text-muted-color);
    }
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8 $space-4;
    text-align: center;
    color: var(--p-text-muted-color);
  }

  &__empty-icon {
    font-size: $font-size-icon-xl; // 2.5rem
    color: var(--p-surface-400);
  }

  &__empty-title {
    margin: 0;
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-700;

    .app-dark & {
      color: var(--p-surface-300);
    }
  }

  // Muted em-dash placeholder — bootstrap-grid.min.css ships no .text-muted utility.
  &__muted {
    color: var(--p-text-muted-color);
  }

  &__target {
    font-size: $font-size-sm;
  }

  &__error-text {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    cursor: help;
  }

  &__footer {
    display: flex;
    align-items: center;
    gap: $space-3;
    padding: $space-3 0;
  }

  &__count {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
  }
}
</style>
