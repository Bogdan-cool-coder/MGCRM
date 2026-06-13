<template>
  <div class="msg-templates-page">
    <PageHeader :title="t('messageTemplates.title')" icon="pi pi-envelope">
      <template #actions>
        <Button icon="pi pi-plus" :label="t('messageTemplates.create')" @click="openDrawer(null)" />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable :value="templates" :loading="loading" row-hover size="small">
          <Column :header="t('messageTemplates.columns.name')">
            <template #body="{ data }">{{ data.title }}</template>
          </Column>
          <Column :header="t('messageTemplates.columns.bindings')" style="width: 110px">
            <template #body="{ data }">{{ data.bindings.length }}</template>
          </Column>
          <Column :header="t('messageTemplates.columns.isActive')" style="width: 90px">
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
            <div class="msg-templates-page__empty">
              <i class="pi pi-envelope" />
              <span>{{ t('messageTemplates.empty') }}</span>
            </div>
          </template>
        </DataTable>
      </template>
    </Card>

    <MessageTemplateDrawer
      v-model="drawerVisible"
      :template-id="editingId"
      @saved="onSaved"
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
import MessageTemplateDrawer from './components/MessageTemplateDrawer.vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { messageTemplatesApi } from '@/api/messageTemplates'
import type { MessageTemplateListItemDto } from '@/entities/messageTemplate'

const { t } = useI18n()

const resource = useAsyncResource<MessageTemplateListItemDto[]>(() => [])
const templates = computed(() => resource.data.value)
const loading = computed(() => resource.loading.value)

watch(() => true, () => {
  void resource.run(() => messageTemplatesApi.getMessageTemplates())
}, { immediate: true })

const drawerVisible = ref(false)
const editingId = ref<number | null>(null)

function openDrawer(id: number | null) {
  editingId.value = id
  drawerVisible.value = true
}

function onSaved() {
  void resource.run(() => messageTemplatesApi.getMessageTemplates())
}
</script>

<style lang="scss" scoped>
.msg-templates-page {
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
