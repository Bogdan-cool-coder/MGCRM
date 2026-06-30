<template>
  <div class="dir-tab-msg-templates">
    <div class="dir-tab-toolbar">
      <div class="dir-tab-toolbar__spacer" />
      <div class="dir-tab-toolbar__actions">
        <Button
          icon="pi pi-plus"
          :label="t('messageTemplates.create')"
          @click="pageRef?.openDrawer(null)"
        />
      </div>
    </div>

    <div class="dir-tab-body">
      <MessageTemplatesPage ref="pageRef" :embedded="true" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import MessageTemplatesPage from '@/pages/MessageTemplatesPage/index.vue'

const { t } = useI18n()

const pageRef = ref<InstanceType<typeof MessageTemplatesPage> | null>(null)
</script>

<style lang="scss" scoped>
.dir-tab-msg-templates {
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
}

.dir-tab-body {
  flex: 1;
}
</style>
