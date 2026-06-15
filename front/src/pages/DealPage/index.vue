<template>
  <div class="deal-page-v2">
    <!-- ── Loading skeleton ──────────────────────────────────────────────── -->
    <template v-if="loading">
      <div class="deal-page-v2__left">
        <Skeleton height="180px" />
        <div class="p-3">
          <Skeleton height="32px" class="mb-2" />
          <Skeleton height="60px" class="mb-2" />
          <Skeleton height="120px" class="mb-2" />
          <Skeleton height="80px" />
        </div>
      </div>
      <div class="deal-page-v2__right">
        <div class="p-3">
          <Skeleton height="44px" class="mb-3" />
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="60px" />
        </div>
      </div>
    </template>

    <!-- ── Error / Not Found ─────────────────────────────────────────────── -->
    <template v-else-if="error || !deal">
      <div class="deal-page-v2__error">
        <i class="pi pi-exclamation-triangle deal-page-v2__error-icon" />
        <p class="deal-page-v2__error-title">{{ t('sales.deal.page.errors.notFound') }}</p>
        <p class="deal-page-v2__error-hint">{{ t('sales.deal.page.errors.noAccess') }}</p>
        <Button
          icon="pi pi-arrow-left"
          :label="t('sales.deal.page.errors.backToDeals')"
          severity="secondary"
          outlined
          @click="router.push('/deals')"
        />
      </div>
    </template>

    <!-- ── Main content ──────────────────────────────────────────────────── -->
    <template v-else>
      <!-- Left panel: info header + tabs -->
      <div class="deal-page-v2__left">
        <DealInfoPanel
          :deal="deal"
          :stages="allStages"
          :users-list="usersList"
          :days-in-stage="daysInStage"
          :products="dealProductsComposable.products.value"
          :products-loading="dealProductsComposable.loading.value"
          :updating-id="dealProductsComposable.updatingId.value"
          :deleting-id="dealProductsComposable.deletingId.value"
          :contacts="dealContactsComposable.contacts.value"
          :removing-contact-id="dealContactsComposable.removingId.value"
          :history="history"
          :activities="activitiesComposable.activities.value"
          @back="router.back()"
          @open-move-dialog="openMoveDialog"
          @open-move-dialog-with-stage="openMoveDialogWithStage"
          @deal-updated="updateDealLocal"
          @deal-deleted="onDealDeleted"
          @deal-archived="onDealArchived"
          @open-add-product="addProductDialogOpen = true"
          @open-add-contact="addContactDialogOpen = true"
          @update-product="onUpdateProduct"
          @remove-product="onRemoveProduct"
          @remove-contact="onRemoveContact"
          @amount-changed="onAmountChanged"
        />
      </div>

      <!-- Right panel: feed + composer -->
      <div class="deal-page-v2__right">
        <DealFeed
          :deal-id="deal.id"
          :feed="feedComposable"
          class="deal-page-v2__feed"
          @open-composer-tab="onOpenComposerTab"
        />
        <DealComposer
          ref="composerRef"
          :deal-id="deal.id"
          :users-list="usersList"
          :initial-tab="composerInitialTab"
          @created="onActivityCreated"
        />
      </div>
    </template>

    <!-- ── Global dialogs ────────────────────────────────────────────────── -->
    <MoveDealDialog
      v-if="deal"
      v-model="moveDialogOpen"
      :deal="deal"
      :stages="allStages"
      :lost-reasons="salesStore.lostReasonsCache"
      @moved="onDealMoved"
    />

    <DealAddProductDialog
      v-if="deal"
      v-model="addProductDialogOpen"
      :deal-id="deal.id"
      :currency="deal.currency"
      :on-add="addProductProxy"
      @added="onProductAdded"
    />

    <DealAddContactDialog
      v-if="deal"
      v-model="addContactDialogOpen"
      :deal-id="deal.id"
      :company-id="deal.company.id"
      :on-add="addContactProxy"
      @added="onContactAdded"
    />

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import DealInfoPanel from './components/DealInfoPanel.vue'
import DealFeed from './components/DealFeed.vue'
import DealComposer from './components/DealComposer.vue'
import DealAddProductDialog from './components/DealAddProductDialog.vue'
import DealAddContactDialog from './components/DealAddContactDialog.vue'
import MoveDealDialog from '../DealsPage/components/MoveDealDialog.vue'
import { useDealPage } from './composables/useDealPage'
import { useDealProducts } from './composables/useDealProducts'
import { useDealContacts } from './composables/useDealContacts'
import { useDealHistory } from './composables/useDealHistory'
import { useDealActivities } from './composables/useDealActivities'
import { useDealActions } from './composables/useDealActions'
import { useDealFeed } from './composables/useDealFeed'
import { useSalesStore } from '@/stores/salesStore'
import { salesApi } from '@/api/sales'
import { usersApi } from '@/api/users'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import type { DealDto, DealProductDto, PipelineStageDto } from '@/entities/sales'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

const { t } = useI18n()
const router = useRouter()
const salesStore = useSalesStore()

// ── Main deal data ─────────────────────────────────────────────────────────────

const { dealId, deal, loading, error, load, updateDealLocal } = useDealPage()

// ── Sub-resources ──────────────────────────────────────────────────────────────

const dealProductsComposable = useDealProducts(() => dealId.value)
const dealContactsComposable = useDealContacts(() => dealId.value)
const dealHistoryComposable = useDealHistory(() => dealId.value)
const { history } = dealHistoryComposable
const activitiesComposable = useDealActivities(() => dealId.value)

// ── Feed ───────────────────────────────────────────────────────────────────────

const feedComposable = useDealFeed(
  () => dealId.value,
  () => deal.value?.created_at ?? null,
)

// ── Composer ───────────────────────────────────────────────────────────────────

const composerRef = ref<InstanceType<typeof DealComposer> | null>(null)
const composerInitialTab = ref<ActivityKind>('note')

function onOpenComposerTab(tab: ActivityKind) {
  composerInitialTab.value = tab
  composerRef.value?.setTab(tab)
}

function onActivityCreated(activity: ActivityDto) {
  feedComposable.prependLocal(activity)
}

// ── Actions ────────────────────────────────────────────────────────────────────

const { moveDialogOpen, openMoveDialog } = useDealActions(
  () => dealId.value,
  (updated) => { updateDealLocal(updated) },
)

// ── Pipeline stages ────────────────────────────────────────────────────────────

const allStagesResource = useAsyncResource<PipelineStageDto[]>(() => [])
const allStages = computed(() => allStagesResource.data.value)

// ── Users list ─────────────────────────────────────────────────────────────────

const usersListResource = useAsyncResource<{ id: number; name: string }[]>(() => [])
const usersList = computed(() => usersListResource.data.value)

// ── Days in stage ──────────────────────────────────────────────────────────────

const daysInStage = computed((): number => {
  if (!deal.value) return 0
  const historyArr = history.value
  const relevant = historyArr.find((h) => h.to_stage?.id === deal.value!.stage.id)
  const fromDate = relevant
    ? new Date(relevant.created_at)
    : new Date(deal.value.created_at)
  const diff = Date.now() - fromDate.getTime()
  return Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)))
})

// ── Move dialog with stage preselect ──────────────────────────────────────────

function openMoveDialogWithStage() {
  moveDialogOpen.value = true
}

// ── Dialogs ────────────────────────────────────────────────────────────────────

const addProductDialogOpen = ref(false)
const addContactDialogOpen = ref(false)

// ── Event handlers ─────────────────────────────────────────────────────────────

function onDealMoved(updated: DealDto) {
  updateDealLocal(updated)
  void dealHistoryComposable.load()
}

function onDealDeleted() {
  // navigated by DealInfoHeader
}

function onDealArchived() {
  // navigated by DealInfoHeader
}

function onProductAdded(product: DealProductDto) {
  if (deal.value) {
    updateDealLocal({ amount: deal.value.amount + product.amount })
  }
}

function onContactAdded() {
  // list updated by composable
}

function onAmountChanged(newTotal: number) {
  updateDealLocal({ amount: newTotal })
}

// Product events forwarded from DealInfoPanel → DealTabMain → DealProductsGroup
async function onUpdateProduct(id: number, payload: { quantity?: number; unit_price?: number }) {
  await dealProductsComposable.update(id, payload)
  // Recalculate amount
  if (deal.value) {
    const total = dealProductsComposable.products.value.reduce((s, p) => s + p.amount, 0)
    updateDealLocal({ amount: total })
  }
}

async function onRemoveProduct(id: number) {
  await dealProductsComposable.remove(id)
  if (deal.value) {
    const total = dealProductsComposable.products.value.reduce((s, p) => s + p.amount, 0)
    updateDealLocal({ amount: total })
  }
}

async function onRemoveContact(contactId: number) {
  await dealContactsComposable.remove(contactId)
}

// ── Proxy fns for dialog :on-add ──────────────────────────────────────────────

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

    await Promise.all([
      dealProductsComposable.load(),
      dealContactsComposable.load(),
      dealHistoryComposable.load(),
      activitiesComposable.load(),
      feedComposable.load(),
    ])

    try {
      await usersListResource.run(async () => {
        const users = await usersApi.getUsers()
        return users.map((u) => ({ id: u.id, name: u.full_name }))
      })
    } catch {
      // non-critical
    }
  }
})
</script>

<style lang="scss" scoped>
.deal-page-v2 {
  display: flex;
  height: 100vh;
  overflow: hidden;
  min-width: 1100px;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;

  &__left {
    width: 290px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    border-right: 1px solid var(--p-surface-200);
    background: var(--p-card-background);

    .app-dark & {
      border-right-color: var(--p-surface-700);
    }
  }

  &__right {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    background: var(--p-surface-50);
    overflow: hidden;

    .app-dark & {
      background: var(--p-surface-900);
    }
  }

  // ── Full-page states ────────────────────────────────────────────────────────

  &__error {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: $space-3;
    padding: $space-8;
    text-align: center;
  }

  &__error-icon {
    font-size: 3rem;
    color: var(--p-red-400);
  }

  &__error-title {
    font-size: $font-size-lg;
    font-weight: $font-weight-semibold;
    margin: 0;
    color: $surface-800;
  }

  &__error-hint {
    color: $surface-500;
    font-size: $font-size-sm;
    margin: 0;
  }

  // ── Feed (fills right panel above composer) ─────────────────────────────────

  &__feed {
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }
}
</style>
