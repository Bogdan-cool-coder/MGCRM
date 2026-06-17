<template>
  <div class="certificates-page">
    <PageHeader :title="t('onboarding.certificates.title')" icon="pi pi-award" />

    <div class="p-4">
      <!-- Loading -->
      <div v-if="loading" class="row g-3">
        <div v-for="n in 3" :key="n" class="col-md-6 col-lg-4">
          <div class="card p-3 text-center">
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
      <div v-else-if="certificates.length === 0" class="certificates-page__empty text-center py-6">
        <i class="pi pi-award certificates-page__empty-icon" />
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
import PageHeader from '@/components/AppShell/PageHeader.vue'
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
  &__empty {
    &-icon {
      font-size: 4rem;
      color: var(--p-surface-400);
      display: block;
    }

    &-title {
      font-size: 1.125rem;
      font-weight: 600;
    }

    &-hint {
      color: var(--p-surface-500);
      margin: 0;
    }
  }
}
</style>
