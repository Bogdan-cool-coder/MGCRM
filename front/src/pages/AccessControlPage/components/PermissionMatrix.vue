<template>
  <div class="perm-matrix">
    <div
      v-for="group in PERMISSION_GROUPS"
      :key="group.key"
      class="perm-matrix__group"
    >
      <Panel
        :header="t(group.labelKey)"
        toggleable
        :collapsed="false"
        class="perm-matrix__panel"
      >
        <DataTable
          :value="rows.filter((r) => r.groupKey === group.key)"
          size="small"
          class="perm-matrix__table"
        >
          <!-- Permission name -->
          <Column :header="t('accessControl.roles.permissionLabel')" style="min-width: 200px">
            <template #body="{ data }">
              <code class="perm-matrix__perm-name">{{ data.permission }}</code>
            </template>
          </Column>

          <!-- Role columns -->
          <Column
            v-for="role in ALL_ROLES"
            :key="role"
            :header="t(`roles.${role}`)"
            style="width: 100px; text-align: center"
          >
            <template #body="{ data }">
              <Checkbox
                :model-value="data.checked[role]"
                :binary="true"
                :disabled="role === 'admin'"
                @update:model-value="(v) => $emit('toggle', data.permission, role, Boolean(v))"
              />
            </template>
          </Column>
        </DataTable>
      </Panel>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Panel from 'primevue/panel'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Checkbox from 'primevue/checkbox'
import { USER_ROLES, type UserRole } from '@/entities/user'
import { PERMISSION_GROUPS } from '@/entities/accessControl'
import type { PermissionRow } from '../composables/useRolesPermissions'

defineProps<{
  rows: PermissionRow[]
}>()

defineEmits<{
  (e: 'toggle', permission: string, role: UserRole, checked: boolean): void
}>()

const { t } = useI18n()
const ALL_ROLES = USER_ROLES
</script>

<style scoped lang="scss">
.perm-matrix {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.perm-matrix__group {
  // each group has its own Panel
}

.perm-matrix__perm-name {
  font-family: $font-family-mono;
  font-size: $font-size-xs;
  background-color: var(--p-surface-100);
  padding: 2px $space-1;
  border-radius: $radius-sm;

  .app-dark & {
    background-color: var(--p-surface-200);
  }
}
</style>
