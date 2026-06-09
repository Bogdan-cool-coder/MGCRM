<template>
  <Card class="report-card" @click="$emit('click')">
    <template #title>
      <div class="card-title-wrapper">
        <h3 class="card-title">{{ title }}</h3>
        <Tag
          v-if="showStatus"
          :value="statusLabel"
          :severity="statusSeverity"
          size="small"
          class="status-tag"
        />
      </div>
    </template>
    <template #content>
      <p class="card-description">
        {{ description || t('defaultDashboard') }}
      </p>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  title: string
  description?: string
  type?: 'dashboard' | 'custom'
  isPublished?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  type: 'dashboard',
  isPublished: false,
})

defineEmits<{
  click: []
}>()

const showStatus = computed(() => props.type === 'custom')
const statusLabel = computed(() => t('custom'))
const statusSeverity = computed(() => 'secondary')
</script>

<style lang="scss" scoped>
.report-card {
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

  .card-description {
    margin: 0;
    font-size: $font-size-sm;
    color: $surface-600;
    line-height: 1.5;
  }
}
</style>
