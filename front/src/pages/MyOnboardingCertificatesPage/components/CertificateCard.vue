<template>
  <Card class="certificate-card">
    <template #content>
      <i class="pi pi-verified certificate-card__icon" />
      <h3 class="certificate-card__title">{{ cert.course_title }}</h3>
      <p class="certificate-card__number">{{ t('onboarding.certificates.number') }}: {{ cert.certificate_number }}</p>
      <p class="certificate-card__date">
        {{ t('onboarding.certificates.issuedAt') }}: {{ formattedDate }}
      </p>
    </template>
    <template #footer>
      <Button
        :label="t('onboarding.certificates.download')"
        icon="pi pi-download"
        :loading="downloading"
        class="w-full"
        @click="$emit('download', cert)"
      />
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import type { Certificate } from '@/entities/certificate'

const props = defineProps<{
  cert: Certificate
  downloading?: boolean
}>()

defineEmits<{
  download: [cert: Certificate]
}>()

const { t } = useI18n()

const formattedDate = computed(() =>
  new Date(props.cert.issued_at).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }),
)
</script>

<style lang="scss" scoped>
// Local width util (full-Bootstrap .w-100 is absent from the grid-only bundle).
.w-full {
  width: 100%;
}

.certificate-card {
  // .text-center is a dead full-Bootstrap class → centering here.
  text-align: center;

  &__icon {
    font-size: $font-size-icon-2xl;
    color: var(--p-green-500);
    display: block;
    margin-bottom: 1rem;
  }

  &__title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    margin: 0 0 0.5rem;
  }

  &__number {
    font-size: $font-size-sm;
    color: var(--p-surface-500);
    margin: 0 0 0.25rem;
  }

  &__date {
    font-size: $font-size-xs; // snap from 13px (0.8125rem)
    color: var(--p-surface-400);
    margin: 0;
  }
}
</style>
