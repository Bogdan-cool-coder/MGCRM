<template>
  <div class="sys-tab-automation-runs">
    <AutomationRunsPage :embedded="true" />
  </div>
</template>

<script setup lang="ts">
import AutomationRunsPage from '@/pages/AutomationRunsPage/index.vue'
</script>

<style lang="scss" scoped>
.sys-tab-automation-runs {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}
</style>
