<template>
  <Menu ref="menuRef" :model="items" popup />
</template>

<script setup lang="ts">
import { ref } from 'vue'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'

defineProps<{
  items: MenuItem[]
}>()

const menuRef = ref<InstanceType<typeof Menu> | null>(null)

function toggle(event: MouseEvent) {
  menuRef.value?.toggle(event)
}

defineExpose({ toggle })
</script>
