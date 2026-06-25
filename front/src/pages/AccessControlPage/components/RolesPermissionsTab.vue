<template>
  <div class="roles-tab">
    <!-- Admin note -->
    <Message severity="info" class="roles-tab__note">
      {{ t('accessControl.roles.adminNote') }}
    </Message>

    <!-- Error state -->
    <div v-if="resource.error.value && !resource.loading.value" class="roles-tab__error">
      <i class="pi pi-exclamation-circle roles-tab__error-icon" />
      <span>{{ t('accessControl.roles.errorLoad') }}</span>
      <Button
        :label="t('common.retry')"
        severity="secondary"
        size="small"
        @click="loadPermissions"
      />
    </div>

    <!-- Loading skeleton -->
    <div v-else-if="resource.loading.value" class="roles-tab__skeleton">
      <div v-for="i in 4" :key="i" class="roles-tab__skeleton-group">
        <Skeleton height="36px" class="mb-1" />
        <Skeleton v-for="j in 3" :key="j" height="28px" class="mb-1" />
      </div>
    </div>

    <!-- Matrix -->
    <template v-else>
      <PermissionMatrix
        :rows="rows"
        @toggle="togglePermission"
      />

      <!-- Actions -->
      <div class="roles-tab__actions">
        <Button
          :label="t('accessControl.roles.reset')"
          severity="secondary"
          outlined
          :disabled="!isDirty"
          @click="resetPermissions"
        />
        <Button
          :label="t('accessControl.roles.save')"
          :loading="saveMutation.isPending.value"
          :disabled="!isDirty"
          @click="savePermissions"
        />
      </div>
    </template>

    <Toast />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Toast from 'primevue/toast'
import PermissionMatrix from './PermissionMatrix.vue'
import { useRolesPermissions } from '../composables/useRolesPermissions'

const { t } = useI18n()

const {
  resource,
  rows,
  isDirty,
  saveMutation,
  loadPermissions,
  resetPermissions,
  togglePermission,
  savePermissions,
} = useRolesPermissions()

onMounted(() => loadPermissions())
</script>

<style scoped lang="scss">
.roles-tab {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.roles-tab__note {
  margin: 0;
}

.roles-tab__actions {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-2;
}

.roles-tab__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-8;
  text-align: center;
}

.roles-tab__error-icon {
  font-size: $font-size-3xl;
  color: var(--p-red-400);
  opacity: 0.7;
}

.roles-tab__skeleton {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.roles-tab__skeleton-group {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}
</style>
