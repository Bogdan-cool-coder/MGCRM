<template>
  <Dialog
    v-model:visible="visible"
    :header="t('onboarding.coursePage.complete.title')"
    modal
    style="width: 36rem"
    :closable="false"
  >
    <div class="course-complete__hero mb-4">
      <i class="pi pi-trophy course-complete__trophy" />
      <p class="course-complete__congrats">
        {{ t('onboarding.coursePage.complete.congrats', { title: courseTitle }) }}
      </p>
      <Tag severity="success" icon="pi pi-check-circle" value="Завершён" />
    </div>

    <Divider />

    <!-- Certificate polling -->
    <div class="course-complete__cert-row d-flex align-items-center py-2">
      <template v-if="!certificate">
        <ProgressSpinner style="width: 28px; height: 28px" strokeWidth="4" />
        <span class="course-complete__muted">{{ t('onboarding.coursePage.complete.certPending') }}</span>
      </template>
      <template v-else>
        <i class="pi pi-file-pdf course-complete__cert-icon" />
        <span class="course-complete__cert-ready">{{ t('onboarding.coursePage.complete.certReady') }}</span>
        <Button
          :label="t('onboarding.coursePage.complete.downloadCert')"
          icon="pi pi-download"
          size="small"
          @click="$emit('downloadCert')"
        />
      </template>
    </div>

    <template #footer>
      <Button
        :label="t('onboarding.coursePage.complete.backToCourses')"
        icon="pi pi-arrow-left"
        severity="secondary"
        @click="$emit('back')"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Divider from 'primevue/divider'
import ProgressSpinner from 'primevue/progressspinner'
import type { Certificate } from '@/entities/certificate'

defineProps<{
  courseTitle?: string
  certificate: Certificate | null
}>()

const visible = defineModel<boolean>('visible', { default: false })

defineEmits<{
  downloadCert: []
  back: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.course-complete {
  // .text-center is a dead full-Bootstrap class → centering here.
  &__hero {
    text-align: center;
  }

  &__cert-row {
    // .gap-3 is a dead full-Bootstrap class → gap via token here.
    gap: $space-3;
  }

  // Theme-reactive muted text — replaces dead full-Bootstrap .text-muted.
  &__muted {
    color: var(--p-text-muted-color);
  }

  // .text-success / .fw-bold are dead full-Bootstrap classes → tokens here.
  &__cert-ready {
    color: var(--p-green-500);
    font-weight: $font-weight-bold;
  }

  &__trophy {
    font-size: $font-size-icon-2xl; // snap from 3.5rem (56px→48px)
    color: var(--p-yellow-500);
    display: block;
    margin-bottom: $space-3;
  }

  &__congrats {
    font-size: $font-size-md;
    font-weight: $font-weight-medium;
    margin-bottom: $space-3;
  }

  &__cert-icon {
    font-size: $font-size-2xl;
    color: var(--p-green-500);
  }
}
</style>
