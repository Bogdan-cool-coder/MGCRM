<template>
  <div class="approval-routes-page">
    <PageHeader :title="t('approvalRoutes.title')" icon="pi pi-sitemap">
      <template #actions>
        <Button icon="pi pi-plus" :label="t('approvalRoutes.create')" @click="openDrawer(null)" />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable :value="routes" :loading="loading" row-hover size="small">
          <Column :header="t('approvalRoutes.columns.name')">
            <template #body="{ data }">{{ data.title }}</template>
          </Column>
          <Column :header="t('approvalRoutes.columns.kind')" style="width: 120px">
            <template #body="{ data }">{{ t(`documents.kinds.${data.document_kind}`, data.document_kind) }}</template>
          </Column>
          <Column :header="t('approvalRoutes.columns.template')" style="width: 140px">
            <template #body="{ data }">{{ data.template_code ?? '—' }}</template>
          </Column>
          <Column :header="t('approvalRoutes.columns.isDefault')" style="width: 100px">
            <template #body="{ data }">
              <i :class="data.is_default ? 'pi pi-circle-fill text-primary' : 'pi pi-circle text-secondary'" />
            </template>
          </Column>
          <Column :header="t('approvalRoutes.columns.isActive')" style="width: 90px">
            <template #body="{ data }">
              <i :class="data.is_active ? 'pi pi-check text-success' : 'pi pi-times text-secondary'" />
            </template>
          </Column>
          <Column style="width: 80px">
            <template #body="{ data }">
              <Button icon="pi pi-pencil" text severity="secondary" size="small" @click="openDrawer(data.id)" />
            </template>
          </Column>
          <template #empty>
            <div class="approval-routes-page__empty">
              <i class="pi pi-sitemap" />
              <span>{{ t('approvalRoutes.empty') }}</span>
            </div>
          </template>
        </DataTable>
      </template>
    </Card>

    <ApprovalRouteEditDrawer
      v-model="drawerVisible"
      :route-id="editingId"
      @saved="fetchRoutes"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { approvalRoutesApi } from '@/api/approvalRoutes'
import type { ApprovalRouteListItemDto } from '@/entities/approvalRoute'
import ApprovalRouteEditDrawer from './components/ApprovalRouteEditDrawer.vue'

const { t } = useI18n()

const resource = useAsyncResource<ApprovalRouteListItemDto[]>(() => [])
const routes = computed(() => resource.data.value)
const loading = computed(() => resource.loading.value)

async function fetchRoutes() {
  await resource.run(() => approvalRoutesApi.getApprovalRoutes())
}

watch(() => true, () => void fetchRoutes(), { immediate: true })

const drawerVisible = ref(false)
const editingId = ref<number | null>(null)

function openDrawer(id: number | null) {
  editingId.value = id
  drawerVisible.value = true
}
</script>

<style lang="scss" scoped>
.approval-routes-page {
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
