<template>
  <div class="rating-table-card">
    <DataTable
      :value="rows"
      size="small"
      :striped-rows="true"
      row-hover
      scrollable
      scroll-height="flex"
      :row-class="rowClass"
      class="rating-table"
    >
    <!-- Rank (#) — medal for the top three -->
    <Column
      :header="t('dashboard.rating.col_rank')"
      frozen
      align-frozen="left"
      class="rating-table__col-rank"
    >
      <template #body="{ data }">
        <span class="rating-table__rank">
          <i
            v-if="medalOf(data as BestManagerRow)"
            class="pi pi-star-fill rating-table__medal"
            :class="`rating-table__medal--${medalOf(data as BestManagerRow)}`"
            aria-hidden="true"
          />
          <span class="rating-table__rank-num">{{ rankLabel(data as BestManagerRow) }}</span>
        </span>
      </template>
    </Column>

    <!-- Manager (avatar + name) — frozen so numbers scroll under it -->
    <Column
      :header="t('dashboard.rating.col_manager')"
      frozen
      align-frozen="left"
      class="rating-table__col-manager"
    >
      <template #body="{ data }">
        <div class="rating-table__manager">
          <EntityAvatar
            :name="(data as BestManagerRow).user.full_name"
            :entity-id="(data as BestManagerRow).user.id"
            :pixel-size="24"
          />
          <span
            class="rating-table__manager-name"
            :title="(data as BestManagerRow).user.full_name"
          >
            {{ (data as BestManagerRow).user.full_name }}
          </span>
        </div>
      </template>
    </Column>

    <!-- Deals -->
    <Column :header="t('dashboard.rating.col_deals')" class="rating-table__col-num">
      <template #body="{ data }">
        <span class="rating-table__num">{{ numOrDash(data as BestManagerRow, 'won_deals') }}</span>
      </template>
    </Column>

    <!-- Supports -->
    <Column :header="t('dashboard.rating.col_escort')" class="rating-table__col-num">
      <template #body="{ data }">
        <span class="rating-table__num">{{ numOrDash(data as BestManagerRow, 'supports') }}</span>
      </template>
    </Column>

    <!-- New income -->
    <Column :header="t('dashboard.rating.col_income')" class="rating-table__col-money">
      <template #body="{ data }">
        <span class="rating-table__num">
          {{ moneyOrDash(data as BestManagerRow, 'new_income_base_kopecks') }}
        </span>
      </template>
    </Column>

    <!-- Average check -->
    <Column :header="t('dashboard.rating.col_avg_check')" class="rating-table__col-money">
      <template #body="{ data }">
        <span class="rating-table__num">
          {{ moneyOrDash(data as BestManagerRow, 'avg_check_base_kopecks') }}
        </span>
      </template>
    </Column>

    <!-- Income points -->
    <Column :header="t('dashboard.rating.col_points_income')" class="rating-table__col-num">
      <template #body="{ data }">
        <span class="rating-table__num">{{ numOrDash(data as BestManagerRow, 'income_points') }}</span>
      </template>
    </Column>

    <!-- Total points (emphasised) -->
    <Column :header="t('dashboard.rating.col_points_total')" class="rating-table__col-num">
      <template #body="{ data }">
        <span class="rating-table__num rating-table__num--total">
          {{ pointsOrDash(data as BestManagerRow) }}
        </span>
      </template>
    </Column>

    <!-- Division -->
    <Column :header="t('dashboard.rating.col_division')" class="rating-table__col-division">
      <template #body="{ data }">
        <span
          class="rating-table__division"
          :title="(data as BestManagerRow).division"
        >{{ (data as BestManagerRow).division }}</span>
      </template>
    </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import { formatMkMoney } from '@/utils/motivation'
import type { BestManagerRow } from '@/entities/reports'

const props = defineProps<{
  rows: BestManagerRow[]
  baseCurrency: string
}>()

const { t, locale } = useI18n()

const DASH = '—'

type Medal = 'gold' | 'silver' | 'bronze'

/**
 * Medal tier for the top three IN-STANDINGS ranks. Colours are token-clean
 * (no new hex): gold → $orange-500 (brand warm accent), silver → $surface-400
 * (neutral grey, inverts per theme), bronze → $orange-700 (deeper orange). All
 * three are dark-aware — documented choice per delegation payload (no $rank-* hex).
 */
const medalOf = (row: BestManagerRow): Medal | null => {
  if (!row.in_standings || row.rank == null) return null
  if (row.rank === 1) return 'gold'
  if (row.rank === 2) return 'silver'
  if (row.rank === 3) return 'bronze'
  return null
}

const rankLabel = (row: BestManagerRow): string =>
  row.in_standings && row.rank != null ? String(row.rank) : DASH

// Out-of-standings accounts (service/admin) render «—» across the numbers.
const numOrDash = (row: BestManagerRow, key: 'won_deals' | 'supports' | 'income_points'): string =>
  row.in_standings
    ? new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'ru-RU').format(row[key])
    : DASH

const moneyOrDash = (
  row: BestManagerRow,
  key: 'new_income_base_kopecks' | 'avg_check_base_kopecks',
): string => (row.in_standings ? formatMkMoney(row[key], props.baseCurrency) : DASH)

const pointsOrDash = (row: BestManagerRow): string =>
  row.in_standings
    ? new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'ru-RU').format(row.total_points)
    : DASH

const rowClass = (row: BestManagerRow): string | undefined =>
  row.in_standings ? undefined : 'rating-table__row--out'
</script>

<style lang="scss" scoped>
// Widget-card подложка (§5.3) — reactive $surface-card fill, $surface-200 border
// (dark override on this scoped element), $radius-lg, $shadow-sm.
.rating-table-card {
  padding: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

// $surface-* / $primary-color alias to --p-* which PrimeVue inverts on .app-dark,
// so surfaces/text are theme-reactive from the BASE rule — only the medal accents
// (orange/primary) need a dark override.
.rating-table {
  :deep(.p-datatable-tbody > tr > td),
  :deep(.p-datatable-thead > tr > th) {
    padding: $space-2 $space-3;
  }

  :deep(.p-datatable-thead > tr > th) {
    white-space: nowrap;
  }

  // Out-of-standings rows — muted (SpaceCRM «вне зачёта»).
  :deep(.rating-table__row--out > td) {
    color: $surface-400;
  }

  // Frozen ранг + менеджер columns OPAQUE so scrolled number columns don't bleed
  // through in dark (reactive table surface, consistent with the striped rows).
  :deep(.p-datatable-frozen-column) {
    background: var(--p-datatable-header-cell-background, var(--p-card-background));
  }
}

// ── Rank + medal ─────────────────────────────────────────────────────────────
.rating-table__rank {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  font-variant-numeric: tabular-nums;
}

.rating-table__rank-num {
  font-weight: $font-weight-semibold;
}

.rating-table__medal {
  font-size: $font-size-md;
}

// Separate BEM modifier classes (not compound `&.is-*`) with their own dark
// override — the endorsed `.app-dark &` idiom (memory trap #1). All three lift
// on the navy card in dark to clear the ≥3:1 contrast bar.
.rating-table__medal--gold {
  color: $orange-500;

  .app-dark & {
    color: var(--p-orange-400);
  }
}

.rating-table__medal--silver {
  color: $surface-400;

  // $surface-400 (#3A4F78 in dark) is only ~2:1 on the card — lift to surface-600
  // (~5:1 on #111E38), matching the gold/bronze dark branches.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

.rating-table__medal--bronze {
  color: $orange-700;

  .app-dark & {
    color: var(--p-orange-600);
  }
}

// ── Manager ──────────────────────────────────────────────────────────────────
.rating-table__manager {
  display: flex;
  align-items: center;
  gap: $space-2;
  min-width: 0; // let the name ellipsize instead of widening the frozen column
}

.rating-table__manager-name {
  white-space: nowrap;
  font-weight: $font-weight-medium;
  // Cap the frozen manager column so a long ФИО no longer eats the scroll
  // viewport of the numeric columns on 1280 (audit L1). Full name in :title.
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}

// ── Numbers ──────────────────────────────────────────────────────────────────
.rating-table__col-num,
.rating-table__col-money {
  text-align: right;
}

.rating-table__num {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.rating-table__num--total {
  font-weight: $font-weight-bold;
  color: $primary-color;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

// Out-of-standings total keeps the muted colour, not the primary emphasis.
:deep(.rating-table__row--out) .rating-table__num--total {
  color: inherit;
  font-weight: $font-weight-medium;
}

.rating-table__division {
  display: block;
  color: $surface-600;
  white-space: nowrap;
  // Cap + ellipsis so a long division name doesn't widen its column (audit L1).
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
