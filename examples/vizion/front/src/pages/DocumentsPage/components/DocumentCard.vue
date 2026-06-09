<template>
  <Card class="document-card" @click="$emit('open')">
    <template #title>
      <div class="card-title-wrapper">
        <h3 class="card-title">{{ title }}</h3>
        <div class="card-badges">
          <Tag
            :value="type === 'docx' ? t('type.docx') : t('type.html')"
            :severity="type === 'docx' ? 'warn' : 'contrast'"
            size="small"
            class="status-tag"
          />
          <Tag
            v-if="isSystem"
            :value="t('badge.system')"
            severity="info"
            size="small"
            class="status-tag"
          />
          <Tag
            v-else-if="isPublished"
            :value="t('badge.published')"
            severity="success"
            size="small"
            class="status-tag"
          />
          <Tag
            v-else
            :value="t('badge.personal')"
            severity="secondary"
            size="small"
            class="status-tag"
          />
        </div>
      </div>
    </template>
    <template #content>
      <p v-if="description" class="card-meta">{{ description }}</p>
      <p v-else class="card-meta card-meta--muted">
        <i class="pi pi-file" aria-hidden="true" />
        {{ t('noDescription') }}
      </p>
    </template>
  </Card>
</template>

<script setup lang="ts">
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { DocumentTemplateType } from '@/entities/document'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  title: string
  description?: string
  type: DocumentTemplateType
  isSystem?: boolean
  isPublished?: boolean
}

withDefaults(defineProps<Props>(), {
  description: '',
  isSystem: false,
  isPublished: false,
})

defineEmits<{
  open: []
}>()
</script>

<style lang="scss" scoped>
.document-card {
  cursor: pointer;
  transition:
    transform 0.2s,
    box-shadow 0.2s;
  height: 100%;

  &:hover {
    transform: translateY(-4px);
    box-shadow: $shadow-lg;
  }

  :deep(.p-card-body) {
    padding: $space-5;
  }

  .card-title-wrapper {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: $space-2;
    margin-bottom: $space-2;

    .card-title {
      margin: 0;
      font-size: $font-size-md;
      font-weight: $font-weight-semibold;
      color: $surface-900;
      flex: 1;
      line-height: 1.4;
    }

    .card-badges {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 0.25rem;
      flex-shrink: 0;
    }
  }

  .card-meta {
    margin: 0;
    font-size: $font-size-sm;
    color: $surface-600;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;

    &--muted {
      color: $surface-500;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }
  }
}
</style>
