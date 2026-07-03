<template>
  <TabComingSoon :title="t('dashboard.hub.tab_registry')" icon="pi pi-inbox" />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import TabComingSoon from './TabComingSoon.vue'

// Ф2 stub — RegistryTable (ожидаемые + дожим) lands with the R1 aggregator.
const { t } = useI18n()
</script>
