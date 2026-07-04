<template>
  <div class="filter-trigger">
    <Button
      icon="pi pi-filter"
      :label="label"
      severity="secondary"
      outlined
      class="filter-trigger__btn"
      @click="emit('click', $event)"
    />
    <span
      v-if="count > 0"
      class="filter-trigger__badge"
    >{{ count }}</span>
  </div>
</template>

<script setup lang="ts">
/**
 * Shared filter trigger — Periphery Uplift §0 canon пattern Б
 * (ContactsToolbar__filter-btn + orange badge). Emits `click` with the DOM event
 * so the caller can toggle a Popover/overlay panel. Badge counts active filters.
 */
import Button from 'primevue/button'

defineProps<{
  label: string
  count: number
}>()

const emit = defineEmits<{
  click: [event: Event]
}>()
</script>

<style lang="scss" scoped>
.filter-trigger {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
}

.filter-trigger__btn {
  height: 38px;
  flex-shrink: 0;
}

.filter-trigger__badge {
  position: absolute;
  top: -7px;
  right: -7px;
  min-width: 18px;
  height: 18px;
  border-radius: $radius-badge;
  background: $orange-500;
  color: $surface-0;
  font-size: $font-size-3xs;
  font-weight: $font-weight-bold;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  box-sizing: border-box;
  pointer-events: none;
}
</style>
