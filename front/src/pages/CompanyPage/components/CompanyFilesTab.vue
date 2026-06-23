<template>
  <EntityFilesTab
    kind="companies"
    :entity-id="companyId"
    :head-title="t('crm.company.tabs.files')"
  />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import EntityFilesTab from '@/components/crm/entity/EntityFilesTab.vue'

const props = defineProps<{
  companyId: number
}>()

const { t } = useI18n()

// props.companyId is forwarded to EntityFilesTab
void props
</script>
