<template>
  <div class="departments-tab">
    <!-- Toolbar -->
    <div class="departments-tab__toolbar">
      <div class="departments-tab__search-wrap">
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="searchQuery"
            :placeholder="t('common.search')"
            size="small"
          />
        </IconField>
        <!-- View toggle -->
        <div class="departments-tab__view-toggle">
          <Button
            :label="t('accessControl.departments.viewTree')"
            :outlined="viewMode !== 'tree'"
            :text="viewMode === 'chart'"
            size="small"
            icon="pi pi-sitemap"
            @click="viewMode = 'tree'"
          />
          <Button
            :label="t('accessControl.departments.viewChart')"
            :outlined="viewMode !== 'chart'"
            :text="viewMode === 'tree'"
            size="small"
            icon="pi pi-share-alt"
            @click="viewMode = 'chart'"
          />
        </div>
      </div>

      <Button
        icon="pi pi-plus"
        :label="t('accessControl.departments.addDepartment')"
        @click="openCreate"
      />
    </div>

    <!-- Depth warning -->
    <Message v-if="depthWarning" severity="warn" class="departments-tab__warn">
      {{ t('accessControl.departments.depthWarning') }}
    </Message>

    <!-- Error state -->
    <div v-if="depts.error.value && !depts.loading.value" class="departments-tab__error">
      <i class="pi pi-exclamation-circle departments-tab__error-icon" />
      <span>{{ t('common.errorLoad') }}</span>
      <Button
        :label="t('common.retry')"
        severity="secondary"
        size="small"
        @click="loadDepartments"
      />
    </div>

    <!-- Content area -->
    <div v-else class="departments-tab__content">
      <!-- Tree view -->
      <template v-if="viewMode === 'tree'">
        <div class="departments-tab__tree-layout">
          <div class="departments-tab__tree-wrap">
            <DepartmentTree
              :nodes="filteredTreeNodes"
              :loading="depts.loading.value"
              @select="(d) => selectDept(d)"
              @edit="(d) => openEdit(d)"
              @delete="confirmDelete"
              @add-dept="openCreate"
            />
          </div>

          <!-- Department members detail panel -->
          <div
            v-if="deptDetail.dept"
            class="departments-tab__detail"
          >
            <div class="departments-tab__detail-header">
              <span class="departments-tab__detail-title">{{ deptDetail.dept.name }}</span>
              <Button
                icon="pi pi-pencil"
                text
                severity="secondary"
                size="small"
                :title="t('common.edit')"
                @click="openEdit(deptDetail.dept!)"
              />
            </div>

            <!-- Loading -->
            <div v-if="deptDetail.loading" class="departments-tab__detail-body">
              <Skeleton v-for="i in 3" :key="i" height="36px" class="mb-1" />
            </div>

            <!-- Error -->
            <div v-else-if="deptDetail.error" class="departments-tab__detail-error">
              <i class="pi pi-exclamation-circle" />
              <span>{{ t('common.errorLoad') }}</span>
            </div>

            <!-- Members list -->
            <DataTable
              v-else
              :value="deptDetail.members"
              size="small"
              class="departments-tab__detail-table"
            >
              <Column :header="t('common.name')">
                <template #body="{ data }">{{ data.full_name }}</template>
              </Column>
              <Column :header="t('common.email')" style="width: 160px">
                <template #body="{ data }">
                  <span class="departments-tab__detail-email">{{ data.email }}</span>
                </template>
              </Column>
              <Column :header="t('common.role')" style="width: 110px">
                <template #body="{ data }">
                  <Tag
                    v-if="data.role"
                    :value="t(`roles.${data.role}`)"
                    :severity="roleSeverity(data.role)"
                    size="small"
                  />
                </template>
              </Column>
              <template #empty>
                <span class="departments-tab__detail-empty">
                  {{ t('accessControl.departments.noMembers') }}
                </span>
              </template>
            </DataTable>
          </div>
        </div>
      </template>

      <!-- Org chart view -->
      <template v-else>
        <div v-if="depts.loading.value" class="departments-tab__tree-wrap">
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="80px" />
        </div>
        <OrgChartView
          v-else
          :nodes="filteredTreeNodes"
          @select="(n) => openEdit(n.data)"
        />
      </template>
    </div>

    <!-- Side panel: create / edit -->
    <DepartmentSidePanel
      :visible="panel.visible"
      :mode="panel.mode"
      :name="formName"
      :parent-id="formParentId"
      :manager-id="formManagerId"
      :members="panel.members"
      :members-loading="membersMutation.isPending.value"
      :saving="saveMutation.isPending.value"
      :parent-options="parentOptions"
      :user-options="userOptions"
      @close="closePanel"
      @save="onPanelSave"
      @remove-member="removeMember"
      @open-member-picker="memberPickerVisible = true"
      @update:name="formName = $event"
      @update:parent-id="formParentId = $event"
      @update:manager-id="formManagerId = $event"
    />

    <!-- MultiSelect member picker overlay -->
    <Dialog
      v-model:visible="memberPickerVisible"
      :header="t('accessControl.departments.addMember')"
      modal
      style="width: 440px"
    >
      <MultiSelect
        v-model="selectedMemberIds"
        :options="availableMembersToAdd"
        option-label="full_name"
        option-value="id"
        filter
        :placeholder="t('common.search')"
        class="w-100"
        display="chip"
      />
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          outlined
          @click="memberPickerVisible = false; selectedMemberIds = []"
        />
        <Button
          :label="t('common.add')"
          :loading="membersMutation.isPending.value"
          :disabled="selectedMemberIds.length === 0"
          @click="addMembers"
        />
      </template>
    </Dialog>

    <!-- ConfirmDialog for delete -->
    <ConfirmDialog group="dept-delete" />

    <Toast v-if="!embedded" />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Dialog from 'primevue/dialog'
import MultiSelect from 'primevue/multiselect'
import ConfirmDialog from 'primevue/confirmdialog'
import Toast from 'primevue/toast'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'

import DepartmentTree from './DepartmentTree.vue'
import DepartmentSidePanel from './DepartmentSidePanel.vue'
import OrgChartView from './OrgChartView.vue'
import { useDepartments } from '../composables/useDepartments'
import type { DepartmentDto } from '@/entities/accessControl'
import type { UserRole } from '@/entities/user'

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const { t } = useI18n()
const confirm = useConfirm()

const {
  depts,
  searchQuery,
  viewMode,
  panel,
  deptDetail,
  formName,
  formParentId,
  formManagerId,
  memberPickerVisible,
  selectedMemberIds,
  filteredTreeNodes,
  depthWarning,
  parentOptions,
  userOptions,
  availableMembersToAdd,
  saveMutation,
  membersMutation,
  loadDepartments,
  selectDept,
  openCreate,
  openEdit,
  closePanel,
  saveDept,
  deleteDept,
  addMembers,
  removeMember,
} = useDepartments()

function roleSeverity(role: UserRole): 'info' | 'success' | 'warn' | 'danger' | 'secondary' {
  const map: Record<UserRole, 'info' | 'success' | 'warn' | 'danger' | 'secondary'> = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    manager: 'success',
    accountant: 'secondary',
    cfo: 'secondary',
  }
  return map[role] ?? 'secondary'
}

onMounted(() => loadDepartments())

function onPanelSave(payload: { name: string; parentId: number | null; managerId: number | null }) {
  formName.value = payload.name
  formParentId.value = payload.parentId
  formManagerId.value = payload.managerId
  saveDept()
}

function confirmDelete(dept: DepartmentDto) {
  confirm.require({
    group: 'dept-delete',
    header: t('accessControl.departments.deleteDepartment'),
    message: t('accessControl.departments.deleteConfirm', { name: dept.name }),
    icon: 'pi pi-exclamation-triangle',
    accept: () => deleteDept(dept),
    rejectLabel: t('common.cancel'),
    acceptLabel: t('common.delete'),
  })
}
</script>

<style scoped lang="scss">
.departments-tab {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  height: 100%;
}

.departments-tab__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
}

.departments-tab__search-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.departments-tab__view-toggle {
  display: flex;
  gap: $space-1;
}

.departments-tab__warn {
  margin: 0;
}

.departments-tab__content {
  flex: 1;
  min-height: 0;
  overflow: auto;
}

.departments-tab__tree-layout {
  display: grid;
  grid-template-columns: 1fr 380px;
  gap: $space-4;
  height: 100%;
  min-height: 0;

  @media (max-width: 900px) {
    grid-template-columns: 1fr;
  }
}

.departments-tab__tree-wrap {
  height: 100%;
  min-height: 0;
  overflow: auto;
}

.departments-tab__detail {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  background-color: var(--p-surface-50);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-3;
  min-height: 120px;
  overflow: auto;

  .app-dark & {
    background-color: var(--p-surface-50);
    border-color: var(--p-surface-200);
  }
}

.departments-tab__detail-header {
  display: flex;
  align-items: center;
  gap: $space-2;
  justify-content: space-between;
}

.departments-tab__detail-title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.departments-tab__detail-body {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.departments-tab__detail-error {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-sm;
  color: var(--p-red-400);
  padding: $space-2;
}

.departments-tab__detail-table {
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-sm;
}

.departments-tab__detail-email {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
  max-width: 150px;
}

.departments-tab__detail-empty {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

.departments-tab__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-8;
  text-align: center;
}

.departments-tab__error-icon {
  font-size: $font-size-3xl;
  color: var(--p-red-400);
  opacity: 0.7;
}
</style>
