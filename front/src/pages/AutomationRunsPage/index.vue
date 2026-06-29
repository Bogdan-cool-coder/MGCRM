<template>
  <div class="automation-runs-page" :class="{ 'automation-runs-page--embedded': embedded }">
    <PageHeader v-if="!embedded" :title="t('automation.runs.pageTitle')" icon="pi pi-clock" />

    <div class="automation-runs-page__content">
      <!-- Filters row -->
      <div class="automation-runs-page__filters">
        <Select
          v-model="page.filterAutomationId.value"
          :options="page.automations.value"
          option-label="name"
          option-value="id"
          :placeholder="t('automation.runs.filter.automation')"
          show-clear
          class="automation-runs-page__filter"
        />
        <Select
          v-model="page.filterStatus.value"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('automation.runs.filter.status')"
          show-clear
          class="automation-runs-page__filter"
        />
        <Select
          v-model="page.filterActionKind.value"
          :options="actionOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('automation.runs.filter.action')"
          show-clear
          class="automation-runs-page__filter"
        />
        <DatePicker
          v-model="page.filterDateRange.value"
          selection-mode="range"
          :placeholder="t('automation.runs.filter.period')"
          date-format="dd.mm.yy"
          show-clear
          class="automation-runs-page__filter"
        />
        <Button
          :label="t('automation.runs.applyFilter')"
          icon="pi pi-search"
          size="small"
          @click="() => page.fetchRuns()"
        />
        <Button
          :label="t('automation.dryrun.button')"
          icon="pi pi-play"
          severity="secondary"
          outlined
          size="small"
          :disabled="!page.filterAutomationId.value"
          @click="openDryRun"
        />
      </div>

      <!-- Error banner -->
      <Message
        v-if="page.loadError.value"
        severity="error"
        :closable="false"
        class="automation-runs-page__error"
      >
        {{ page.loadError.value }}
      </Message>

      <!-- DataTable -->
      <DataTable
        :value="page.runs.value"
        :loading="page.loading.value"
        striped-rows
        class="automation-runs-page__table"
        :empty-message="' '"
      >
        <template #empty>
          <div class="automation-runs-page__empty">
            <i class="pi pi-clock automation-runs-page__empty-icon" />
            <p>{{ t('automation.runs.empty') }}</p>
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
            <span v-else class="text-muted">—</span>
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
            <span v-else class="text-muted">—</span>
          </template>
        </Column>
      </DataTable>

      <!-- Load more -->
      <div v-if="page.runs.value.length > 0 && page.hasMore.value" class="automation-runs-page__load-more">
        <Button
          :label="t('automation.runs.loadMore')"
          severity="secondary"
          outlined
          :loading="page.loading.value"
          @click="page.loadMore()"
        />
        <span class="automation-runs-page__count">
          {{ t('automation.runs.count', { n: page.runs.value.length }) }}
        </span>
      </div>
    </div>

    <!-- Dry-run Drawer (separate component) -->
    <DryRunDrawer
      v-model:visible="dryRunVisible"
      :automation="page.selectedAutomation.value"
      @ran="() => page.fetchRuns()"
    />

    <Toast v-if="!embedded" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import Toast from 'primevue/toast'
import { PageHeader } from '@/components/AppShell'
import type { AutomationRunDto, RunStatus, ActionKind } from '@/entities/automation'
import { useAutomationRuns } from './composables/useAutomationRuns'
import DryRunDrawer from './DryRunDrawer.vue'

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

// ─── Page composable (orchestrator) ──────────────────────────────────────────
const page = useAutomationRuns()

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

  &__filters {
    display: flex;
    align-items: center;
    gap: $space-2;
    flex-wrap: wrap;
  }

  &__filter {
    min-width: 160px;
  }

  &__error {
    margin: 0;
  }

  &__table {
    :deep(th) {
      background-color: var(--p-surface-50);
    }
  }

  :global(.app-dark) &__table {
    :deep(th) {
      background-color: var(--p-surface-900);
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

  &__target {
    font-size: $font-size-sm;
  }

  &__error-text {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    cursor: help;
  }

  &__load-more {
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
