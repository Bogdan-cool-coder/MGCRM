<template>
  <div class="products-page" :class="{ 'products-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('catalog.products.page.title')"
      :subtitle="t('catalog.products.page.subtitle')"
      icon="pi pi-box"
    >
      <template #actions>
        <template v-if="canWrite">
          <Button
            icon="pi pi-upload"
            :label="t('catalog.products.page.import')"
            severity="secondary"
            outlined
            @click="toggleImportMenu"
          />
          <Menu
            ref="importMenuRef"
            :model="importMenuItems"
            popup
          />
          <Button
            icon="pi pi-plus"
            :label="t('catalog.products.page.create')"
            @click="openCreateDrawer"
          />
        </template>
      </template>
    </PageHeader>

    <div class="products-page__content">
      <!-- Filter toolbar -->
      <div class="products-page__toolbar">
        <IconField class="products-page__search">
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="filter.q"
            :placeholder="t('catalog.products.page.filters.search')"
            @input="onSearchInput"
          />
        </IconField>
        <Select
          v-model="filter.group_id"
          :options="groups"
          option-label="name"
          option-value="id"
          :placeholder="t('catalog.products.page.filters.group')"
          show-clear
          class="products-page__filter-select"
          @change="applyFilter"
        />
        <Select
          v-model="filter.pricing_type"
          :options="pricingTypeOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('catalog.products.page.filters.pricingType')"
          show-clear
          class="products-page__filter-select"
          @change="applyFilter"
        />
        <Select
          v-model="filter.active_only"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('catalog.products.page.filters.status')"
          class="products-page__filter-select"
          @change="applyFilter"
        />
        <Button
          icon="pi pi-refresh"
          :label="t('catalog.products.page.filters.reset')"
          severity="secondary"
          text
          @click="resetFilter"
        />
      </div>

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

          <!-- Actions -->
          <Column
            :header="t('catalog.products.page.columns.actions')"
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

    <Toast v-if="!embedded" position="top-right" />
    <ConfirmDialog v-if="!embedded" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import ToggleSwitch from 'primevue/toggleswitch'
import Menu from 'primevue/menu'
import Paginator from 'primevue/paginator'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useUserStore } from '@/stores/user'
import { useProductsPageData } from './composables/useProductsPageData'
import { useProductsPageActions } from './composables/useProductsPageActions'
import { formatCurrency } from '@/utils/currency'
import ProductPricingTypeTag from './components/ProductPricingTypeTag.vue'
import ProductPlansPriceTable from './components/ProductPlansPriceTable.vue'
import ProductCreateDrawer from './components/ProductCreateDrawer.vue'
import PriceImportDialog from './components/PriceImportDialog.vue'
import type { ProductDto } from '@/entities/catalog'

const { t } = useI18n()
const router = useRouter()
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

// Filter options
const pricingTypeOptions = computed(() => [
  { value: 'fixed', label: t('catalog.products.pricingType.fixed') },
  { value: 'tiered', label: t('catalog.products.pricingType.tiered') },
  { value: 'per_minute', label: t('catalog.products.pricingType.per_minute') },
  { value: 'package', label: t('catalog.products.pricingType.package') },
  { value: 'custom', label: t('catalog.products.pricingType.custom') },
])

const statusOptions = computed(() => [
  { label: t('catalog.products.page.filters.statusAll'), value: null },
  { label: t('catalog.products.page.filters.statusActive'), value: true },
  { label: t('catalog.products.page.filters.statusArchived'), value: false },
])

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

defineExpose({ canWrite, openCreateDrawer, toggleImportMenu, importMenuRef, importMenuItems })
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

.products-page__search {
  min-width: 240px;
}

.products-page__filter-select {
  min-width: 150px;
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
}

.products-page__code {
  font-family: $font-family-mono;
  font-size: $font-size-xs;
  color: $surface-600;
}

.products-page__name-link {
  color: $primary-color;
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
