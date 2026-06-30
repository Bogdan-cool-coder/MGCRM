<template>
  <div class="dir-tab-doc-templates">
    <div class="dir-tab-toolbar">
      <div class="dir-tab-toolbar__spacer" />
    </div>

    <div class="dir-tab-body">
      <TemplatesPage :embedded="true" />
    </div>
  </div>
</template>

<script setup lang="ts">
import TemplatesPage from '@/pages/TemplatesPage/index.vue'
</script>

<style lang="scss" scoped>
.dir-tab-doc-templates {
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

.dir-tab-body {
  flex: 1;
}
</style>
