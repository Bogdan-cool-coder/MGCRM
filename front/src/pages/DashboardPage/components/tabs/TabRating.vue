<template>
  <TabComingSoon :title="t('dashboard.hub.tab_rating')" icon="pi pi-trophy" />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import TabComingSoon from './TabComingSoon.vue'

// Ф3 stub — LeaderCard + RatingTable land with the R3 aggregator.
const { t } = useI18n()
</script>
