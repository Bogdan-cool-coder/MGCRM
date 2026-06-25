<template>
  <Drawer
    :visible="visible"
    position="right"
    :show-close-icon="false"
    :pt="{ root: { style: 'width: 420px' } }"
    @update:visible="$emit('close')"
  >
    <template #header>
      <div class="dept-panel__header">
        <span class="dept-panel__title">
          {{ mode === 'create' ? t('accessControl.departments.addDepartment') : t('accessControl.departments.editDepartment') }}
        </span>
      </div>
    </template>

    <div class="dept-panel__body">
      <!-- Name -->
      <div class="dept-panel__field">
        <label class="dept-panel__label">
          {{ t('accessControl.departments.nameLabel') }}
          <span class="dept-panel__required">*</span>
        </label>
        <InputText
          v-model="localName"
          class="w-100"
          :invalid="nameInvalid"
          @blur="nameTouched = true"
        />
        <small v-if="nameInvalid" class="dept-panel__error">
          {{ t('common.required') }}
        </small>
      </div>

      <!-- Parent -->
      <div class="dept-panel__field">
        <label class="dept-panel__label">{{ t('accessControl.departments.parentLabel') }}</label>
        <Select
          v-model="localParentId"
          :options="parentOptions"
          option-label="name"
          option-value="id"
          show-clear
          class="w-100"
        />
      </div>

      <!-- Manager -->
      <div class="dept-panel__field">
        <label class="dept-panel__label">{{ t('accessControl.departments.managerLabel') }}</label>
        <Select
          v-model="localManagerId"
          :options="userOptions"
          option-label="full_name"
          option-value="id"
          show-clear
          filter
          class="w-100"
        />
      </div>

      <!-- Members (edit mode only) -->
      <div v-if="mode === 'edit'" class="dept-panel__field">
        <div class="dept-panel__members-header">
          <label class="dept-panel__label">{{ t('accessControl.departments.membersLabel') }}</label>
          <Button
            icon="pi pi-plus"
            :label="t('accessControl.departments.addMember')"
            text
            severity="secondary"
            size="small"
            @click="$emit('openMemberPicker')"
          />
        </div>

        <!-- Members DataTable -->
        <DataTable
          :value="members"
          :loading="membersLoading"
          size="small"
          class="dept-panel__members-table"
        >
          <Column :header="t('common.name')">
            <template #body="{ data }">{{ data.full_name }}</template>
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
          <Column style="width: 48px">
            <template #body="{ data }">
              <Button
                icon="pi pi-times"
                text
                severity="danger"
                size="small"
                :title="t('accessControl.departments.removeMember')"
                :loading="membersLoading"
                @click="$emit('removeMember', data)"
              />
            </template>
          </Column>
          <template #empty>
            <span class="dept-panel__members-empty">{{ t('common.noData') }}</span>
          </template>
        </DataTable>
      </div>
    </div>

    <template #footer>
      <div class="dept-panel__footer">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          outlined
          @click="$emit('close')"
        />
        <Button
          :label="t('common.save')"
          :loading="saving"
          :disabled="!localName.trim()"
          @click="onSave"
        />
      </div>
    </template>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import type { DepartmentMemberDto } from '@/entities/accessControl'
import type { UserOptionDto } from '@/api/users'
import type { UserRole } from '@/entities/user'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  name: string
  parentId: number | null
  managerId: number | null
  members: DepartmentMemberDto[]
  membersLoading: boolean
  saving: boolean
  parentOptions: { id: number | null; name: string }[]
  userOptions: UserOptionDto[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'save', payload: { name: string; parentId: number | null; managerId: number | null }): void
  (e: 'removeMember', member: DepartmentMemberDto): void
  (e: 'openMemberPicker'): void
  (e: 'update:name', v: string): void
  (e: 'update:parentId', v: number | null): void
  (e: 'update:managerId', v: number | null): void
}>()

const { t } = useI18n()

const localName = ref(props.name)
const localParentId = ref<number | null>(props.parentId)
const localManagerId = ref<number | null>(props.managerId)
const nameTouched = ref(false)
const nameInvalid = ref(false)

watch(() => props.name, (v) => { localName.value = v })
watch(() => props.parentId, (v) => { localParentId.value = v })
watch(() => props.managerId, (v) => { localManagerId.value = v })
watch(() => props.visible, (v) => {
  if (v) nameTouched.value = false
})

watch(localName, (v) => {
  emit('update:name', v)
  if (nameTouched.value) nameInvalid.value = !v.trim()
})
watch(localParentId, (v) => emit('update:parentId', v ?? null))
watch(localManagerId, (v) => emit('update:managerId', v ?? null))

function onSave() {
  nameTouched.value = true
  if (!localName.value.trim()) {
    nameInvalid.value = true
    return
  }
  emit('save', {
    name: localName.value.trim(),
    parentId: localParentId.value ?? null,
    managerId: localManagerId.value ?? null,
  })
}

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
</script>

<style scoped lang="scss">
.dept-panel__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
}

.dept-panel__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
}

.dept-panel__body {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-4;
}

.dept-panel__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.dept-panel__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.dept-panel__required {
  color: var(--p-red-500);
  margin-left: $space-1;
}

.dept-panel__error {
  font-size: $font-size-xs;
  color: var(--p-red-500);
}

.dept-panel__members-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $space-1;
}

.dept-panel__members-table {
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-sm;
}

.dept-panel__members-empty {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

.dept-panel__footer {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding: $space-3 $space-4;
  border-top: 1px solid var(--p-surface-200);
}
</style>
