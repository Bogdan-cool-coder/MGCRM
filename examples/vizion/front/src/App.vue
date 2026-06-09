<template>
  <AppNotifications />
  <DefaultLayout />
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout'
import AppNotifications from '@/components/base/AppNotifications.vue'
import { usePrimeVueLocale } from '@/composables/usePrimeVueLocale'
import { useLocaleSync } from '@/composables/useLocaleSync'

usePrimeVueLocale()

useLocaleSync()
</script>

<style lang="scss">
html,
body {
  height: 100%;
  margin: 0;
}

#app {
  height: 100vh;
  overflow: hidden;
}
</style>
