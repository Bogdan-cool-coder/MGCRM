<template>
  <div class="deal-page">
    <!-- Loading skeleton -->
    <template v-if="loading">
      <div class="deal-page__skeleton">
        <Skeleton height="32px" class="mb-3" />
        <div class="row g-4">
          <div class="col-lg-9">
            <Skeleton height="200px" class="mb-3" />
            <Skeleton height="120px" />
          </div>
          <div class="col-lg-3">
            <Skeleton height="200px" />
          </div>
        </div>
      </div>
    </template>

    <!-- Error / not found -->
    <template v-else-if="error || !deal">
      <div class="deal-page__error">
        <i class="pi pi-exclamation-triangle deal-page__error-icon" />
        <p class="deal-page__error-text">{{ t('sales.deal.page.errors.notFound') }}</p>
        <Button
          icon="pi pi-arrow-left"
          :label="t('sales.deal.page.errors.backToDeals')"
          severity="secondary"
          outlined
          @click="router.push('/deals')"
        />
      </div>
    </template>

    <!-- Main content -->
    <template v-else>
      <PageHeader
        :title="deal.title"
        :subtitle="dealSubtitle"
        icon="pi pi-briefcase"
      >
        <template #actions>
          <Button
            icon="pi pi-arrow-left"
            :label="t('sales.deal.page.back')"
            severity="secondary"
            text
            @click="router.back()"
          />
          <Button
            icon="pi pi-arrows-h"
            :label="t('sales.deal.page.changeStage')"
            severity="secondary"
            outlined
            @click="openMoveDialog"
          />
          <Button
            icon="pi pi-ellipsis-v"
            text
            severity="secondary"
            @click="toggleMenu"
          />
          <Menu ref="dealMenu" :model="dealMenuItems" popup />
        </template>
      </PageHeader>

      <div class="deal-page__content">
        <div class="row g-4">
          <!-- Left: Tabs -->
          <div class="col-lg-9">
            <Tabs v-model:value="activeTab" class="deal-page__tabs">
              <TabList>
                <Tab value="overview">{{ t('sales.deal.page.tabs.overview') }}</Tab>
                <Tab value="contacts">{{ t('sales.deal.page.tabs.contacts') }}</Tab>
                <Tab value="history">{{ t('sales.deal.page.tabs.history') }}</Tab>
              </TabList>
              <TabPanels>
                <!-- Overview tab -->
                <TabPanel value="overview">
                  <div class="deal-page__tab-content">
                    <DealOverviewTab
                      :deal="deal"
                      :is-saving="isSaving"
                      @save="onFieldSave"
                    />
                    <DealProductsCard
                      :items="products"
                      :currency="deal.currency"
                      :loading="productsLoading"
                      :updating-id="updatingId"
                      :deleting-id="deletingId"
                      class="deal-page__products-card"
                      @add-product="addProductDialogOpen = true"
                      @update-item="onUpdateProduct"
                      @remove-item="onRemoveProduct"
                    />
                  </div>
                </TabPanel>

                <!-- Contacts tab -->
                <TabPanel value="contacts">
                  <div class="deal-page__tab-content">
                    <DealContactsTab
                      :contacts="contacts"
                      :removing-id="removingId"
                      @add-contact="addContactDialogOpen = true"
                      @remove-contact="onRemoveContact"
                    />
                  </div>
                </TabPanel>

                <!-- History tab -->
                <TabPanel value="history">
                  <div class="deal-page__tab-content">
                    <DealStageHistoryTab :history="history" />
                  </div>
                </TabPanel>
              </TabPanels>
            </Tabs>
          </div>

          <!-- Right rail -->
          <div class="col-lg-3">
            <DealRightRail :deal="deal" />
          </div>
        </div>
      </div>

      <!-- Move dialog -->
      <MoveDealDialog
        v-model="moveDialogOpen"
        :deal="deal"
        :stages="allStages"
        :lost-reasons="salesStore.lostReasonsCache"
        @moved="onDealMoved"
      />

      <!-- Add product dialog -->
      <DealAddProductDialog
        v-model="addProductDialogOpen"
        :deal-id="deal.id"
        :currency="deal.currency"
        :on-add="addProductProxy"
        @added="onProductAdded"
      />

      <!-- Add contact dialog -->
      <DealAddContactDialog
        v-model="addContactDialogOpen"
        :deal-id="deal.id"
        :company-id="deal.company.id"
        :on-add="addContactProxy"
        @added="onContactAdded"
      />
    </template>

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Skeleton from 'primevue/skeleton'
import Menu from 'primevue/menu'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import DealOverviewTab from './components/DealOverviewTab.vue'
import DealProductsCard from './components/DealProductsCard.vue'
import DealContactsTab from './components/DealContactsTab.vue'
import DealStageHistoryTab from './components/DealStageHistoryTab.vue'
import DealRightRail from './components/DealRightRail.vue'
import DealAddProductDialog from './components/DealAddProductDialog.vue'
import DealAddContactDialog from './components/DealAddContactDialog.vue'
import MoveDealDialog from '../DealsPage/components/MoveDealDialog.vue'
import { useDealPage } from './composables/useDealPage'
import { useDealProducts } from './composables/useDealProducts'
import { useDealContacts } from './composables/useDealContacts'
import { useDealHistory } from './composables/useDealHistory'
import { useDealActions } from './composables/useDealActions'
import { useSalesStore } from '@/stores/salesStore'
import { salesApi } from '@/api/sales'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { formatCurrency } from '@/utils/currency'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto, DealProductDto, PipelineStageDto } from '@/entities/sales'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const salesStore = useSalesStore()

// ── Tab state ──────────────────────────────────────────────────────────────────

const activeTab = ref('overview')

// ── Main deal data ─────────────────────────────────────────────────────────────

const { dealId, deal, loading, error, load, updateDealLocal } = useDealPage()

// ── Products ───────────────────────────────────────────────────────────────────

const dealProductsComposable = useDealProducts(() => dealId.value)
const { products, loading: productsLoading, updatingId, deletingId } = dealProductsComposable

// ── Contacts ───────────────────────────────────────────────────────────────────

const dealContactsComposable = useDealContacts(() => dealId.value)
const { contacts, removingId } = dealContactsComposable

// ── History ────────────────────────────────────────────────────────────────────

const dealHistoryComposable = useDealHistory(() => dealId.value)
const { history } = dealHistoryComposable

// ── Actions ────────────────────────────────────────────────────────────────────

const { isSaving, moveDialogOpen, patchField, deleteDeal, openMoveDialog } =
  useDealActions(() => dealId.value, (updated) => {
    updateDealLocal(updated)
  })

// ── Pipeline stages ────────────────────────────────────────────────────────────

const allStagesResource = useAsyncResource<PipelineStageDto[]>(() => [])
const allStages = computed(() => allStagesResource.data.value)

// ── Dialogs ────────────────────────────────────────────────────────────────────

const addProductDialogOpen = ref(false)
const addContactDialogOpen = ref(false)

// ── Menu ───────────────────────────────────────────────────────────────────────

const dealMenu = ref<InstanceType<typeof Menu> | null>(null)

function toggleMenu(event: MouseEvent) {
  dealMenu.value?.toggle(event)
}

const dealMenuItems = computed(() => [
  {
    label: t('sales.deals.page.actions.delete'),
    icon: 'pi pi-trash',
    command: confirmDelete,
  },
])

// ── Computed subtitle ──────────────────────────────────────────────────────────

const dealSubtitle = computed(() => {
  if (!deal.value) return ''
  const parts = [
    deal.value.company.name,
    deal.value.stage.name,
    formatCurrency(deal.value.amount, deal.value.currency),
  ]
  return parts.join(' · ')
})

// ── Handlers ───────────────────────────────────────────────────────────────────

async function onFieldSave(field: string, value: unknown) {
  try {
    await patchField(field, value)
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

async function onUpdateProduct(id: number, payload: { quantity?: number; unit_price?: number }) {
  try {
    await dealProductsComposable.update(id, payload)
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

async function onRemoveProduct(id: number) {
  confirm.require({
    header: t('sales.deal.page.products.addDialog.removeConfirm'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await dealProductsComposable.remove(id)
        // Recalc deal amount
        if (deal.value) {
          updateDealLocal({ amount: products.value.reduce((s, p) => s + p.amount, 0) })
        }
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 3000,
        })
      }
    },
  })
}

function onProductAdded(product: DealProductDto) {
  // dealProductsComposable.add already updates list
  // Update deal amount locally
  if (deal.value) {
    updateDealLocal({ amount: deal.value.amount + product.amount })
  }
}

async function onRemoveContact(contactId: number) {
  try {
    await dealContactsComposable.remove(contactId)
    toast.add({
      severity: 'success',
      summary: t('sales.deal.page.contacts.addDialog.removeSuccess'),
      life: 3000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

function onContactAdded() {
  // list updated by composable
}

function onDealMoved(updated: DealDto) {
  updateDealLocal(updated)
  // Reload history
  void dealHistoryComposable.load()
}

function confirmDelete() {
  confirm.require({
    header: t('sales.deals.page.actions.deleteConfirm'),
    message: t('sales.deals.page.actions.deleteDetail'),
    acceptLabel: t('sales.deals.page.actions.deleteAccept'),
    rejectLabel: t('sales.deals.page.actions.deleteReject'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await deleteDeal()
        toast.add({ severity: 'success', summary: t('sales.deals.page.actions.deleteSuccess'), life: 3000 })
        void router.push('/deals')
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    },
  })
}

// ── Proxy functions matching dialog :on-add signatures ────────────────────────

function addProductProxy(
  _dealId: number,
  payload: { product_id: number; plan_id?: number | null; quantity: number; unit_price?: number | null },
) {
  return dealProductsComposable.add(payload)
}

function addContactProxy(
  _dealId: number,
  payload: { contact_id: number; is_primary: boolean },
) {
  return dealContactsComposable.add(payload)
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────

onMounted(async () => {
  // Load lost reasons if not cached
  if (salesStore.lostReasonsCache.length === 0) {
    try {
      const reasons = await salesApi.getLostReasons()
      salesStore.cacheLostReasons(reasons)
    } catch {
      // non-critical
    }
  }

  await load()

  if (deal.value) {
    // Load stages for the pipeline
    const pipelineId = deal.value.pipeline.id
    if (salesStore.getCachedStages(pipelineId).length === 0) {
      await allStagesResource.run(() => salesApi.getPipelineStages(pipelineId), {
        commit: (stages) => {
          allStagesResource.data.value = stages
          salesStore.cacheStages(pipelineId, stages)
        },
      })
    } else {
      allStagesResource.data.value = salesStore.getCachedStages(pipelineId)
    }

    // Load sub-resources
    await Promise.all([
      dealProductsComposable.load(),
      dealContactsComposable.load(),
      dealHistoryComposable.load(),
    ])
  }
})
</script>

<style lang="scss" scoped>
.deal-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.deal-page__skeleton {
  padding: $space-4 $space-6;
}

.deal-page__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-4;
  padding: $space-8;
}

.deal-page__error-icon {
  font-size: 3rem;
  color: $surface-400;
}

.deal-page__error-text {
  font-size: $font-size-lg;
  color: $surface-500;
  margin: 0;
}

.deal-page__content {
  flex: 1;
  overflow-y: auto;
  padding: $space-4 $space-6;
}

.deal-page__tabs {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deal-page__tab-content {
  padding: $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.deal-page__products-card {
  margin-top: $space-2;
}
</style>
