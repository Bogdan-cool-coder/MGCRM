<template>
  <div class="mk-skeleton">
    <Skeleton height="88px" border-radius="8px" class="mb-4" />
    <Skeleton height="72px" border-radius="8px" class="mb-4" />
    <div class="row g-4 mb-4">
      <div class="col-12 col-lg-8">
        <Skeleton v-for="n in 4" :key="n" height="56px" border-radius="8px" class="mb-2" />
      </div>
      <div class="col-12 col-lg-4">
        <Skeleton height="240px" border-radius="8px" />
      </div>
    </div>
    <div class="d-flex gap-4">
      <Skeleton height="120px" border-radius="8px" class="flex-fill" />
      <Skeleton height="120px" border-radius="8px" class="flex-fill" />
    </div>
  </div>
</template>

<script setup lang="ts">
import Skeleton from 'primevue/skeleton'
</script>

<style lang="scss" scoped>
.mk-skeleton {
  :deep(.p-skeleton) {
    // wave animation is the PrimeVue default; keep the rhythm compact.
    margin-bottom: 0;
  }
}
</style>
