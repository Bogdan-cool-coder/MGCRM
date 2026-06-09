<template>
  <div class="action-buttons">
    <slot>
      <Button
        v-if="showEdit"
        icon="pi pi-pencil"
        outlined
        :severity="editSeverity"
        :size="size"
        @click="$emit('edit')"
      />
      <Button
        v-if="showDelete"
        icon="pi pi-trash"
        outlined
        :severity="deleteSeverity"
        :size="size"
        @click="$emit('delete')"
      />
    </slot>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
interface Props {
  showEdit?: boolean
  showDelete?: boolean
  editSeverity?:
    | 'primary'
    | 'secondary'
    | 'danger'
    | 'success'
    | 'warning'
    | 'help'
    | 'info'
    | 'contrast'
  deleteSeverity?:
    | 'primary'
    | 'secondary'
    | 'danger'
    | 'success'
    | 'warning'
    | 'help'
    | 'info'
    | 'contrast'
  size?: 'small' | 'normal' | 'large'
}

withDefaults(defineProps<Props>(), {
  showEdit: true,
  showDelete: true,
  editSeverity: 'secondary',
  deleteSeverity: 'danger',
  size: 'small',
})

defineEmits<{
  edit: []
  delete: []
}>()
</script>

<style lang="scss" scoped>
.action-buttons {
  display: flex;
  gap: 0.25rem;
}
</style>
