<template>
  <div class="not-found-state">
    <i
      class="not-found-state__icon"
      :class="icon"
    />
    <p class="not-found-state__title">{{ title }}</p>
    <p
      v-if="hint"
      class="not-found-state__hint"
    >{{ hint }}</p>
    <Button
      v-if="resolvedCtaLabel"
      icon="pi pi-arrow-left"
      :label="resolvedCtaLabel"
      severity="secondary"
      outlined
      @click="onCta"
    />
  </div>
</template>

<script setup lang="ts">
/**
 * Shared not-found / error state — consistency Wave 4 canon.
 *
 * Replaces the icon+title+hint+CTA column that was hand-copied across
 * DealPage / ProductPage / TemplatePage (and now the CRM detail pages).
 *
 * CTA behaviour:
 *  - pass `to` for a router destination (uses router.push);
 *  - omit `to` and listen for the `cta` event to run router.back() etc.
 *  - omit `ctaLabel` entirely to hide the button (falls back to common.back
 *    only when a `to` OR a `cta` listener is meaningfully wired by the caller).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import Button from 'primevue/button'

const props = defineProps<{
  /** PrimeIcons class, e.g. "pi pi-box". */
  icon: string
  /** Bold headline, e.g. "Контакт не найден". */
  title: string
  /** Optional secondary explanation line. */
  hint?: string
  /** CTA button label; falls back to common.back when a target is provided. */
  ctaLabel?: string
  /** Router destination for the CTA; when omitted the `cta` event fires. */
  to?: RouteLocationRaw
}>()

const emit = defineEmits<{
  cta: []
}>()

const { t } = useI18n()
const router = useRouter()

const resolvedCtaLabel = computed(() => props.ctaLabel ?? t('common.back'))

function onCta(): void {
  if (props.to !== undefined) {
    void router.push(props.to)
    return
  }
  emit('cta')
}
</script>

<style lang="scss" scoped>
.not-found-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.not-found-state__icon {
  font-size: $font-size-icon-2xl;
  color: $surface-400;
  opacity: 0.4;
}

.not-found-state__title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  margin: 0;
}

.not-found-state__hint {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
