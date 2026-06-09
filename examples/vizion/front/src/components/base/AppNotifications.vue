<template>
  <Toast position="top-right" />
</template>

<script setup lang="ts">
import { watch } from 'vue'
import Toast from 'primevue/toast'
import { useToast } from 'primevue/usetoast'
import { notificationCenter } from '@/application'

const toast = useToast()

watch(
  () => notificationCenter.state.queue.length,
  () => {
    const notifications = notificationCenter.drain()

    notifications.forEach(({ id: _id, ...message }) => {
      toast.add({
        life: 4000,
        ...message,
      })
    })
  },
  { immediate: true },
)
</script>
