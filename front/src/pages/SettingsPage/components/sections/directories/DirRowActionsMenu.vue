<template>
  <!-- Kebab trigger + popup menu (Edit / Delete). Shown only in directory
       edit-mode (Гэп-2). Matches settings.html DirectoriesTab row kebab. -->
  <div class="dir-row-actions" @click.stop>
    <Button
      icon="pi pi-ellipsis-v"
      text
      severity="secondary"
      size="small"
      rounded
      :aria-label="t('common.actions')"
      @click="toggle"
    />
    <Menu ref="menuRef" :model="items" popup />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'

const props = withDefaults(
  defineProps<{
    /** Extra menu items rendered above Edit/Delete (e.g. Duplicate, Archive) */
    extraItems?: MenuItem[]
    /** Whether the built-in Edit item is shown */
    showEdit?: boolean
    /** Whether the built-in Delete item is shown */
    showDelete?: boolean
  }>(),
  { extraItems: () => [], showEdit: true, showDelete: true },
)

const emit = defineEmits<{
  edit: []
  delete: []
}>()

const { t } = useI18n()

const menuRef = ref<InstanceType<typeof Menu> | null>(null)

const items = computed<MenuItem[]>(() => {
  const list: MenuItem[] = [...(props.extraItems ?? [])]
  if (props.showEdit) {
    list.push({ label: t('common.edit'), icon: 'pi pi-pencil', command: () => emit('edit') })
  }
  if (props.showDelete) {
    list.push({
      label: t('common.delete'),
      icon: 'pi pi-trash',
      class: 'dir-row-actions__delete',
      command: () => emit('delete'),
    })
  }
  return list
})

function toggle(event: MouseEvent) {
  menuRef.value?.toggle(event)
}
</script>

<style lang="scss" scoped>
.dir-row-actions {
  display: inline-flex;
}
</style>

<!-- Danger styling for the Delete item. The PrimeVue Menu popup teleports to
     <body>, so scoped [data-v] selectors never reach it — this rule MUST be
     global. --p-red-* inverts in dark automatically (lightens), so one rule
     covers both themes. -->
<style lang="scss">
.dir-row-actions__delete .p-menu-item-link,
.dir-row-actions__delete .p-menu-item-link .p-menu-item-icon {
  color: var(--p-red-500);
}

.app-dark .dir-row-actions__delete .p-menu-item-link,
.app-dark .dir-row-actions__delete .p-menu-item-link .p-menu-item-icon {
  color: var(--p-red-400);
}
</style>
