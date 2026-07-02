<template>
  <span class="field-kind-tag" :class="`field-kind-tag--${kind}`">
    <i v-if="showIcon" :class="`pi pi-${kindMeta.icon}`" class="field-kind-tag__icon" />
    <span v-if="showLabel" class="field-kind-tag__label">{{ kindMeta.label }}</span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CustomFieldType } from '@/entities/crm'

const props = withDefaults(
  defineProps<{
    kind: CustomFieldType
    showIcon?: boolean
    showLabel?: boolean
  }>(),
  { showIcon: true, showLabel: true },
)

const { t } = useI18n()

interface KindMeta {
  icon: string
  label: string
}

const kindMeta = computed((): KindMeta => {
  const map: Record<CustomFieldType, KindMeta> = {
    text:        { icon: 'align-left',   label: t('customFields.kinds.text') },
    textarea:    { icon: 'list',         label: t('customFields.kinds.textarea') },
    number:      { icon: 'hashtag',      label: t('customFields.kinds.number') },
    date:        { icon: 'calendar',     label: t('customFields.kinds.date') },
    select:      { icon: 'chevron-down', label: t('customFields.kinds.select') },
    multiselect: { icon: 'list-check',   label: t('customFields.kinds.multiselect') },
    url:         { icon: 'link',         label: t('customFields.kinds.url') },
    boolean:     { icon: 'check-square', label: t('customFields.kinds.checkbox') },
    user_ref:    { icon: 'user',         label: t('customFields.kinds.user_ref') },
  }
  return map[props.kind] ?? { icon: 'question', label: props.kind }
})
</script>

<style lang="scss" scoped>
.field-kind-tag {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 2px $space-2;
  border-radius: $radius-pill;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  white-space: nowrap;

  // ── Defaults (text / textarea — neutral) ───────────────────────────────────
  &--text,
  &--textarea {
    background: var(--p-surface-100);
    color: $surface-600;

    .app-dark & {
      background: var(--p-surface-700);
      color: var(--p-surface-300);
    }
  }

  // ── Number — blue ──────────────────────────────────────────────────────────
  &--number {
    background: var(--p-blue-50);
    color: var(--p-blue-700);

    .app-dark & {
      background: var(--p-blue-950);
      color: var(--p-blue-300);
    }
  }

  // ── Date — orange ──────────────────────────────────────────────────────────
  &--date {
    background: var(--p-orange-50);
    color: var(--p-orange-700);

    .app-dark & {
      background: var(--p-orange-950);
      color: var(--p-orange-300);
    }
  }

  // ── Select / Multiselect — teal ────────────────────────────────────────────
  &--select,
  &--multiselect {
    background: var(--p-teal-50);
    color: var(--p-teal-700);

    .app-dark & {
      background: var(--p-teal-950);
      color: var(--p-teal-300);
    }
  }

  // ── URL — purple ───────────────────────────────────────────────────────────
  &--url {
    background: var(--p-purple-50);
    color: var(--p-purple-700);

    .app-dark & {
      background: var(--p-purple-950);
      color: var(--p-purple-300);
    }
  }

  // ── Boolean / checkbox — green ─────────────────────────────────────────────
  &--boolean {
    background: var(--p-green-50);
    color: var(--p-green-700);

    .app-dark & {
      background: var(--p-green-950);
      color: var(--p-green-300);
    }
  }

  // ── User ref — indigo ──────────────────────────────────────────────────────
  &--user_ref {
    background: var(--p-indigo-50);
    color: var(--p-indigo-700);

    .app-dark & {
      background: var(--p-indigo-950);
      color: var(--p-indigo-300);
    }
  }
}

.field-kind-tag__icon {
  font-size: $font-size-xs;
}

.field-kind-tag__label {
  line-height: 1;
}
</style>
