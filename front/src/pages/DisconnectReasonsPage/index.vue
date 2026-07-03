<template>
  <div class="disconnect-reasons-page" :class="{ 'disconnect-reasons-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('admin.disconnectReasons.title')"
      icon="pi pi-times-circle"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('admin.disconnectReasons.add')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable
          :value="reasons"
          :loading="loading"
          row-hover
          size="small"
          :reorderable-rows="editMode && canManage"
          @row-reorder="onRowReorder"
        >
          <!-- Drag handle — edit-mode only -->
          <Column
            v-if="editMode && canManage"
            row-reorder
            style="width: 40px"
            :pt="{ rowReorderIcon: { class: 'dir-page__drag-handle' } }"
          />

          <!-- ID -->
          <Column header="#" style="width: 60px">
            <template #body="{ data }">{{ data.id }}</template>
          </Column>

          <!-- Name -->
          <Column :header="t('admin.disconnectReasons.columns.name')">
            <template #body="{ data }">{{ data.name }}</template>
          </Column>

          <!-- Sort order -->
          <Column :header="t('admin.disconnectReasons.columns.sortOrder')" style="width: 110px">
            <template #body="{ data }">{{ data.sort_order }}</template>
          </Column>

          <!-- Is active -->
          <Column :header="t('admin.disconnectReasons.columns.isActive')" style="width: 100px">
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

          <!-- Actions (kebab) — edit-mode only -->
          <Column v-if="editMode && canManage" style="width: 56px">
            <template #body="{ data }">
              <DirRowActionsMenu
                @edit="openEdit(data)"
                @delete="deleteReason(data)"
              />
            </template>
          </Column>

          <template #empty>
            <div class="dir-page__empty">
              <i class="pi pi-times-circle" />
              <span>{{ t('admin.disconnectReasons.empty') }}</span>
              <Button
                v-if="canManage"
                :label="t('admin.disconnectReasons.add')"
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

    <ReasonDialog
      v-model="dialogVisible"
      :editing="editingReason"
      :loading="saveMutation.isPending.value"
      @save="save"
    />

  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import ReasonDialog from './components/ReasonDialog.vue'
import DirRowActionsMenu from '@/pages/SettingsPage/components/sections/directories/DirRowActionsMenu.vue'
import { useDisconnectReasonsPage } from './composables/useDisconnectReasonsPage'
import type { DisconnectReason } from '@/entities/crm'

interface DataTableRowReorderEvent {
  dragIndex: number
  dropIndex: number
  value: unknown[]
}

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean; editMode?: boolean }>(), {
  embedded: false,
  editMode: false,
})

const {
  reasons,
  loading,
  dialogVisible,
  editingReason,
  canManage,
  saveMutation,
  openCreate,
  openEdit,
  save,
  toggleActive,
  deleteReason,
  reorder,
} = useDisconnectReasonsPage()

function onRowReorder(event: DataTableRowReorderEvent) {
  void reorder(event.value as DisconnectReason[])
}

const rowCount = computed(() => reasons.value.length)

defineExpose({ canManage, openCreate, rowCount })
</script>

<style lang="scss" scoped>
.disconnect-reasons-page {
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
  padding: $space-8;
  color: var(--p-text-muted-color);

  i {
    font-size: $font-size-lg;
    opacity: 0.4;
  }
}

:deep(.dir-page__drag-handle) {
  cursor: grab;
  color: var(--p-surface-400);

  &:hover {
    color: var(--p-surface-500);
  }
}
</style>
