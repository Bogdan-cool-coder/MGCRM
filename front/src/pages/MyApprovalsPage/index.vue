<template>
  <div class="my-approvals-page">
    <PageHeader :title="t('myApprovals.title')" icon="pi pi-check-square" />

    <Tabs v-model:value="activeTab">
      <TabList>
        <Tab value="pending">
          {{ t('myApprovals.tabs.pending') }}
          <Badge
            v-if="pendingTotal > 0"
            :value="pendingTotal"
            severity="danger"
            class="ms-1"
          />
        </Tab>
        <Tab value="history">{{ t('myApprovals.tabs.history') }}</Tab>
      </TabList>

      <TabPanels>
        <!-- Pending -->
        <TabPanel value="pending">
          <DataTable :value="pending" :loading="loadingPending" row-hover size="small">
            <Column :header="t('myApprovals.columns.document')" style="width: 130px">
              <template #body="{ data }">
                <router-link :to="`/documents/${data.document_id}`" class="fw-medium">
                  {{ data.document_number ?? `#draft-${data.document_id}` }}
                </router-link>
              </template>
            </Column>
            <Column :header="t('myApprovals.columns.kind')" style="width: 100px">
              <template #body="{ data }">{{ t(`documents.kinds.${data.document_kind}`, data.document_kind) }}</template>
            </Column>
            <Column :header="t('myApprovals.columns.company')">
              <template #body="{ data }">{{ data.company_name ?? '—' }}</template>
            </Column>
            <Column :header="t('myApprovals.columns.stage')" style="width: 100px">
              <template #body="{ data }">{{ data.stage_name }}</template>
            </Column>
            <Column :header="t('myApprovals.columns.date')" style="width: 100px">
              <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
            </Column>
            <Column :header="t('myApprovals.columns.decision')" style="width: 140px">
              <template #body="{ data }">
                <span class="d-flex gap-1">
                  <Button
                    icon="pi pi-check"
                    severity="success"
                    text
                    size="small"
                    :title="t('documents.approval.decide.approve')"
                    @click="approveItem(data)"
                  />
                  <Button
                    icon="pi pi-times"
                    severity="danger"
                    text
                    size="small"
                    :title="t('documents.approval.decide.reject')"
                    @click="openDecide(data, 'rejected')"
                  />
                  <Button
                    icon="pi pi-undo"
                    severity="warn"
                    text
                    size="small"
                    :title="t('documents.approval.decide.rework')"
                    @click="openDecide(data, 'needs_rework')"
                  />
                </span>
              </template>
            </Column>
            <template #empty>
              <div class="my-approvals-page__empty">
                <i class="pi pi-check-square" />
                <span>{{ t('myApprovals.empty.pending') }}</span>
              </div>
            </template>
          </DataTable>
        </TabPanel>

        <!-- History -->
        <TabPanel value="history">
          <DataTable :value="history" :loading="loadingHistory" row-hover size="small">
            <Column :header="t('myApprovals.columns.document')" style="width: 130px">
              <template #body="{ data }">
                <router-link :to="`/documents/${data.document_id}`" class="fw-medium">
                  {{ data.document_number ?? `#draft-${data.document_id}` }}
                </router-link>
              </template>
            </Column>
            <Column :header="t('myApprovals.columns.kind')" style="width: 100px">
              <template #body="{ data }">{{ t(`documents.kinds.${data.document_kind}`, data.document_kind) }}</template>
            </Column>
            <Column :header="t('myApprovals.columns.decision')" style="width: 130px">
              <template #body="{ data }">
                <DocumentStatusTag v-if="data.decision" :status="data.decision" />
              </template>
            </Column>
            <Column :header="t('myApprovals.columns.comment')">
              <template #body="{ data }">{{ data.comment ?? '—' }}</template>
            </Column>
            <Column :header="t('myApprovals.columns.date')" style="width: 100px">
              <template #body="{ data }">{{ data.decided_at ? formatDate(data.decided_at) : '—' }}</template>
            </Column>
            <template #empty>
              <div class="my-approvals-page__empty">
                <i class="pi pi-history" />
                <span>{{ t('myApprovals.empty.history') }}</span>
              </div>
            </template>
          </DataTable>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <!-- Decide dialog -->
    <DecideDialog
      v-model="decideDialogVisible"
      :required="true"
      @confirm="submitDecide"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { approvalsApi } from '@/api/approvals'
import { documentsApi } from '@/api/documents'
import { useApprovalsStore } from '@/stores/approvalsStore'
import DecideDialog from '@/components/shared/DecideDialog.vue'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import type { MyApprovalItemDto } from '@/entities/approval'

const { t } = useI18n()
const toast = useToast()
const approvalsStore = useApprovalsStore()

const activeTab = ref('pending')

// ─── Pending ──────────────────────────────────────────────────────────────
const pendingResource = useAsyncResource<{ items: MyApprovalItemDto[]; total: number }>(
  () => ({ items: [], total: 0 }),
)
const pending = computed(() => pendingResource.data.value.items)
const loadingPending = computed(() => pendingResource.loading.value)
const pendingTotal = computed(() => pendingResource.data.value.total)

async function fetchPending() {
  await pendingResource.run(async () => {
    const resp = await approvalsApi.getMyApprovals({ status: 'pending', per_page: 50 })
    return { items: resp.data, total: resp.meta.total }
  })
}

// ─── History ──────────────────────────────────────────────────────────────
const historyResource = useAsyncResource<MyApprovalItemDto[]>(() => [])
const history = computed(() => historyResource.data.value)
const loadingHistory = computed(() => historyResource.loading.value)

async function fetchHistory() {
  await historyResource.run(async () => {
    const resp = await approvalsApi.getMyApprovals({ status: 'decided', per_page: 50 })
    return resp.data
  })
}

watch(activeTab, (tab) => {
  if (tab === 'history' && history.value.length === 0) {
    void fetchHistory()
  }
})

onMounted(() => {
  void fetchPending()
})

// ─── Decide ────────────────────────────────────────────────────────────────
const decideDialogVisible = ref(false)
const currentItem = ref<MyApprovalItemDto | null>(null)
const decideAction = ref<'rejected' | 'needs_rework'>('rejected')

function openDecide(item: MyApprovalItemDto, action: 'rejected' | 'needs_rework') {
  currentItem.value = item
  decideAction.value = action
  decideDialogVisible.value = true
}

async function approveItem(item: MyApprovalItemDto) {
  try {
    await documentsApi.decideDocument(item.document_id, { decision: 'approved' })
    pendingResource.data.value.items = pendingResource.data.value.items.filter((x) => x.id !== item.id)
    pendingResource.data.value.total = Math.max(0, pendingResource.data.value.total - 1)
    approvalsStore.decrementPending()
    toast.add({ severity: 'success', summary: t('documents.approval.approved'), life: 3000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

async function submitDecide(comment: string) {
  if (!currentItem.value) return
  try {
    await documentsApi.decideDocument(currentItem.value.document_id, {
      decision: decideAction.value,
      comment,
    })
    pendingResource.data.value.items = pendingResource.data.value.items.filter(
      (x) => x.id !== currentItem.value!.id,
    )
    pendingResource.data.value.total = Math.max(0, pendingResource.data.value.total - 1)
    approvalsStore.decrementPending()
    decideDialogVisible.value = false
    toast.add({
      severity: 'warn',
      summary: decideAction.value === 'rejected'
        ? t('documents.approval.rejected')
        : t('documents.approval.needs_rework'),
      life: 3000,
    })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}
</script>

<style lang="scss" scoped>
.my-approvals-page {
  padding: 0.75rem;

  &__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 2rem;
    color: var(--p-text-muted-color);
  }
}
</style>
