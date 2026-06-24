<template>
  <div
    class="deal-page-v2"
    :class="{
      'deal-page-v2--mobile': isMobile,
      'deal-page-v2--tablet': isTablet,
    }"
  >
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
      <!-- Mobile top bar (< 768px) -->
      <div v-if="isMobile" class="deal-page-v2__mobile-bar">
        <Button icon="pi pi-arrow-left" text @click="router.back()" />
        <SelectButton
          v-model="mobileView"
          :options="mobileViewOptions"
          option-label="label"
          option-value="value"
          size="small"
          class="deal-page-v2__mobile-switch"
        />
        <span class="deal-page-v2__spacer" />
      </div>

      <!-- Tablet top bar (768–1023px) — shown only outside info-panel drawer -->
      <div v-else-if="isTablet" class="deal-page-v2__tablet-bar">
        <Button icon="pi pi-arrow-left" text @click="router.back()" />
        <Button
          icon="pi pi-info-circle"
          text
          :label="t('sales.deal.page.infoPanel')"
          @click="infoPanelOpen = true"
        />
        <span class="deal-page-v2__tablet-title text-truncate">{{ deal.title }}</span>
        <span class="deal-page-v2__spacer" />
      </div>

      <!-- Left panel: info header + tabs (hidden on tablet/mobile, shown in drawer) -->
      <div
        v-if="!isTablet && !isMobile"
        class="deal-page-v2__left"
      >
        <DealInfoPanel
          ref="infoPanelRef"
          :deal="deal"
          :stages="allStages"
          :users-list="usersList"
          :days-in-stage="daysInStage"
          :next-task="deal.next_task ?? null"
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
          @remove-product="onRemoveProduct"
          @remove-contact="onRemoveContact"
          @contacts-updated="onContactsUpdated"
          @collapse-all-groups="onCollapseAll"
          @expand-all-groups="onExpandAll"
          @scroll-to-feed-type="onScrollToFeedType"
          @reload-deal="reloadSilent"
        />
      </div>

      <!-- Tablet: Left panel inside Drawer -->
      <Drawer
        v-if="isTablet"
        v-model:visible="infoPanelOpen"
        position="left"
        :style="{ width: '380px' }"
        :modal="true"
        :header="deal.title"
      >
        <DealInfoPanel
          ref="infoPanelTabletRef"
          :deal="deal"
          :stages="allStages"
          :users-list="usersList"
          :days-in-stage="daysInStage"
          :next-task="deal.next_task ?? null"
          :products="dealProductsComposable.products.value"
          :products-loading="dealProductsComposable.loading.value"
          :updating-id="dealProductsComposable.updatingId.value"
          :deleting-id="dealProductsComposable.deletingId.value"
          :contacts="dealContactsComposable.contacts.value"
          :removing-contact-id="dealContactsComposable.removingId.value"
          :history="history"
          :activities="activitiesComposable.activities.value"
          @back="infoPanelOpen = false"
          @open-move-dialog="openMoveDialog"
          @open-move-dialog-with-stage="openMoveDialogWithStage"
          @deal-updated="updateDealLocal"
          @deal-deleted="onDealDeleted"
          @deal-archived="onDealArchived"
          @open-add-product="addProductDialogOpen = true"
          @open-add-contact="addContactDialogOpen = true"
          @remove-product="onRemoveProduct"
          @remove-contact="onRemoveContact"
          @contacts-updated="onContactsUpdated"
          @collapse-all-groups="onCollapseAll"
          @expand-all-groups="onExpandAll"
          @scroll-to-feed-type="onScrollToFeedType"
          @reload-deal="reloadSilent"
        />
      </Drawer>

      <!-- Mobile: Info panel (shown when mobileView === 'info') -->
      <div
        v-if="isMobile && mobileView === 'info'"
        class="deal-page-v2__left deal-page-v2__left--mobile-active"
      >
        <DealInfoPanel
          ref="infoPanelMobileRef"
          :deal="deal"
          :stages="allStages"
          :users-list="usersList"
          :days-in-stage="daysInStage"
          :next-task="deal.next_task ?? null"
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
          @remove-product="onRemoveProduct"
          @remove-contact="onRemoveContact"
          @contacts-updated="onContactsUpdated"
          @collapse-all-groups="onCollapseAll"
          @expand-all-groups="onExpandAll"
          @scroll-to-feed-type="onScrollToFeedType"
          @reload-deal="reloadSilent"
        />
      </div>

      <!-- Right panel: feed + open tasks list + composer -->
      <div
        v-if="!isMobile || mobileView === 'feed'"
        class="deal-page-v2__right"
        :class="{ 'deal-page-v2__right--mobile-active': isMobile }"
      >
        <DealFeed
          ref="dealFeedRef"
          :deal-id="deal.id"
          :pipeline-id="deal.pipeline?.id ?? null"
          :feed="feedComposable"
          :key-actions="deal.key_actions"
          class="deal-page-v2__feed"
          @open-composer-tab="onOpenComposerTab"
        />
        <!-- Open tasks list — above composer, AMO-style -->
        <OpenTasksList
          :tasks="feedComposable.openTasks.value"
          target-type="deal"
          :target-id="deal.id"
          :users-list="usersList"
          @completed="onTaskCompleted"
          @deleted="onTaskDeleted"
          @updated="onTaskUpdated"
        />
        <DealComposer
          ref="composerRef"
          :deal-id="deal.id"
          :users-list="usersList"
          :initial-tab="composerInitialTab"
          :class="{ 'deal-page-v2__composer--sticky': isMobile }"
          @created="onActivityCreated"
        />
      </div>
    </template>

    <!-- ── Global dialogs ────────────────────────────────────────────────────── -->
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

  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Drawer from 'primevue/drawer'
import SelectButton from 'primevue/selectbutton'
import DealInfoPanel from './components/DealInfoPanel.vue'
import DealFeed from './components/DealFeed.vue'
import DealComposer from './components/DealComposer.vue'
import DealAddProductDialog from './components/DealAddProductDialog.vue'
import DealAddContactDialog from './components/DealAddContactDialog.vue'
import MoveDealDialog from './components/MoveDealDialog.vue'
import OpenTasksList from '@/components/crm/entity/OpenTasksList.vue'
import { useDealPage } from './composables/useDealPage'
import { useDealProducts } from './composables/useDealProducts'
import { useDealContacts } from './composables/useDealContacts'
import { useDealHistory } from './composables/useDealHistory'
import { useDealActivities } from './composables/useDealActivities'
import { useDealActions } from './composables/useDealActions'
import { useDealFeed } from './composables/useDealFeed'
import { useBreakpoints } from '@/composables/useBreakpoints'
import { useSalesStore } from '@/stores/salesStore'
import { salesApi } from '@/api/sales'
import { usersApi } from '@/api/users'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import type { DealDto, DealProductDto, DealContactDto, PipelineStageDto, KeyActionType } from '@/entities/sales'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const salesStore = useSalesStore()

// ── Breakpoints ────────────────────────────────────────────────────────────────

const { isTablet, isMobile } = useBreakpoints()

// ── Responsive state ───────────────────────────────────────────────────────────

const infoPanelOpen = ref(false)
const mobileView = ref<'info' | 'feed'>('info')

const mobileViewOptions = computed(() => [
  { value: 'info', label: t('sales.deal.page.infoView') },
  { value: 'feed', label: t('sales.deal.page.feedView') },
])

// ── Panel refs ─────────────────────────────────────────────────────────────────

const infoPanelRef = ref<InstanceType<typeof DealInfoPanel> | null>(null)
const infoPanelTabletRef = ref<InstanceType<typeof DealInfoPanel> | null>(null)
const infoPanelMobileRef = ref<InstanceType<typeof DealInfoPanel> | null>(null)

function onCollapseAll() {
  infoPanelRef.value?.onCollapseAll()
  infoPanelTabletRef.value?.onCollapseAll()
  infoPanelMobileRef.value?.onCollapseAll()
}

function onExpandAll() {
  infoPanelRef.value?.onExpandAll()
  infoPanelTabletRef.value?.onExpandAll()
  infoPanelMobileRef.value?.onExpandAll()
}

// ── Main deal data ─────────────────────────────────────────────────────────────────

const { dealId, deal, loading, error, load, reloadSilent, updateDealLocal } = useDealPage()

// ── Sub-resources ──────────────────────────────────────────────────────────────────

const dealProductsComposable = useDealProducts(() => dealId.value)
const dealContactsComposable = useDealContacts(() => dealId.value)
const dealHistoryComposable = useDealHistory(() => dealId.value)
const { history } = dealHistoryComposable
const activitiesComposable = useDealActivities(() => dealId.value)

// ── Feed ───────────────────────────────────────────────────────────────────────────

const feedComposable = useDealFeed(
  () => dealId.value,
  () => deal.value?.created_at ?? null,
)

// ── Composer + Feed ref ────────────────────────────────────────────────────────────

const dealFeedRef = ref<InstanceType<typeof DealFeed> | null>(null)
const composerRef = ref<InstanceType<typeof DealComposer> | null>(null)
const composerInitialTab = ref<ActivityKind>('note')

function onOpenComposerTab(tab: ActivityKind) {
  composerInitialTab.value = tab
  composerRef.value?.setTab(tab)
}

function onScrollToFeedType(type: KeyActionType) {
  dealFeedRef.value?.scrollToFeedItem(type)
}

function onActivityCreated(activity: ActivityDto) {
  feedComposable.prependLocal(activity)
  // Scroll feed to bottom so new event is visible
  dealFeedRef.value?.scrollToBottom()
}

// Open-task complete/delete/update handlers (wired to OpenTasksList)
function onTaskCompleted(activity: ActivityDto) {
  feedComposable.updateActivityLocal(activity)
}

function onTaskUpdated(activity: ActivityDto) {
  // Inline picker edits (kind/date/responsible/title) — sync the feed item so the
  // displayed value reflects the server response without a reload.
  feedComposable.updateActivityLocal(activity)
}

async function onTaskDeleted(activityId: number) {
  try {
    // API-backed delete (removes the row locally on success). The local-only
    // sibling removeActivityLocal would resurrect the task on reload.
    await feedComposable.deleteActivity(activityId)
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ── Actions ────────────────────────────────────────────────────────────────────────

const { moveDialogOpen, openMoveDialog } = useDealActions(
  () => dealId.value,
  (updated) => { updateDealLocal(updated) },
)

// ── Pipeline stages ────────────────────────────────────────────────────────────────

const allStagesResource = useAsyncResource<PipelineStageDto[]>(() => [])
const allStages = computed(() => allStagesResource.data.value)

// ── Users list ─────────────────────────────────────────────────────────────────────

const usersListResource = useAsyncResource<{ id: number; name: string }[]>(() => [])
const usersList = computed(() => usersListResource.data.value)

// ── Days in stage ──────────────────────────────────────────────────────────────────

const daysInStage = computed((): number => {
  if (!deal.value) return 0
  // Prefer backend-computed value if available
  if (deal.value.days_in_stage != null) return deal.value.days_in_stage
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

// ── Dialogs ────────────────────────────────────────────────────────────────────────

const addProductDialogOpen = ref(false)
const addContactDialogOpen = ref(false)

// ── Event handlers ─────────────────────────────────────────────────────────────────

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

function onContactsUpdated(contacts: DealContactDto[]) {
  dealContactsComposable.setContacts(contacts)
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

// ── Bootstrap ──────────────────────────────────────────────────────────────────────

onMounted(async () => {
  if (salesStore.lostReasonsCache.length === 0) {
    try {
      const reasons = await salesApi.getLostReasons()
      salesStore.cacheLostReasons(reasons)
    } catch {
      // non-critical
    }
  }

  // The deal load rethrows on 403/404 (foreign or missing deal). The resource
  // already records error.value (the "Сделка не найдена / Нет доступа" screen
  // renders from it), so swallow the rejection here — otherwise it surfaces as an
  // "Unhandled error during execution of mounted hook" and trips error monitoring
  // with false positives (audit N2).
  try {
    await load()
  } catch {
    // error state handled by the resource → error template
  }

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
// ── Global scrollbar hide for this page ─────────────────────────────────────
:deep(*) {
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

.deal-page-v2 {
  display: flex;
  height: 100vh;
  overflow: hidden;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;

  // ── Left panel: fixed 420px at ALL desktop widths ─────────────────────────

  &__left {
    width: 420px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    border-right: 1px solid var(--p-surface-200);
    background: var(--p-card-background);

    // Correct dark-theme idiom (no :global())
    .app-dark & {
      border-right-color: var(--p-surface-700);
    }

    // Hidden scrollbar
    scrollbar-width: none;
    -ms-overflow-style: none;

    &::-webkit-scrollbar {
      width: 0;
      height: 0;
      display: none;
    }
  }

  // ── Right panel: --c-feed background ─────────────────────────────────────
  // Light: --c-feed = #F1F2F3 = surfacePalette[100] = var(--p-surface-100).
  // Dark:  --c-feed spec = #1f2021 (between surface-50 #272829 and surface-0 #000).
  //        Closest available token: var(--p-surface-50) in dark = #272829.
  //        No exact token exists — using surface-50 as approved approximation.
  &__right {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    background: var(--p-surface-100);
    overflow: hidden;

    .app-dark & {
      background: var(--p-surface-50);
    }
  }

  // ── Tablet (768–1023px) ────────────────────────────────────────────────────

  &--tablet {
    flex-direction: column;

    .deal-page-v2__right {
      width: 100%;
      flex: 1;
    }
  }

  // ── Mobile (<768px) ────────────────────────────────────────────────────────

  &--mobile {
    flex-direction: column;
    height: 100svh;
  }

  &__left--mobile-active {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    border-right: none;

    scrollbar-width: none;
    -ms-overflow-style: none;

    &::-webkit-scrollbar {
      width: 0;
      height: 0;
      display: none;
    }
  }

  &__right--mobile-active {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  // ── Mobile / tablet bars ───────────────────────────────────────────────────

  &__mobile-bar,
  &__tablet-bar {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-2 $space-3;
    border-bottom: 1px solid var(--p-surface-200);
    background: var(--p-card-background);
    flex-shrink: 0;

    // Correct dark-theme idiom (no :global())
    .app-dark & {
      border-bottom-color: var(--p-surface-700);
    }
  }

  &__mobile-switch {
    flex-shrink: 0;
  }

  &__tablet-title {
    flex: 1;
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-800;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__spacer {
    flex: 1;
  }

  // ── Composer sticky on mobile ──────────────────────────────────────────────

  &__composer--sticky {
    position: sticky;
    bottom: 0;
    z-index: 10;
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
    font-size: $font-size-icon-2xl;
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
