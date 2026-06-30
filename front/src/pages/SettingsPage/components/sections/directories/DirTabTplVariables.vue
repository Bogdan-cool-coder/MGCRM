<template>
  <div class="dir-tab-tpl-variables">
    <div class="dir-tab-toolbar">
      <div class="dir-tab-toolbar__spacer" />
      <div class="dir-tab-toolbar__actions">
        <Button
          v-if="pageRef?.canManage"
          icon="pi pi-plus"
          :label="t('templateVariables.create')"
          @click="pageRef?.openCreate()"
        />
      </div>
    </div>

    <div class="dir-tab-body">
      <TemplateVariablesPage ref="pageRef" :embedded="true" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import TemplateVariablesPage from '@/pages/TemplateVariablesPage/index.vue'

const { t } = useI18n()

const pageRef = ref<InstanceType<typeof TemplateVariablesPage> | null>(null)
</script>

<style lang="scss" scoped>
.dir-tab-tpl-variables {
  display: flex;
  flex-direction: column;
}

.dir-tab-toolbar {
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

.dir-tab-toolbar__spacer {
  flex: 1;
}

.dir-tab-toolbar__actions {
  display: flex;
  align-items: center;
  gap: $space-2;

  .app-dark & :deep(.p-button-secondary:not(.p-button-text):not(.p-button-outlined)) {
    background: var(--p-surface-200);
    border-color: var(--p-surface-300);
    color: var(--p-surface-900);

    &:hover {
      background: var(--p-surface-300);
      border-color: var(--p-surface-400);
    }
  }
}

.dir-tab-body {
  flex: 1;
}
</style>
