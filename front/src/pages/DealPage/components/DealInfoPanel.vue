<template>
  <div class="deal-info-panel">
    <DealInfoHeader
      :deal="deal"
      :stages="stages"
      :users-list="usersList"
      :days-in-stage="daysInStage"
      @back="$emit('back')"
      @open-move-dialog="$emit('openMoveDialog')"
      @open-move-dialog-with-stage="(id) => $emit('openMoveDialogWithStage', id)"
      @deal-updated="(d) => $emit('dealUpdated', d)"
      @deal-deleted="$emit('dealDeleted')"
      @deal-archived="$emit('dealArchived')"
    />

    <DealInfoTabs class="deal-info-panel__tabs">
      <template #main>
        <DealTabMain
          :deal="deal"
          :products="products"
          :products-loading="productsLoading"
          :updating-id="updatingId"
          :deleting-id="deletingId"
          :contacts="contacts"
          :removing-contact-id="removingContactId"
          :users-list="usersList"
          @deal-updated="(updates) => $emit('dealUpdated', updates)"
          @open-add-product="$emit('openAddProduct')"
          @open-add-contact="$emit('openAddContact')"
          @update-product="(id, payload) => $emit('updateProduct', id, payload)"
          @remove-product="(id) => $emit('removeProduct', id)"
          @remove-contact="(id) => $emit('removeContact', id)"
          @amount-changed="(total) => $emit('amountChanged', total)"
        />
      </template>

      <template #documents>
        <DealTabDocuments
          :deal-id="deal.id"
          @docs-count-changed="(n) => { docsCount = n }"
        />
      </template>

      <template #finances>
        <DealTabFinances />
      </template>

      <template #stats>
        <DealTabStats
          :deal="deal"
          :history="history"
          :activities="activities"
          :documents-count="docsCount"
          :days-in-stage="daysInStage"
        />
      </template>
    </DealInfoTabs>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import DealInfoHeader from './DealInfoHeader.vue'
import DealInfoTabs from './DealInfoTabs.vue'
import DealTabMain from './DealTabMain.vue'
import DealTabDocuments from './DealTabDocuments.vue'
import DealTabFinances from './DealTabFinances.vue'
import DealTabStats from './DealTabStats.vue'
import type { DealDto, DealProductDto, DealContactDto, PipelineStageDto, DealStageHistoryDto } from '@/entities/sales'
import type { ActivityDto } from '@/entities/activity'

interface MenuUser {
  id: number
  name: string
}

defineProps<{
  deal: DealDto
  stages: PipelineStageDto[]
  usersList: MenuUser[]
  daysInStage: number
  products: DealProductDto[]
  productsLoading: boolean
  updatingId: number | null
  deletingId: number | null
  contacts: DealContactDto[]
  removingContactId: number | null
  history: DealStageHistoryDto[]
  activities: ActivityDto[]
}>()

defineEmits<{
  back: []
  openMoveDialog: []
  openMoveDialogWithStage: [stageId: number]
  dealUpdated: [updates: Partial<DealDto>]
  dealDeleted: []
  dealArchived: []
  openAddProduct: []
  openAddContact: []
  updateProduct: [id: number, payload: { quantity?: number; unit_price?: number }]
  removeProduct: [id: number]
  removeContact: [contactId: number]
  amountChanged: [total: number]
}>()

// docsCount is managed internally: DealTabDocuments emits it,
// DealTabStats reads it from this local ref.
const docsCount = ref(0)
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
