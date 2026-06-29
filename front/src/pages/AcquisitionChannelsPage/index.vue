<template>
  <div class="acquisition-channels-page" :class="{ 'acquisition-channels-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('admin.acquisitionChannels.title')"
      icon="pi pi-megaphone"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('admin.acquisitionChannels.add')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable
          :value="channels"
          :loading="loading"
          row-hover
          size="small"
        >
          <!-- ID -->
          <Column header="#" style="width: 60px">
            <template #body="{ data }">{{ data.id }}</template>
          </Column>

          <!-- Name -->
          <Column :header="t('admin.acquisitionChannels.columns.name')">
            <template #body="{ data }">{{ data.name }}</template>
          </Column>

          <!-- Sort order -->
          <Column :header="t('admin.acquisitionChannels.columns.sortOrder')" style="width: 110px">
            <template #body="{ data }">{{ data.sort_order }}</template>
          </Column>

          <!-- Is active -->
          <Column :header="t('admin.acquisitionChannels.columns.isActive')" style="width: 100px">
            <template #body="{ data }">
              <ToggleSwitch
                v-if="canManage"
                :model-value="data.is_active"
                @update:model-value="() => toggleActive(data)"
              />
              <i
                v-else
                :class="data.is_active ? 'pi pi-check text-success' : 'pi pi-times text-secondary'"
              />
            </template>
          </Column>

          <!-- Actions -->
          <Column v-if="canManage" style="width: 90px">
            <template #body="{ data }">
              <span class="d-flex gap-1">
                <Button
                  icon="pi pi-pencil"
                  text
                  severity="secondary"
                  size="small"
                  :title="t('common.edit')"
                  @click="openEdit(data)"
                />
                <Button
                  icon="pi pi-trash"
                  text
                  severity="danger"
                  size="small"
                  :title="t('common.delete')"
                  @click="deleteChannel(data)"
                />
              </span>
            </template>
          </Column>

          <template #empty>
            <div class="dir-page__empty">
              <i class="pi pi-megaphone" />
              <span>{{ t('admin.acquisitionChannels.empty') }}</span>
              <Button
                v-if="canManage"
                :label="t('admin.acquisitionChannels.add')"
                icon="pi pi-plus"
                size="small"
                text
                severity="secondary"
                @click="openCreate"
              />
            </div>
          </template>
        </DataTable>
      </template>
    </Card>

    <ChannelDialog
      v-model="dialogVisible"
      :editing="editingChannel"
      :loading="saveMutation.isPending.value"
      @save="save"
    />

  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import ChannelDialog from './components/ChannelDialog.vue'
import { useAcquisitionChannelsPage } from './composables/useAcquisitionChannelsPage'

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const {
  channels,
  loading,
  dialogVisible,
  editingChannel,
  canManage,
  saveMutation,
  openCreate,
  openEdit,
  save,
  toggleActive,
  deleteChannel,
} = useAcquisitionChannelsPage()

defineExpose({ canManage, openCreate })
</script>

<style lang="scss" scoped>
.acquisition-channels-page {
  padding: $space-3;

  &--embedded {
    padding: 0;
  }
}

.dir-page__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2.5rem; // no exact $space token for 2.5rem; nearest $space-8 (2rem) is 8px short
  color: var(--p-text-muted-color);

  i {
    font-size: $font-size-2xl;
    opacity: 0.4;
  }
}
</style>
