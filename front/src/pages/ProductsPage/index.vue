<template>
  <div class="products-page" :class="{ 'products-page--embedded': embedded }">
    <!-- Toolbar canon (non-embedded) -->
    <ProductsToolbar
      v-if="!embedded"
      :total="total"
      :search="filter.q"
      :active-filter-count="activeFilterCount"
      :can-write="canWrite"
      @search="onSearchQuery"
      @open-filter="onOpenFilter"
      @create="openCreateDrawer"
      @import="openImportDialog"
      @download-template="downloadTemplate"
    />

    <!-- Filter panel (Popover, pattern Б) — shared by both branches -->
    <ProductsFilterPanel
      ref="filterPanelRef"
      :filter="filter"
      :groups="groups"
      :active-filter-count="activeFilterCount"
      @update:filter="onFilterPatch"
      @apply="applyFilter"
      @reset="resetFilter"
    />

    <div class="products-page__content">
      <!-- Compact canon filter strip — embedded (inside /settings) only.
           Section heading/icon/counter come from the Settings section chrome;
           right-side actions (edit/import/create) stay in the DirTabCatalog parent. -->
      <div v-if="embedded" class="products-page__toolbar">
        <div class="products-page__search-pill">
          <i class="pi pi-search products-page__search-pill-icon" />
          <InputText
            v-model="filter.q"
            :placeholder="t('catalog.products.page.filters.search')"
            class="products-page__search-pill-input"
            @input="onSearchInput"
          />
        </div>
        <FilterTriggerButton
          :label="t('catalog.products.page.toolbar.filters')"
          :count="activeFilterCount"
          @click="onOpenFilter"
        />
      </div>

      <!-- Import menu (embedded — toolbar lives in the /settings parent) -->
      <Menu
        v-if="embedded"
        ref="importMenuRef"
        :model="importMenuItems"
        popup
      />

      <!-- Table card -->
      <div class="products-page__card">
        <!-- Empty state — catalog empty, canWrite -->
        <div
          v-if="!loading && products.length === 0 && !isFiltered && canWrite"
          class="products-page__empty"
        >
          <i class="pi pi-box products-page__empty-icon" />
          <p class="products-page__empty-title">{{ t('catalog.products.page.empty.title') }}</p>
          <p class="products-page__empty-subtitle">{{ t('catalog.products.page.empty.subtitle') }}</p>
          <div class="d-flex gap-3">
            <Button icon="pi pi-plus" :label="t('catalog.products.page.create')" @click="openCreateDrawer" />
            <Button
              icon="pi pi-upload"
              :label="t('catalog.products.page.import')"
              severity="secondary"
              outlined
              @click="openImportDialog"
            />
          </div>
        </div>

        <!-- Empty state — catalog empty, readOnly -->
        <div
          v-else-if="!loading && products.length === 0 && !isFiltered && !canWrite"
          class="products-page__empty"
        >
          <i class="pi pi-box products-page__empty-icon" />
          <p class="products-page__empty-title">{{ t('catalog.products.page.empty.title') }}</p>
          <p class="products-page__empty-subtitle">{{ t('catalog.products.page.empty.subtitle') }}</p>
        </div>

        <!-- Empty — filtered -->
        <div
          v-else-if="!loading && products.length === 0 && isFiltered"
          class="products-page__empty"
        >
          <i class="pi pi-filter-slash products-page__empty-icon" />
          <p class="products-page__empty-title">{{ t('catalog.products.page.empty.filtered') }}</p>
          <p class="products-page__empty-subtitle">{{ t('catalog.products.page.empty.filteredSubtitle') }}</p>
          <Button
            severity="secondary"
            :label="t('catalog.products.page.empty.resetFilters')"
            @click="resetFilter"
          />
        </div>

        <!-- DataTable -->
        <DataTable
          v-else
          v-model:expanded-rows="expandedRows"
          :value="products"
          :loading="loading"
          striped-rows
          lazy
          data-key="id"
          class="products-page__table"
          @row-click="onRowClick"
        >
          <!-- Expander -->
          <Column expander style="width: 3rem" />

          <!-- Code -->
          <Column
            field="code"
            :header="t('catalog.products.page.columns.code')"
            sortable
            style="width: 140px"
          >
            <template #body="{ data }">
              <span class="products-page__code">{{ data.code }}</span>
            </template>
          </Column>

          <!-- Name -->
          <Column
            field="name"
            :header="t('catalog.products.page.columns.name')"
            sortable
            style="min-width: 180px"
          >
            <template #body="{ data }">
              <router-link
                :to="`/admin/products/${data.id}`"
                class="products-page__name-link"
                @click.stop
              >
                {{ data.name }}
              </router-link>
            </template>
          </Column>

          <!-- Group -->
          <Column
            :header="t('catalog.products.page.columns.group')"
            style="min-width: 120px"
          >
            <template #body="{ data }">
              {{ data.group?.name ?? '—' }}
            </template>
          </Column>

          <!-- Pricing Type -->
          <Column
            :header="t('catalog.products.page.columns.pricingType')"
            style="min-width: 130px"
          >
            <template #body="{ data }">
              <ProductPricingTypeTag :pricing-type="data.pricing_type" />
            </template>
          </Column>

          <!-- KZT -->
          <Column header="KZT" style="min-width: 130px">
            <template #body="{ data }">
              <span v-if="getBasePrice(data, 'KZT') !== null" style="white-space: nowrap">
                {{ formatCurrency(getBasePrice(data, 'KZT')!, 'KZT') }}
              </span>
              <span v-else class="products-page__no-price">—</span>
            </template>
          </Column>

          <!-- RUB -->
          <Column header="RUB" style="min-width: 110px">
            <template #body="{ data }">
              <span v-if="getBasePrice(data, 'RUB') !== null">
                {{ formatCurrency(getBasePrice(data, 'RUB')!, 'RUB') }}
              </span>
              <span v-else class="products-page__no-price">—</span>
            </template>
          </Column>

          <!-- USD -->
          <Column header="USD" style="min-width: 100px">
            <template #body="{ data }">
              <span v-if="getBasePrice(data, 'USD') !== null">
                {{ formatCurrency(getBasePrice(data, 'USD')!, 'USD') }}
              </span>
              <span v-else class="products-page__no-price">—</span>
            </template>
          </Column>

          <!-- Active toggle -->
          <Column
            :header="t('catalog.products.page.columns.isActive')"
            style="width: 80px"
          >
            <template #body="{ data }">
              <ToggleSwitch
                v-model="data.is_active"
                :disabled="!canWrite"
                @change="toggleActive(data)"
              />
            </template>
          </Column>

          <!-- Actions (kebab) — edit-mode only -->
          <Column
            v-if="editMode && canWrite"
            :header="t('catalog.products.page.columns.actions')"
            style="width: 60px"
          >
            <template #body="{ data }">
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
          </Column>

          <!-- Row expansion -->
          <template #expansion="slotProps">
            <ProductPlansPriceTable :product="slotProps.data" />
          </template>
        </DataTable>

        <!-- Paginator -->
        <Paginator
          v-show="total > 0"
          :rows="perPage"
          :total-records="total"
          :first="(page - 1) * perPage"
          :rows-per-page-options="[25, 50, 100]"
          class="products-page__paginator"
          @page="onPageChange"
        />
        <div v-if="total > 0" class="products-page__total">
          {{ t('common.total', { count: total }) }}
        </div>
      </div>
    </div>

    <!-- Create / Edit Drawer -->
    <ProductCreateDrawer
      v-model="createDrawerOpen"
      :edit-product="editingProduct"
      :groups="groups"
      @saved="onProductSaved"
    />

    <!-- Price Import Dialog -->
    <PriceImportDialog
      v-model="importDialogOpen"
      @imported="load"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import ToggleSwitch from 'primevue/toggleswitch'
import Menu from 'primevue/menu'
import Paginator from 'primevue/paginator'
import { useUserStore } from '@/stores/user'
import { useProductsPageData, type ProductsFilter } from './composables/useProductsPageData'
import { useProductsPageActions } from './composables/useProductsPageActions'
import { formatCurrency } from '@/utils/currency'
import FilterTriggerButton from '@/components/shared/FilterTriggerButton.vue'
import ProductPricingTypeTag from './components/ProductPricingTypeTag.vue'
import ProductPlansPriceTable from './components/ProductPlansPriceTable.vue'
import ProductCreateDrawer from './components/ProductCreateDrawer.vue'
import ProductsToolbar from './components/ProductsToolbar.vue'
import ProductsFilterPanel from './components/ProductsFilterPanel.vue'
import PriceImportDialog from './components/PriceImportDialog.vue'
import type { ProductDto } from '@/entities/catalog'

const { t } = useI18n()
const router = useRouter()
const userStore = useUserStore()

withDefaults(defineProps<{ embedded?: boolean; editMode?: boolean }>(), {
  embedded: false,
  editMode: false,
})

const canWrite = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const {
  page,
  perPage,
  filter,
  loading,
  products,
  total,
  groups,
  isFiltered,
  load,
  loadGroups,
  applyFilter,
  resetFilter,
  onPageChange,
} = useProductsPageData()

const {
  createDrawerOpen,
  editingProduct,
  importDialogOpen,
  toggleActive,
  confirmDelete,
  openCreateDrawer,
  openEditDrawer,
  openImportDialog,
  downloadTemplate,
} = useProductsPageActions({ reload: load })

// Expanded rows
const expandedRows = ref<Record<number, boolean>>({})

// ── Toolbar canon wiring (non-embedded) ────────────────────────────────────────

const filterPanelRef = ref<InstanceType<typeof ProductsFilterPanel> | null>(null)

// Badge = active panel filters only (search stays visible in the toolbar, not counted).
const activeFilterCount = computed(() => {
  let n = 0
  if (filter.value.group_id !== null) n++
  if (filter.value.pricing_type !== null) n++
  if (filter.value.active_only !== true) n++
  return n
})

function onSearchQuery(query: string) {
  filter.value.q = query
  onSearchInput()
}

function onOpenFilter(event: Event) {
  filterPanelRef.value?.toggle(event)
}

function onFilterPatch(patch: Partial<ProductsFilter>) {
  Object.assign(filter.value, patch)
}

// Import menu
const importMenuRef = ref<InstanceType<typeof Menu> | null>(null)
const importMenuItems = computed(() => [
  {
    label: t('catalog.products.page.importMenu.upload'),
    icon: 'pi pi-upload',
    command: () => openImportDialog(),
  },
  {
    label: t('catalog.products.page.importMenu.template'),
    icon: 'pi pi-download',
    command: () => downloadTemplate(),
  },
])

function toggleImportMenu(event: Event) {
  importMenuRef.value?.toggle(event)
}

// Row menus
const rowMenuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setRowMenuRef(id: number, el: unknown) {
  if (el) rowMenuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function toggleRowMenu(event: Event, id: number) {
  rowMenuRefs.value.get(id)?.toggle(event)
}

function getRowMenuItems(product: ProductDto) {
  return [
    {
      label: t('catalog.products.page.actions.edit'),
      icon: 'pi pi-pencil',
      command: () => openEditDrawer(product),
    },
    {
      label: t('catalog.products.page.actions.delete'),
      icon: 'pi pi-trash',
      command: () => confirmDelete(product),
    },
  ]
}

// Price helpers
function getBasePrice(product: ProductDto, currency: string): number | null {
  const prices = product.prices ?? []
  const p = prices.find((pr) => pr.plan_id === null && pr.currency_code === currency)
  return p ? p.amount : null
}

// Filter options for the pricing-type / status Selects live inside ProductsFilterPanel;
// they are no longer needed here since the embedded strip uses the shared filter Popover.

// Debounced search
let searchTimer: ReturnType<typeof setTimeout> | null = null
function onSearchInput() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => applyFilter(), 300)
}

function onProductSaved() {
  void load()
}

function onRowClick(event: { data: ProductDto }) {
  void router.push(`/admin/products/${event.data.id}`)
}

onMounted(() => {
  void load()
  void loadGroups()
})

const rowCount = computed(() => products.value.length)

defineExpose({ canWrite, openCreateDrawer, toggleImportMenu, importMenuRef, importMenuItems, rowCount })
</script>

<style lang="scss" scoped>
.products-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;

  &--embedded {
    margin: 0;
    height: auto;
  }
}

.products-page__content {
  padding: $space-4 $space-6;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.products-page__toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

// Search pill — canon (mirrors ProductsToolbar__search-wrap)
.products-page__search-pill {
  position: relative;
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}

.products-page__search-pill-icon {
  position: absolute;
  left: 11px;
  font-size: $font-size-xs;
  color: $surface-400;
  pointer-events: none;
  z-index: 1;
}

.products-page__search-pill-input {
  width: 240px;
  height: 38px;
  padding: $space-2 $space-3 $space-2 32px;
  font-size: $font-size-base;
  box-sizing: border-box;
}

.products-page__card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.products-page__table {
  flex: 1;
  cursor: pointer;

  // Canon uppercase column headers (matches core-2.0 list tables)
  :deep(.p-datatable-column-title) {
    text-transform: uppercase;
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    letter-spacing: 0.03em;
    color: $surface-500;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }
}

.products-page__code {
  font-family: $font-family-mono;
  font-size: $font-size-xs;
  color: $surface-600;
}

// In-cell link — theme-reactive primary (dark navy accent), NOT static $primary-color
.products-page__name-link {
  color: var(--p-primary-color);
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    text-decoration: underline;
  }
}

.products-page__no-price {
  color: $surface-400;
}

.products-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 300px;
  text-align: center;
}

.products-page__empty-icon {
  font-size: $font-size-icon-2xl;
  color: $surface-400;
}

.products-page__empty-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;
}

.products-page__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.products-page__paginator {
  border-top: 1px solid $surface-200;
}

.products-page__total {
  padding: $space-2 $space-4;
  font-size: $font-size-sm;
  color: $surface-500;
  text-align: right;
}
</style>
