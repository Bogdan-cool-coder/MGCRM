<template>
  <EntityFilesTab
    kind="contacts"
    :entity-id="contactId"
    :head-title="t('crm.contact.tabs.files')"
  />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import EntityFilesTab from '@/components/crm/entity/EntityFilesTab.vue'

const props = defineProps<{
  contactId: number
}>()

const { t } = useI18n()

// props.contactId is forwarded to EntityFilesTab
void props
</script>
