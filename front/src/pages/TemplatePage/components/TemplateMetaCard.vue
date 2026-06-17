<template>
  <Card class="template-meta-card">
    <template #title>{{ t('templates.card.meta.title') }}</template>
    <template #content>
      <dl class="template-meta-card__list">
        <div class="template-meta-card__item">
          <dt>{{ t('templates.card.meta.code') }}</dt>
          <dd><code>{{ template.code }}</code></dd>
        </div>
        <div class="template-meta-card__item">
          <dt>{{ t('documents.list.filters.kind') }}</dt>
          <dd>{{ t(`documents.kinds.${template.kind}`, template.kind) }}</dd>
        </div>
        <div class="template-meta-card__item">
          <dt>{{ t('templates.card.meta.products') }}</dt>
          <dd>{{ template.product_codes.join(', ') || '—' }}</dd>
        </div>
        <div class="template-meta-card__item">
          <dt>{{ t('templates.card.meta.countries') }}</dt>
          <dd>{{ template.country_codes.join(', ') || '—' }}</dd>
        </div>
        <div v-if="template.category_codes?.length" class="template-meta-card__item">
          <dt>{{ t('templates.card.meta.categories') }}</dt>
          <dd>{{ template.category_codes.join(', ') }}</dd>
        </div>
      </dl>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import type { TemplateDto } from '@/entities/template'

defineProps<{ template: TemplateDto }>()
const { t } = useI18n()
</script>

<style lang="scss" scoped>
.template-meta-card {
  &__list {
    margin: 0;
  }

  &__item {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.35rem 0;
    border-bottom: 1px solid var(--p-surface-100);
    font-size: $font-size-sm;

    &:last-child {
      border-bottom: none;
    }

    dt {
      color: var(--p-text-muted-color);
      font-weight: normal;
    }

    dd {
      margin: 0;
      text-align: right;
    }
  }
}
</style>
