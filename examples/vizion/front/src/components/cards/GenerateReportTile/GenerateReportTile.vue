<template>
  <Card
    class="generate-report-tile"
    role="button"
    tabindex="0"
    :aria-label="label"
    @click="$emit('click')"
    @keydown.enter.prevent="$emit('click')"
    @keydown.space.prevent="$emit('click')"
  >
    <template #content>
      <div class="tile-inner">
        <div class="tile-dashed">
          <i class="pi pi-plus tile-icon" aria-hidden="true" />
          <span class="tile-label">{{ label }}</span>
        </div>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import Card from 'primevue/card'

interface Props {
  label: string
}

defineProps<Props>()

defineEmits<{
  click: []
}>()
</script>

<style lang="scss" scoped>
.generate-report-tile {
  cursor: pointer;
  transition:
    transform 0.2s,
    box-shadow 0.2s;

  &:hover {
    transform: translateY(-4px);
    box-shadow: $shadow-lg;
  }

  &:focus-visible {
    outline: 2px solid $primary;
    outline-offset: 2px;
  }

  :deep(.p-card-body) {
    padding: $space-3;
    height: 100%;
  }

  :deep(.p-card-content) {
    padding: 0;
    height: 100%;
  }

  .tile-inner {
    display: flex;
    height: 100%;
  }

  .tile-dashed {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: $space-2;
    text-align: center;
    padding: $space-3;
    background: $surface-50;
    border: 3px dashed $surface-200;
    border-radius: $card-border-radius;
  }

  .tile-icon {
    font-size: 1.5rem;
    font-weight: $font-weight-semibold;
    line-height: 1;
    color: $surface-500;
  }

  .tile-label {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    line-height: 1.4;
    color: $surface-600;
  }
}
</style>
