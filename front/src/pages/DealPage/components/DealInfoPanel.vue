<template>
  <div class="deal-info-panel">
    <DealInfoHeader
      :deal="deal"
      :stages="stages"
      :users-list="usersList"
      :days-in-stage="daysInStage"
      :next-task="nextTask"
      @back="$emit('back')"
      @open-move-dialog="$emit('openMoveDialog')"
      @open-move-dialog-with-stage="(id) => $emit('openMoveDialogWithStage', id)"
      @deal-updated="(d) => $emit('dealUpdated', d)"
      @deal-deleted="$emit('dealDeleted')"
      @deal-archived="$emit('dealArchived')"
      @collapse-all-groups="onCollapseAll"
      @expand-all-groups="onExpandAll"
      @scroll-to-feed-type="(type) => $emit('scrollToFeedType', type)"
    />

    <DealInfoTabs class="deal-info-panel__tabs">
      <template #main>
        <DealTabMain
          :deal="deal"
          :days-in-stage="daysInStage"
          :products="products"
          :products-loading="productsLoading"
          :updating-id="updatingId"
          :deleting-id="deletingId"
          :contacts="contacts"
          :removing-contact-id="removingContactId"
          :users-list="usersList"
          :collapse-all-signal="collapseAllSignal"
          :expand-all-signal="expandAllSignal"
          @deal-updated="(updates) => $emit('dealUpdated', updates)"
          @open-add-product="$emit('openAddProduct')"
          @open-add-contact="$emit('openAddContact')"
          @remove-product="(id) => $emit('removeProduct', id)"
          @remove-contact="(id) => $emit('removeContact', id)"
          @contacts-updated="(list) => $emit('contactsUpdated', list)"
        />
      </template>

      <template #documents>
        <DealTabDocuments
          :deal-id="deal.id"
          @docs-count-changed="(n) => { docsCount = n }"
        />
      </template>

      <template #finances>
        <DealTabFinances
          :deal="deal"
          @deal-updated="(d) => $emit('dealUpdated', d)"
        />
      </template>

      <template #log>
        <EntityLogTab
          :log="entityLog"
          :metrics="dealMetrics"
        />
      </template>
    </DealInfoTabs>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DealInfoHeader from './DealInfoHeader.vue'
import DealInfoTabs from './DealInfoTabs.vue'
import DealTabMain from './DealTabMain.vue'
import DealTabDocuments from './DealTabDocuments.vue'
import DealTabFinances from './DealTabFinances.vue'
import EntityLogTab, { type LogMetric } from '@/components/crm/entity/EntityLogTab.vue'
import { useEntityLog } from '@/composables/crm/useEntityLog'
import type { DealDto, DealProductDto, DealContactDto, PipelineStageDto, DealStageHistoryDto, NextTaskDto, KeyActionType } from '@/entities/sales'
import type { ActivityDto } from '@/entities/activity'

interface MenuUser {
  id: number
  name: string
}

const props = defineProps<{
  deal: DealDto
  stages: PipelineStageDto[]
  usersList: MenuUser[]
  daysInStage: number
  nextTask: NextTaskDto | null
  products: DealProductDto[]
  productsLoading: boolean
  updatingId: number | null
  deletingId: number | null
  contacts: DealContactDto[]
  removingContactId: number | null
  history: DealStageHistoryDto[]
  activities: ActivityDto[]
}>()

const emit = defineEmits<{
  back: []
  openMoveDialog: []
  openMoveDialogWithStage: [stageId: number]
  dealUpdated: [updates: Partial<DealDto>]
  dealDeleted: []
  dealArchived: []
  openAddProduct: []
  openAddContact: []
  removeProduct: [id: number]
  removeContact: [contactId: number]
  contactsUpdated: [contacts: DealContactDto[]]
  collapseAllGroups: []
  expandAllGroups: []
  scrollToFeedType: [type: KeyActionType]
}>()

const { t } = useI18n()

// ── Entity log ────────────────────────────────────────────────────────────────

const entityLog = useEntityLog('deal', () => props.deal.id)

// Load on mount (deal ID is always available here)
entityLog.load()

// ── Compact metrics bar ───────────────────────────────────────────────────────

const daysInDeal = computed((): number => {
  const diff = Date.now() - new Date(props.deal.created_at).getTime()
  return Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)))
})

const lastActivityLabel = computed((): string => {
  const last = props.activities[0]
  if (!last) return '—'
  const dateRef = last.due_at ?? last.created_at
  const diffDays = Math.floor(
    (Date.now() - new Date(dateRef).getTime()) / (1000 * 60 * 60 * 24),
  )
  return `${diffDays} ${t('sales.deal.stats.daysAgoShort')}`
})

const dealMetrics = computed((): LogMetric[] => [
  { key: 'daysInDeal', label: t('sales.deal.stats.daysInDeal'), metricValue: daysInDeal.value },
  { key: 'daysInStage', label: t('sales.deal.stats.daysInStage'), metricValue: props.daysInStage },
  { key: 'activities', label: t('sales.deal.stats.activities'), metricValue: props.activities.length },
  { key: 'stageChanges', label: t('sales.deal.stats.stageChanges'), metricValue: props.history.length },
  { key: 'documents', label: t('sales.deal.stats.documents'), metricValue: docsCount.value },
  { key: 'lastActivity', label: t('sales.deal.stats.lastActivity'), metricValue: lastActivityLabel.value },
])

// ── Docs count ────────────────────────────────────────────────────────────────

// docsCount is managed internally: DealTabDocuments emits it
const docsCount = ref(0)

// ── Collapse / expand signals ─────────────────────────────────────────────────

const collapseAllSignal = ref(0)
const expandAllSignal = ref(0)

function onCollapseAll() {
  collapseAllSignal.value++
  emit('collapseAllGroups')
}

function onExpandAll() {
  expandAllSignal.value++
  emit('expandAllGroups')
}

defineExpose({ onCollapseAll, onExpandAll })
</script>

<style lang="scss" scoped>
.deal-info-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.deal-info-panel__tabs {
  flex: 1;
  overflow: hidden;
}
</style>
