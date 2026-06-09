<template>
  <div class="fields-proposed-panel">
    <div class="fields-proposed-panel__header">
      <span class="fields-proposed-panel__hint">
        {{ t('docx.ai.proposalsHint') }}
      </span>
      <div class="fields-proposed-panel__bulk">
        <Button
          :label="t('docx.ai.acceptAll')"
          icon="pi pi-check-circle"
          size="small"
          severity="success"
          :disabled="disabled || proposals.length === 0"
          @click="$emit('accept-all')"
        />
        <Button
          :label="t('docx.ai.dismissAll')"
          icon="pi pi-times-circle"
          size="small"
          severity="secondary"
          text
          :disabled="disabled || proposals.length === 0"
          @click="$emit('dismiss-all')"
        />
      </div>
    </div>

    <div class="fields-proposed-panel__grid">
      <DocumentFieldProposalCard
        v-for="proposal in proposals"
        :key="proposal.token"
        :proposal="proposal"
        :t="t"
        :label-for-key="labelForKey"
        :disabled="disabled"
        @accept="$emit('accept', $event)"
        @dismiss="$emit('dismiss', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import DocumentFieldProposalCard from './DocumentFieldProposalCard.vue'
import type { DocumentFieldProposalDto } from '@/api/types/chats'

type Translate = (key: string, named?: Record<string, unknown>) => string

defineProps<{
  proposals: DocumentFieldProposalDto[]
  t: Translate
  labelForKey: (_key: string) => string
  /** Locked while a save is in flight. */
  disabled?: boolean
}>()

defineEmits<{
  accept: [token: string]
  dismiss: [token: string]
  'accept-all': []
  'dismiss-all': []
}>()
</script>

<style lang="scss" scoped>
.fields-proposed-panel {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  padding: $space-3;
  border: 1px solid rgba($primary, 0.25);
  border-radius: $radius-md;
  background: rgba($primary, 0.04);

  &__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: $space-3;
    flex-wrap: wrap;
  }

  &__hint {
    font-size: $font-size-sm;
    color: $surface-700;
    line-height: 1.5;
    flex: 1;
    min-width: 12rem;
  }

  &__bulk {
    display: flex;
    gap: $space-2;
    flex-wrap: wrap;
  }

  &__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: $space-3;
  }
}
</style>
