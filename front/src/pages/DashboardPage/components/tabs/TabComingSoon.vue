<template>
  <Card class="tab-coming-soon">
    <template #content>
      <div class="tab-coming-soon__inner">
        <i :class="icon" class="tab-coming-soon__icon" aria-hidden="true" />
        <h3 class="tab-coming-soon__title">{{ title }}</h3>
        <p class="tab-coming-soon__hint">{{ t('dashboard.hub.wip_hint') }}</p>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'

/**
 * Shell placeholder for the Ф2/Ф3 report tabs (Реестр/График/Рейтинг). The
 * routing + keep-alive scaffolding is live; the report bodies land in later
 * phases. Kept deliberately thin so replacing it with the real tab is a swap.
 */
defineProps<{
  title: string
  icon: string
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.tab-coming-soon {
  background: $surface-card;

  :deep(.p-card-body) {
    padding: 0;
  }
  :deep(.p-card-content) {
    padding: 0;
  }
}

.tab-coming-soon__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  min-height: 320px;
  padding: $space-8 $space-6;
  text-align: center;
}

.tab-coming-soon__icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.tab-coming-soon__title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.tab-coming-soon__hint {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
