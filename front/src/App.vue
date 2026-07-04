<template>
  <DefaultLayout />
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout'
import { useMacroCrmEchartsTheme } from '@/composables/useMacroCrmEchartsTheme'

// Register the reactive (dark-mode aware) ECharts theme once, app-wide, so every
// <VChart theme="macro-crm"> instance — on any page or tab — reacts to theme
// switches. Registering it in a single leaf tab left charts on other pages
// (HrProgress, Schedule) stuck on the light palette in dark mode.
useMacroCrmEchartsTheme()
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
