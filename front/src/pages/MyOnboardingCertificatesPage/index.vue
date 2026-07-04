<template>
  <div class="certificates-page">
    <ListToolbar
      icon="pi-verified"
      :title="t('onboarding.certificates.title')"
      :subtitle="t('onboarding.certificates.subtitle', { count: certificates.length })"
    />

    <div class="certificates-page__body">
      <!-- Loading -->
      <div v-if="loading" class="row g-3">
        <div v-for="n in 3" :key="n" class="col-md-6 col-lg-4">
          <div class="certificates-page__skeleton-card p-3">
            <Skeleton shape="circle" size="3rem" class="mx-auto mb-3" />
            <Skeleton width="70%" height="20px" class="mx-auto mb-2" />
            <Skeleton width="50%" height="16px" class="mx-auto mb-1" />
            <Skeleton width="40%" height="14px" class="mx-auto mb-3" />
            <Skeleton height="38px" />
          </div>
        </div>
      </div>

      <!-- Error -->
      <Message v-else-if="error" severity="error" :closable="false">
        {{ t('common.loadError') }}
      </Message>

      <!-- Empty -->
      <div v-else-if="certificates.length === 0" class="certificates-page__empty">
        <i class="pi pi-verified certificates-page__empty-icon" />
        <p class="mt-3 certificates-page__empty-title">{{ t('onboarding.certificates.empty') }}</p>
        <p class="certificates-page__empty-hint">{{ t('onboarding.certificates.emptyHint') }}</p>
        <Button
          :label="t('onboarding.certificates.goToCourses')"
          class="mt-3"
          icon="pi pi-book"
          @click="$router.push({ name: 'MyCourses' })"
        />
      </div>

      <!-- Cards -->
      <div v-else class="row g-3">
        <div v-for="cert in certificates" :key="cert.id" class="col-md-6 col-lg-4">
          <CertificateCard
            :cert="cert"
            :downloading="downloading"
            @download="(c) => downloadCertificate(c.assignment_id, c.certificate_number)"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import Button from 'primevue/button'
import ListToolbar from '@/components/shared/ListToolbar.vue'
import CertificateCard from './components/CertificateCard.vue'
import { useMyCertificatesPage } from './composables/useMyCertificatesPage'

const { t } = useI18n()
const { loading, error, certificates, load, downloadCertificate, downloading } = useMyCertificatesPage()

onMounted(async () => {
  await load()
})
</script>

<style lang="scss" scoped>
.certificates-page {
  // .p-4 is a full-Bootstrap padding util absent from the grid-only bundle → scoped.
  &__body {
    padding: $space-4;
  }

  // Card scaffold — full-Bootstrap .card is absent from the grid-only bundle,
  // so the loading tile rendered with no surface.
  &__skeleton-card {
    background: $surface-card;
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-lg;
    box-shadow: var(--app-shadow-card);
    text-align: center;
  }

  &__empty {
    // .text-center / py-6 are dead full-Bootstrap classes → center + spacing here.
    text-align: center;
    padding-block: $space-8;

    &-icon {
      font-size: $font-size-icon-3xl;
      color: var(--p-surface-400);
      display: block;
    }

    &-title {
      font-size: $font-size-lg;
      font-weight: $font-weight-semibold;
    }

    &-hint {
      color: var(--p-surface-500);
      margin: 0;
    }
  }
}
</style>
