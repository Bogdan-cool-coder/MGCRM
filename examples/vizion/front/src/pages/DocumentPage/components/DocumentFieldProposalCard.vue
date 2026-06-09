<template>
  <div class="field-proposal-card">
    <div class="field-proposal-card__head">
      <code class="field-proposal-card__token" :title="tokenDisplay">
        {{ tokenDisplay }}
      </code>
      <Tag
        :value="sourceLabel"
        :severity="proposal.source === 'catalog' ? 'success' : 'warn'"
        rounded
      />
    </div>

    <div class="field-proposal-card__arrow" aria-hidden="true">
      <i class="pi pi-arrow-down" />
    </div>

    <div class="field-proposal-card__target">
      <span class="field-proposal-card__field" :title="proposal.suggested_field">
        {{ fieldLabel }}
      </span>
      <span
        v-if="confidencePercent !== null"
        class="field-proposal-card__confidence"
      >
        {{ t('docx.ai.confidence', { percent: confidencePercent }) }}
      </span>
    </div>

    <div class="field-proposal-card__actions">
      <Button
        class="field-proposal-card__accept"
        :label="t('docx.ai.accept')"
        icon="pi pi-check"
        size="small"
        severity="primary"
        :disabled="disabled"
        type="button"
        @click="$emit('accept', proposal.token)"
      />
      <Button
        :label="t('docx.ai.dismiss')"
        icon="pi pi-times"
        size="small"
        severity="secondary"
        text
        :disabled="disabled"
        type="button"
        @click="$emit('dismiss', proposal.token)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { DocumentFieldProposalDto } from '@/api/types/chats'

type Translate = (key: string, named?: Record<string, unknown>) => string

const props = defineProps<{
  proposal: DocumentFieldProposalDto
  t: Translate
  /** Resolve a catalog key → localized label (raw MacroData fields pass through). */
  labelForKey: (_key: string) => string
  /** Locked while a save is in flight. */
  disabled?: boolean
}>()

defineEmits<{
  /** Accept this proposal — bare placeholder token. */
  accept: [token: string]
  /** Decline this proposal — bare placeholder token. */
  dismiss: [token: string]
}>()

const { t } = props

const tokenDisplay = computed(() => '${' + props.proposal.token + '}')

const sourceLabel = computed(() =>
  props.proposal.source === 'catalog'
    ? t('docx.ai.source.catalog')
    : t('docx.ai.source.macrodata'),
)

const fieldLabel = computed(() => props.labelForKey(props.proposal.suggested_field))

const confidencePercent = computed<number | null>(() => {
  const c = props.proposal.confidence
  if (typeof c !== 'number' || Number.isNaN(c)) return null
  // Backend may emit 0..1 or 0..100 — normalize to a percent.
  const pct = c <= 1 ? c * 100 : c
  return Math.round(Math.max(0, Math.min(100, pct)))
})
</script>

<style lang="scss" scoped>
.field-proposal-card {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-3;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  background: $surface-0;

  &__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: $space-2;
  }

  &__token {
    font-family: monospace;
    font-size: $font-size-sm;
    color: $surface-900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__arrow {
    color: $surface-400;
    font-size: 0.85rem;
    line-height: 1;
  }

  &__target {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
  }

  &__field {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__confidence {
    font-size: $font-size-xs;
    color: $surface-500;
  }

  &__actions {
    display: flex;
    gap: $space-2;
    margin-top: $space-1;
  }
}
</style>
