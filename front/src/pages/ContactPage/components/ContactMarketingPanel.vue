<template>
  <InfoPanel
    :title="t('crm.contact.sections.marketing')"
    icon="pi-megaphone"
    panel-key="contact-marketing"
    :default-collapsed="false"
  >
    <KeyFactsBlock>
      <!-- Acquisition channel -->
      <KeyFactsItem :label="t('crm.contact.marketing.channel')">
        <InlineEditableField
          :model-value="acquisitionChannelId"
          field-key="acquisition_channel_id"
          field-type="select"
          :options="channelOptions"
          option-label="name"
          option-value="id"
          :placeholder="t('crm.company.marketing.noChannel')"
          :saving="isSaving"
          @save="onSave"
        >
          <template #display="{ value }">
            <span v-if="value">{{ channelLabel(value as number) }}</span>
            <span v-else class="contact-marketing-panel__empty">{{ t('crm.company.marketing.noChannel') }}</span>
          </template>
        </InlineEditableField>
      </KeyFactsItem>

      <!-- Channel history button -->
      <KeyFactsItem :label="t('crm.contact.marketing.channelHistory')">
        <Button
          icon="pi pi-history"
          :label="t('crm.contact.marketing.channelHistory')"
          text
          severity="secondary"
          size="small"
          class="contact-marketing-panel__history-btn"
          @click="historyOpen = true"
        />
      </KeyFactsItem>
    </KeyFactsBlock>
  </InfoPanel>

  <ChannelHistoryDrawer
    v-model="historyOpen"
    :endpoint="`/api/contacts/${contactId}/channel-history`"
  />
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import KeyFactsBlock from '@/components/crm/entity/KeyFactsBlock.vue'
import KeyFactsItem from '@/components/crm/entity/KeyFactsItem.vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import ChannelHistoryDrawer from '@/components/crm/ChannelHistoryDrawer.vue'
import type { AcquisitionChannel } from '@/entities/crm'

const props = defineProps<{
  contactId: number
  acquisitionChannelId: number | null
  isSaving: boolean
  channels: AcquisitionChannel[]
}>()

const emit = defineEmits<{
  save: [fieldKey: string, value: unknown]
}>()

const { t } = useI18n()

const historyOpen = ref(false)

const channelOptions = computed(() => props.channels as unknown as Array<Record<string, unknown>>)

function channelLabel(id: number): string {
  return props.channels.find((c) => c.id === id)?.name ?? String(id)
}

function onSave(fieldKey: string, value: string | number | null) {
  emit('save', fieldKey, value)
}
</script>

<style lang="scss" scoped>
.contact-marketing-panel__empty {
  color: $surface-400;
  font-style: italic;
}

.contact-marketing-panel__history-btn {
  padding: 0;
  height: auto;
}
</style>
