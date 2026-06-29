<template>
  <div class="rates-page" :class="{ 'rates-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('catalog.exchangeRates.page.title')"
      :subtitle="t('catalog.exchangeRates.page.subtitle')"
      icon="pi pi-chart-line"
    >
      <template #actions>
        <Button
          v-if="canWrite"
          icon="pi pi-refresh"
          :label="t('catalog.exchangeRates.page.refresh')"
          severity="secondary"
          outlined
          :loading="refreshing"
          @click="refreshRates"
        />
        <Button
          v-if="canWrite"
          icon="pi pi-plus"
          :label="t('catalog.exchangeRates.page.addManual')"
          @click="openCreateDialog"
        />
      </template>
    </PageHeader>

    <div class="rates-page__content">
      <!-- Age warning — only shown to admin/director who can actually trigger refresh -->
      <ExchangeRateAgeWarning
        v-if="canWrite"
        :is-stale="isStale"
        :refreshing="refreshing"
        @refresh="refreshRates"
      />

      <!-- Filter toolbar -->
      <div class="rates-page__toolbar">
        <Select
          v-model="filter.from_code"
          :options="currencyOptions"
          :placeholder="t('catalog.exchangeRates.page.filters.from')"
          show-clear
          class="rates-page__filter-select"
          @change="applyFilter"
        />
        <Select
          v-model="filter.to_code"
          :options="currencyOptions"
          :placeholder="t('catalog.exchangeRates.page.filters.to')"
          show-clear
          class="rates-page__filter-select"
          @change="applyFilter"
        />
        <DatePicker
          v-model="filter.date_from"
          date-format="dd.mm.yy"
          show-clear
          :placeholder="t('catalog.exchangeRates.page.filters.dateFrom')"
          class="rates-page__filter-date"
          @date-select="applyFilter"
          @clear-click="applyFilter"
        />
        <DatePicker
          v-model="filter.date_to"
          date-format="dd.mm.yy"
          show-clear
          :placeholder="t('catalog.exchangeRates.page.filters.dateTo')"
          class="rates-page__filter-date"
          @date-select="applyFilter"
          @clear-click="applyFilter"
        />
        <Button
          icon="pi pi-refresh"
          :label="t('catalog.exchangeRates.page.filters.reset')"
          severity="secondary"
          text
          @click="resetFilter"
        />
      </div>

      <!-- Table card -->
      <div class="rates-page__card">
        <!-- Empty state -->
        <div
          v-if="!loading && rates.length === 0"
          class="rates-page__empty"
        >
          <i class="pi pi-chart-line rates-page__empty-icon" />
          <p class="rates-page__empty-title">{{ t('catalog.exchangeRates.page.empty.title') }}</p>
          <p class="rates-page__empty-subtitle">{{ t('catalog.exchangeRates.page.empty.subtitle') }}</p>
        </div>

        <!-- DataTable -->
        <DataTable
          v-else
          :value="rates"
          :loading="loading"
          striped-rows
          lazy
          class="rates-page__table"
        >
          <!-- From -->
          <Column
            field="from_code"
            :header="t('catalog.exchangeRates.page.columns.from')"
            sortable
            style="width: 80px"
          />

          <!-- To -->
          <Column
            field="to_code"
            :header="t('catalog.exchangeRates.page.columns.to')"
            sortable
            style="width: 80px"
          />

          <!-- Rate -->
          <Column
            :header="t('catalog.exchangeRates.page.columns.rate')"
            sortable
            field="rate"
            style="min-width: 130px"
          >
            <template #body="{ data }">
              <span class="rates-page__rate">{{ Number(data.rate).toFixed(6) }}</span>
            </template>
          </Column>

          <!-- Date -->
          <Column
            field="date"
            :header="t('catalog.exchangeRates.page.columns.date')"
            sortable
            style="min-width: 110px"
          >
            <template #body="{ data }">
              {{ formatDate(data.date) }}
            </template>
          </Column>

          <!-- Source -->
          <Column
            :header="t('catalog.exchangeRates.page.columns.source')"
            style="min-width: 110px"
          >
            <template #body="{ data }">
              <Tag
                v-if="data.source === 'manual'"
                :value="t('catalog.exchangeRates.page.source.manual')"
                severity="secondary"
              />
              <Tag
                v-else
                :value="t('catalog.exchangeRates.page.source.api')"
                severity="info"
              />
            </template>
          </Column>

          <!-- Actions -->
          <Column
            :header="t('catalog.exchangeRates.page.columns.actions')"
            style="width: 60px"
          >
            <template #body="{ data }">
              <template v-if="canWrite">
                <Button
                  icon="pi pi-ellipsis-v"
                  text
                  severity="secondary"
                  size="small"
                  @click.stop="toggleRowMenu($event, data.id)"
                />
                <Menu
                  :ref="(el) => setRowMenuRef(data.id, el)"
                  :model="getRowMenuItems(data)"
                  popup
                />
              </template>
            </template>
          </Column>
        </DataTable>

        <!-- Paginator -->
        <Paginator
          v-show="total > 0"
          :rows="perPage"
          :total-records="total"
          :first="(page - 1) * perPage"
          :rows-per-page-options="[25, 50, 100]"
          class="rates-page__paginator"
          @page="onPageChange"
        />
        <div v-if="total > 0" class="rates-page__total">
          {{ t('common.total', { count: total }) }}
        </div>
      </div>
    </div>

    <!-- Manual Rate Dialog -->
    <ManualRateDialog
      v-model="manualDialogOpen"
      :edit-rate="editingRate"
      :saving="saveMutation.isPending.value"
      @submit="saveRate"
    />

    <Toast v-if="!embedded" position="top-right" />
    <ConfirmDialog v-if="!embedded" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Menu from 'primevue/menu'
import Paginator from 'primevue/paginator'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useUserStore } from '@/stores/user'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import { useExchangeRatesPage } from './composables/useExchangeRatesPage'
import { useExchangeRatesActions } from './composables/useExchangeRatesActions'
import ExchangeRateAgeWarning from './components/ExchangeRateAgeWarning.vue'
import ManualRateDialog from './components/ManualRateDialog.vue'
import type { ExchangeRateDto } from '@/entities/catalog'

const { t } = useI18n()
const userStore = useUserStore()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const canWrite = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const {
  page,
  perPage,
  filter,
  loading,
  rates,
  total,
  isStale,
  load,
  applyFilter,
  resetFilter,
  onPageChange,
} = useExchangeRatesPage()

const {
  manualDialogOpen,
  editingRate,
  refreshing,
  saveMutation,
  openCreateDialog,
  openEditDialog,
  refreshRates,
  saveRate,
  confirmDelete,
} = useExchangeRatesActions({ reload: load })

const currencyOptions = [...CURRENCY_WHITELIST]

// Row menus
const rowMenuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setRowMenuRef(id: number, el: unknown) {
  if (el) rowMenuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function toggleRowMenu(event: Event, id: number) {
  rowMenuRefs.value.get(id)?.toggle(event)
}

function getRowMenuItems(rate: ExchangeRateDto) {
  return [
    {
      label: t('catalog.exchangeRates.page.actions.edit'),
      icon: 'pi pi-pencil',
      command: () => openEditDialog(rate),
    },
    {
      label: t('catalog.exchangeRates.page.actions.delete'),
      icon: 'pi pi-trash',
      command: () => confirmDelete(rate),
    },
  ]
}

function formatDate(isoOrDate: string): string {
  const d = new Date(isoOrDate)
  return d.toLocaleDateString('ru-RU')
}

onMounted(() => {
  void load()
})

defineExpose({ canWrite, refreshRates, refreshing, openCreateDialog })
</script>

<style lang="scss" scoped>
.rates-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;

  &--embedded {
    margin: 0;
    height: auto;
  }
}

.rates-page__content {
  padding: $space-4 $space-6;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.rates-page__toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.rates-page__filter-select,
.rates-page__filter-date {
  min-width: 140px;
}

.rates-page__card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.rates-page__table {
  flex: 1;
}

.rates-page__rate {
  font-family: $font-family-mono;
  font-size: $font-size-xs; // snap from 13px
  font-variant-numeric: tabular-nums;
}

.rates-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 300px;
  text-align: center;
}

.rates-page__empty-icon {
  font-size: $font-size-icon-2xl;
  color: $surface-400;
}

.rates-page__empty-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;
}

.rates-page__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.rates-page__paginator {
  border-top: 1px solid $surface-200;
}

.rates-page__total {
  padding: $space-2 $space-4;
  font-size: $font-size-sm;
  color: $surface-500;
  text-align: right;
}
</style>
