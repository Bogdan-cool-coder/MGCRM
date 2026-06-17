<template>
  <Tag
    :severity="isPublished ? 'success' : 'secondary'"
    :icon="isPublished ? 'pi pi-check-circle' : 'pi pi-file'"
    :value="isPublished ? t('onboarding.courses.statuses.published') : t('onboarding.courses.statuses.draft')"
    class="course-status-tag"
  />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'

defineProps<{
  isPublished: boolean
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.course-status-tag {
  white-space: nowrap;
}
</style>
