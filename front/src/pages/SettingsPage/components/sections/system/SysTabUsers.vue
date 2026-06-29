<template>
  <div class="sys-tab-users">
    <div class="sys-tab-toolbar">
      <div class="sys-tab-toolbar__spacer" />
      <div class="sys-tab-toolbar__actions">
        <Button
          v-if="pageRef?.canManage"
          icon="pi pi-plus"
          :label="t('admin.users.addUser')"
          @click="pageRef?.openCreate()"
        />
      </div>
    </div>

    <div class="sys-tab-body">
      <UsersPage ref="pageRef" :embedded="true" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import UsersPage from '@/pages/UsersPage/index.vue'

const { t } = useI18n()

const pageRef = ref<InstanceType<typeof UsersPage> | null>(null)
</script>

<style lang="scss" scoped>
.sys-tab-users {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.sys-tab-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
  background: $surface-card;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }
}

.sys-tab-toolbar__spacer {
  flex: 1;
}

.sys-tab-toolbar__actions {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.sys-tab-body {
  flex: 1;
  overflow-y: auto;
  padding: $space-3;
}
</style>
