<template>
  <section class="registry-table">
    <!-- Section header: title + total badge (danger accent for squeeze) -->
    <header class="registry-table__header">
      <div class="registry-table__title-wrap">
        <i
          v-if="severity === 'danger'"
          class="pi pi-arrow-circle-down registry-table__title-icon"
          aria-hidden="true"
        />
        <h3 class="registry-table__title">{{ title }}</h3>
        <span class="registry-table__period">{{ periodLabel }}</span>
      </div>
      <Tag
        :severity="severity"
        :value="`${t('dashboard.registry.total')}: ${formatMkMoney(totalBaseKopecks, baseCurrency)}`"
        class="registry-table__total-badge"
      />
    </header>

    <!-- Empty (no rows). Squeeze-empty is a *good* outcome → success accent. -->
    <div v-if="rows.length === 0" class="registry-table__empty">
      <i
        :class="severity === 'danger' ? 'pi pi-check-circle' : 'pi pi-inbox'"
        class="registry-table__empty-icon"
        :data-good="severity === 'danger' ? 'true' : undefined"
        aria-hidden="true"
      />
      <p class="registry-table__empty-text">{{ emptyText }}</p>
    </div>

    <DataTable
      v-else
      :value="rows"
      size="small"
      :striped-rows="true"
      row-hover
      scrollable
      scroll-height="flex"
      :row-class="rowClass"
      class="registry-table__table"
    >
      <!-- Pay date -->
      <Column :header="t('dashboard.registry.col_pay_date')" class="registry-table__col-date">
        <template #body="{ data }">
          <span v-if="(data as RegistryRow).expected_payment_date" class="registry-table__date">
            {{ formatDate((data as RegistryRow).expected_payment_date) }}
          </span>
          <span
            v-else
            v-tooltip.top="t('dashboard.registry.no_pay_date')"
            class="registry-table__no-date"
          >
            <i class="pi pi-exclamation-triangle" aria-hidden="true" />
          </span>
        </template>
      </Column>

      <!-- Deal (link) -->
      <Column :header="t('dashboard.registry.col_deal')" class="registry-table__col-deal">
        <template #body="{ data }">
          <RouterLink
            :to="`/deals/${(data as RegistryRow).deal_id}`"
            class="registry-table__deal-link"
          >
            {{ (data as RegistryRow).title }}
          </RouterLink>
        </template>
      </Column>

      <!-- Company -->
      <Column :header="t('dashboard.registry.col_company')" class="registry-table__col-company">
        <template #body="{ data }">
          <span class="registry-table__company">{{ (data as RegistryRow).company_name }}</span>
        </template>
      </Column>

      <!-- Products (chips, +N overflow) -->
      <Column :header="t('dashboard.registry.col_products')" class="registry-table__col-products">
        <template #body="{ data }">
          <div class="registry-table__products">
            <Tag
              v-for="p in visibleProducts(data as RegistryRow)"
              :key="p.name"
              severity="secondary"
              :value="p.name"
              class="registry-table__product-tag"
            />
            <Tag
              v-if="overflowCount(data as RegistryRow) > 0"
              v-tooltip.top="overflowNames(data as RegistryRow)"
              severity="secondary"
              :value="`+${overflowCount(data as RegistryRow)}`"
              class="registry-table__product-tag"
            />
          </div>
        </template>
      </Column>

      <!-- Amount (K оплате) — base kopecks, right-aligned -->
      <Column :header="t('dashboard.registry.col_amount')" class="registry-table__col-amount">
        <template #body="{ data }">
          <span
            v-tooltip.top="amountTooltip(data as RegistryRow)"
            class="registry-table__amount"
          >
            {{ formatMkMoney((data as RegistryRow).amount_base_kopecks, baseCurrency) }}
          </span>
        </template>
      </Column>

      <!-- Responsible -->
      <Column :header="t('dashboard.registry.col_responsible')" class="registry-table__col-owner">
        <template #body="{ data }">
          <div v-if="(data as RegistryRow).owner" class="registry-table__owner">
            <EntityAvatar
              :name="(data as RegistryRow).owner!.full_name"
              :entity-id="(data as RegistryRow).owner!.id"
              :pixel-size="22"
            />
            <span class="registry-table__owner-name">
              {{ (data as RegistryRow).owner!.full_name }}
            </span>
          </div>
          <span v-else class="registry-table__owner-none">—</span>
        </template>
      </Column>

      <!-- Last task -->
      <Column :header="t('dashboard.registry.col_last_task')" class="registry-table__col-task">
        <template #body="{ data }">
          <div v-if="(data as RegistryRow).last_task" class="registry-table__task">
            <span class="registry-table__task-text">
              {{ (data as RegistryRow).last_task!.result_text }}
            </span>
            <span class="registry-table__task-time">
              <i class="pi pi-clock" aria-hidden="true" />
              {{ relativeTime((data as RegistryRow).last_task!.at) }}
            </span>
          </div>
          <span v-else class="registry-table__task-none">—</span>
        </template>
      </Column>

      <!-- Footer: total (K оплате) -->
      <ColumnGroup type="footer">
        <Row>
          <Column :footer="t('dashboard.registry.total')" :colspan="4" footer-class="registry-table__footer-label" />
          <Column footer-class="registry-table__footer-total">
            <template #footer>
              <span>{{ formatMkMoney(totalBaseKopecks, baseCurrency) }}</span>
            </template>
          </Column>
          <Column :colspan="2" />
        </Row>
      </ColumnGroup>
    </DataTable>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import Tag from 'primevue/tag'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import { formatMkMoney } from '@/utils/motivation'
import { formatCurrency } from '@/utils/currency'
import type { RegistryRow } from '@/entities/reports'

const props = defineProps<{
  rows: RegistryRow[]
  totalBaseKopecks: number
  title: string
  periodLabel: string
  emptyText: string
  baseCurrency: string
  /** `info` = expected list, `danger` = squeeze list (drives accents). */
  severity: 'info' | 'danger'
}>()

const { t, locale } = useI18n()

const MAX_PRODUCT_CHIPS = 3

const visibleProducts = (row: RegistryRow) => row.products.slice(0, MAX_PRODUCT_CHIPS)

const overflowCount = (row: RegistryRow): number =>
  Math.max(0, row.products.length - MAX_PRODUCT_CHIPS)

const overflowNames = (row: RegistryRow): string =>
  row.products
    .slice(MAX_PRODUCT_CHIPS)
    .map((p) => p.name)
    .join(', ')

// Rows with no expected_payment_date get a subtle warning highlight (plan §7 risk).
const rowClass = (row: RegistryRow): string | undefined =>
  props.severity === 'danger' && row.expected_payment_date == null
    ? 'registry-table__row--no-date'
    : undefined

const formatDate = (iso: string | null): string => {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(d)
}

// Amount tooltip surfaces the deal's own currency when it differs from base.
const amountTooltip = (row: RegistryRow): string | undefined => {
  if (row.currency === props.baseCurrency) return undefined
  return formatCurrency(row.amount_kopecks, row.currency)
}

/** Relative time (i18n-aware): «N мин / N ч / вчера / DD.MM». */
const relativeTime = (iso: string): string => {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const diffMs = Date.now() - d.getTime()
  const diffMin = Math.floor(diffMs / 60_000)
  const diffH = Math.floor(diffMs / 3_600_000)
  const diffD = Math.floor(diffMs / 86_400_000)
  if (diffMin < 1) return t('dashboard.registry.time_now')
  if (diffMin < 60) return t('dashboard.registry.time_min', { n: diffMin })
  if (diffH < 24) return t('dashboard.registry.time_hour', { n: diffH })
  if (diffD === 1) return t('dashboard.registry.time_yesterday')
  const tag = locale.value === 'en' ? 'en-US' : 'ru-RU'
  return new Intl.DateTimeFormat(tag, { day: '2-digit', month: '2-digit' }).format(d)
}
</script>

<style lang="scss" scoped>
// Section on a widget-card подложка (§3.3) — reactive $surface-card fill,
// $surface-200 border (dark override on this scoped element), $radius-lg, $shadow-sm.
.registry-table {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  padding: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.registry-table__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: $space-2;
}

.registry-table__title-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.registry-table__title-icon {
  color: var(--p-red-500);
  font-size: $font-size-md;

  .app-dark & {
    color: var(--p-red-400);
  }
}

// $surface-* aliases to --p-surface-* which PrimeVue inverts on .app-dark, so
// surface text/fills are theme-reactive from the BASE rule — no dark override
// needed. Only the red/green/yellow/primary accents differ per theme.
.registry-table__title {
  margin: 0;
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.registry-table__period {
  font-size: $font-size-sm;
  color: $surface-500;
}

.registry-table__total-badge {
  font-variant-numeric: tabular-nums;
  font-weight: $font-weight-semibold;
}

// ── Empty state ──────────────────────────────────────────────────────────────
// The section now sits on its own widget-card подложка (§3.3), so the empty state
// no longer needs its own card fill — it inherits the section surface.
.registry-table__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 140px;
  padding: $space-6;
  text-align: center;
}

.registry-table__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;

  &[data-good='true'] {
    color: var(--p-green-500);
  }

  .app-dark &[data-good='true'] {
    color: var(--p-green-400);
  }
}

.registry-table__empty-text {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;
}

// ── Table ────────────────────────────────────────────────────────────────────
.registry-table__table {
  :deep(.p-datatable-tbody > tr > td),
  :deep(.p-datatable-thead > tr > th) {
    padding: $space-2 $space-3;
  }

  :deep(.p-datatable-thead > tr > th) {
    white-space: nowrap;
  }

  // No-date squeeze rows — subtle warning tint mixed against the card surface so
  // it stays readable in both themes (--p-card-background inverts to navy in dark).
  :deep(.registry-table__row--no-date > td) {
    background: color-mix(in srgb, var(--p-yellow-500) 14%, var(--p-card-background));
  }
}

.registry-table__date {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.registry-table__no-date {
  color: var(--p-yellow-500);

  .app-dark & {
    color: var(--p-yellow-400);
  }
}

.registry-table__deal-link {
  color: $primary-color;
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    text-decoration: underline;
  }

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.registry-table__company {
  color: $surface-700;
}

.registry-table__products {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1;
}

.registry-table__product-tag {
  font-size: $font-size-2xs;
}

.registry-table__amount {
  font-variant-numeric: tabular-nums;
  font-weight: $font-weight-semibold;
  white-space: nowrap;
}

.registry-table__col-amount {
  text-align: right;
}

.registry-table__owner {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.registry-table__owner-name {
  white-space: nowrap;
}

.registry-table__owner-none {
  color: $surface-400;
}

.registry-table__task {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  max-width: 260px;
}

.registry-table__task-text {
  font-size: $font-size-sm;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.registry-table__task-time {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-500;
}

.registry-table__task-none {
  color: $surface-400;
}

.registry-table__table {
  :deep(.registry-table__footer-label) {
    text-align: right;
    font-weight: $font-weight-semibold;
  }

  :deep(.registry-table__footer-total) {
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-weight: $font-weight-bold;
    white-space: nowrap;
  }
}
</style>
