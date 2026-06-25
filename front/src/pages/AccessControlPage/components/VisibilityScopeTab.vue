<template>
  <div class="visibility-tab">
    <!-- Warning -->
    <Message severity="warn" class="visibility-tab__warn">
      {{ t('accessControl.visibility.warning') }}
    </Message>

    <!-- Error state -->
    <div v-if="resource.error.value && !resource.loading.value" class="visibility-tab__error">
      <i class="pi pi-exclamation-circle visibility-tab__error-icon" />
      <span>{{ t('accessControl.visibility.errorLoad') }}</span>
      <Button
        :label="t('common.retry')"
        severity="secondary"
        size="small"
        @click="loadConfig"
      />
    </div>

    <!-- Loading skeleton -->
    <div v-else-if="resource.loading.value" class="visibility-tab__skeleton">
      <Skeleton v-for="i in 6" :key="i" height="40px" class="mb-1" />
    </div>

    <!-- Table -->
    <template v-else>
      <DataTable
        :value="rows"
        class="visibility-tab__table"
      >
        <!-- Role column -->
        <Column :header="t('accessControl.visibility.roleColumn')" style="width: 200px">
          <template #body="{ data }">
            <Tag
              :value="t(`roles.${data.role}`)"
              :severity="roleSeverity(data.role)"
            />
          </template>
        </Column>

        <!-- Scope column -->
        <Column :header="t('accessControl.visibility.scopeColumn')">
          <template #body="{ data }">
            <Select
              :model-value="data.scope"
              :options="scopeOptions"
              option-label="label"
              option-value="value"
              class="visibility-tab__select"
              @update:model-value="(v) => setScope(data.role, v)"
            />
          </template>
        </Column>
      </DataTable>

      <!-- Department hint -->
      <Message severity="secondary" class="visibility-tab__hint">
        {{ t('accessControl.visibility.departmentHint') }}
      </Message>

      <!-- Actions -->
      <div class="visibility-tab__actions">
        <Button
          :label="t('accessControl.visibility.reset')"
          severity="secondary"
          outlined
          :disabled="!isDirty"
          @click="resetConfig"
        />
        <Button
          :label="t('accessControl.visibility.save')"
          :loading="saveMutation.isPending.value"
          :disabled="!isDirty"
          @click="saveConfig"
        />
      </div>
    </template>

    <Toast />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import Toast from 'primevue/toast'
import { useVisibilityConfig } from '../composables/useVisibilityConfig'
import type { UserRole } from '@/entities/user'

const { t } = useI18n()

const {
  resource,
  rows,
  isDirty,
  saveMutation,
  loadConfig,
  resetConfig,
  setScope,
  saveConfig,
} = useVisibilityConfig()

onMounted(() => loadConfig())

const scopeOptions = computed(() => [
  { value: 'all', label: t('accessControl.visibility.scopeAll') },
  { value: 'department', label: t('accessControl.visibility.scopeDepartment') },
  { value: 'own', label: t('accessControl.visibility.scopeOwn') },
])

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
.visibility-tab {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.visibility-tab__warn,
.visibility-tab__hint {
  margin: 0;
}

.visibility-tab__table {
  width: 100%;
  max-width: 600px;
}

.visibility-tab__select {
  width: 240px;
}

.visibility-tab__actions {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
}

.visibility-tab__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-8;
  text-align: center;
}

.visibility-tab__error-icon {
  font-size: $font-size-3xl;
  color: var(--p-red-400);
  opacity: 0.7;
}

.visibility-tab__skeleton {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}
</style>
