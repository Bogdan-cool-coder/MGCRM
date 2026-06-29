<template>
  <div class="sys-tab-access-control">
    <AccessControlPage :embedded="true" />
  </div>
</template>

<script setup lang="ts">
import AccessControlPage from '@/pages/AccessControlPage/index.vue'
</script>

<style lang="scss" scoped>
.sys-tab-access-control {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}
</style>
