<template>
  <div v-if="columns.length > 0" class="hidden-cols-toggle">
    <Button
      v-for="col in columns"
      :key="col.stage.id"
      :label="`${col.stage.name} (${col.total})`"
      severity="secondary"
      outlined
      size="small"
      :icon="'pi pi-eye'"
      class="hidden-cols-toggle__btn"
      @click="emit('show', col.stage.id)"
    />
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import type { BoardColumnDto } from '@/entities/sales'

defineProps<{
  columns: BoardColumnDto[]
}>()

const emit = defineEmits<{
  show: [stageId: number]
}>()
</script>

<style lang="scss" scoped>
.hidden-cols-toggle {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  align-self: flex-start;
  padding-top: $space-2;
}

.hidden-cols-toggle__btn {
  white-space: nowrap;
  writing-mode: horizontal-tb;
}
</style>
