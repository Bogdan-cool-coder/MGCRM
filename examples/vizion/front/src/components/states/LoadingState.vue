<template>
  <div class="loading-state">
    <ProgressSpinner />
  </div>
</template>

<script setup lang="ts">
import ProgressSpinner from 'primevue/progressspinner'
</script>

<style lang="scss" scoped>
.loading-state {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 400px;
}
</style>
