<template>
  <div class="client-status-badge-wrapper">
    <Tag
      v-if="status"
      :severity="severity"
      size="small"
      class="client-status-badge"
      :class="[`client-status-badge--${status}`, { 'client-status-badge--clickable': isClickable }]"
      v-tooltip="tooltip"
      @click="onTagClick"
    >
      <template #default>
        <span class="client-status-badge__inner">
          <i :class="['pi', iconClass, 'client-status-badge__icon']" />
          <span>{{ label }}</span>
          <i v-if="isClickable" class="pi pi-chevron-down client-status-badge__chevron" />
        </span>
      </template>
    </Tag>

    <!-- Status log popover -->
    <Popover ref="popoverRef" class="client-status-badge__popover">
      <div class="client-status-badge__popover-inner">
        <div class="client-status-badge__popover-title">
          {{ t('crm.company.clientStatus.logTitle') }}
        </div>

        <!-- Loading -->
        <div v-if="logLoading" class="client-status-badge__popover-loading">
          <Skeleton height="40px" class="mb-2" border-radius="6px" />
          <Skeleton height="40px" class="mb-2" border-radius="6px" />
          <Skeleton height="40px" border-radius="6px" />
        </div>

        <!-- Error -->
        <div v-else-if="logError" class="client-status-badge__popover-empty">
          <i class="pi pi-exclamation-triangle" />
          <span>{{ t('errors.server_error') }}</span>
        </div>

        <!-- Empty -->
        <div v-else-if="statusLog.length === 0" class="client-status-badge__popover-empty">
          <i class="pi pi-history" />
          <span>{{ t('crm.company.clientStatus.logEmpty') }}</span>
        </div>

        <!-- Log entries -->
        <div v-else class="client-status-badge__log-list">
          <div
            v-for="entry in statusLog"
            :key="entry.id"
            class="client-status-badge__log-item"
          >
            <div class="client-status-badge__log-row">
              <Tag
                v-if="entry.old_status"
                :severity="statusSeverity(entry.old_status)"
                size="small"
                :value="t(`crm.company.clientStatus.${entry.old_status}`)"
              />
              <i class="pi pi-arrow-right client-status-badge__log-arrow" />
              <Tag
                :severity="statusSeverity(entry.new_status)"
                size="small"
                :value="t(`crm.company.clientStatus.${entry.new_status}`)"
              />
            </div>
            <div class="client-status-badge__log-meta">
              <span v-if="entry.changed_by_user">
                <i class="pi pi-user client-status-badge__log-meta-icon" />
                {{ entry.changed_by_user.full_name }}
              </span>
              <span v-if="entry.reason">
                <i class="pi pi-tag client-status-badge__log-meta-icon" />
                {{ entry.reason.name }}
              </span>
              <span v-if="entry.changed_at" class="client-status-badge__log-date">
                {{ formatDate(entry.changed_at) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import Popover from 'primevue/popover'
import Skeleton from 'primevue/skeleton'
import type { ClientStatus, CompanyClientStatusLogEntry } from '@/entities/crm'
import { companiesApi } from '@/api/crm/companies'

const props = defineProps<{
  status: ClientStatus | null | undefined
  since?: string | null
  disconnectedAt?: string | null
  companyId?: number | null
}>()

const { t } = useI18n()

const popoverRef = ref()
const logLoading = ref(false)
const logError = ref(false)
const statusLog = ref<CompanyClientStatusLogEntry[]>([])

const isClickable = computed(() =>
  (props.status === 'active' || props.status === 'disconnected') && !!props.companyId,
)

function formatDate(iso: string | null | undefined): string {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    return (
      d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
      ' ' +
      d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    )
  } catch {
    return iso
  }
}

const severity = computed((): 'secondary' | 'success' | 'danger' => {
  switch (props.status) {
    case 'active':
      return 'success'
    case 'disconnected':
      return 'danger'
    default:
      return 'secondary'
  }
})

const iconClass = computed((): string => {
  switch (props.status) {
    case 'active':
      return 'pi-verified'
    case 'disconnected':
      return 'pi-times-circle'
    default:
      return 'pi-user'
  }
})

const label = computed((): string => {
  if (!props.status) return ''
  const base = t(`crm.company.clientStatus.${props.status}`)
  if (props.status === 'active' && props.since) {
    return `${base} · ${t('crm.company.clientStatus.since', { date: formatDate(props.since) })}`
  }
  if (props.status === 'disconnected' && props.disconnectedAt) {
    return `${base} · ${t('crm.company.clientStatus.at', { date: formatDate(props.disconnectedAt) })}`
  }
  return base
})

const tooltip = computed((): string => {
  if (!isClickable.value) return ''
  return t('crm.company.clientStatus.logTooltip')
})

function statusSeverity(s: ClientStatus): 'secondary' | 'success' | 'danger' {
  switch (s) {
    case 'active':
      return 'success'
    case 'disconnected':
      return 'danger'
    default:
      return 'secondary'
  }
}

async function loadLog() {
  if (!props.companyId) return
  logLoading.value = true
  logError.value = false
  try {
    const res = await companiesApi.getStatusLog(props.companyId)
    statusLog.value = res.data
  } catch {
    logError.value = true
  } finally {
    logLoading.value = false
  }
}

function onTagClick(event: Event) {
  if (!isClickable.value) return
  const popover = popoverRef.value
  if (!popover) return
  popover.toggle(event)
  if (statusLog.value.length === 0 && !logLoading.value) {
    void loadLog()
  }
}
</script>

<style lang="scss" scoped>
.client-status-badge-wrapper {
  position: relative;
  display: inline-flex;
}

.client-status-badge {
  flex-shrink: 0;
  cursor: default;

  &--clickable {
    cursor: pointer;

    &:hover {
      opacity: 0.85;
    }
  }
}

.client-status-badge__inner {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.client-status-badge__icon {
  font-size: $font-size-3xs;
}

.client-status-badge__chevron {
  font-size: $font-size-3xs; // snap from 9px
  opacity: 0.7;
}

// ── Popover ───────────────────────────────────────────────────────────────────

.client-status-badge__popover-inner {
  min-width: 320px;
  max-width: 420px;
}

.client-status-badge__popover-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  margin-bottom: $space-3;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.client-status-badge__popover-loading {
  padding: $space-2 0;
}

.client-status-badge__popover-empty {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-sm;
  color: $surface-400;
  padding: $space-2 0;
}

// ── Log list ──────────────────────────────────────────────────────────────────

.client-status-badge__log-list {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  max-height: 320px;
  overflow-y: auto;
}

.client-status-badge__log-item {
  padding: $space-2 $space-3;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  display: flex;
  flex-direction: column;
  gap: $space-1;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.client-status-badge__log-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.client-status-badge__log-arrow {
  font-size: $font-size-3xs;
  color: $surface-400;
}

.client-status-badge__log-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-3;
  font-size: $font-size-xs;
  color: $surface-500;
}

.client-status-badge__log-meta-icon {
  font-size: $font-size-3xs;
  margin-right: 3px;
}

.client-status-badge__log-date {
  margin-left: auto;
  color: $surface-400;
  font-size: $font-size-xs;
}
</style>
