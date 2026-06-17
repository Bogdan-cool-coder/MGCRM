<template>
  <Button
    v-if="!completed"
    :label="label ?? t('onboarding.coursePage.markDone')"
    icon="pi pi-check"
    :loading="loading"
    @click="$emit('complete')"
  />
  <Tag v-else severity="success" icon="pi pi-check-circle" :value="t('onboarding.coursePage.lessonDone')" />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'

defineProps<{
  completed?: boolean
  loading?: boolean
  label?: string
}>()

defineEmits<{ complete: [] }>()

const { t } = useI18n()
</script>
