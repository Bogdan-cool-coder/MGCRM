<template>
  <Card class="dashboard-card" @click="$emit('click')">
    <template #title>
      <div class="card-title-wrapper">
        <h3 class="card-title">{{ title }}</h3>
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
    </template>
    <template #content>
      <p class="card-meta">
        <i class="pi pi-th-large" aria-hidden="true" />
        {{ t('widgetsCount', widgetsCount, { named: { count: widgetsCount } }) }}
      </p>
    </template>
  </Card>
</template>

<script setup lang="ts">
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  title: string
  isSystem?: boolean
  isPublished?: boolean
  widgetsCount: number
}

withDefaults(defineProps<Props>(), {
  isSystem: false,
  isPublished: false,
})

defineEmits<{
  click: []
}>()
</script>

<style lang="scss" scoped>
.dashboard-card {
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

    .status-tag {
      flex-shrink: 0;
    }
  }

  .card-meta {
    margin: 0;
    font-size: $font-size-sm;
    color: $surface-600;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
  }
}
</style>
