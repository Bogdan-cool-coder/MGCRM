<template>
  <div class="contact-files-tab">
    <!-- TabHead -->
    <div class="contact-files-tab__head">
      <span class="contact-files-tab__head-title">{{ t('crm.contact.tabs.files') }}</span>
      <div class="contact-files-tab__head-actions">
        <Button
          icon="pi pi-folder-plus"
          :label="t('crm.files.createFolder')"
          severity="secondary"
          size="small"
          outlined
          disabled
        />
        <Button
          icon="pi pi-upload"
          :label="t('crm.files.upload')"
          size="small"
          disabled
        />
      </div>
    </div>

    <!-- TODO B-4: реализовать двухпанельный layout после появления GET /api/contacts/{id}/files -->
    <!-- Empty state: graceful fallback до появления API -->
    <div class="contact-files-tab__coming-soon">
      <i class="pi pi-folder-open contact-files-tab__icon" />
      <p class="contact-files-tab__title">{{ t('crm.files.emptyFiles') }}</p>
      <p class="contact-files-tab__hint">{{ t('crm.files.comingSoon') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.contact-files-tab {
  display: flex;
  flex-direction: column;
  min-height: 300px;
}

// ── TabHead ──────────────────────────────────────────────────────────────────

.contact-files-tab__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.contact-files-tab__head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
}

.contact-files-tab__head-actions {
  display: flex;
  gap: $space-2;
}

// ── Coming soon / empty ───────────────────────────────────────────────────────

.contact-files-tab__coming-soon {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.contact-files-tab__icon {
  font-size: $font-size-icon-2xl;
  color: $surface-300;
}

.contact-files-tab__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.contact-files-tab__hint {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}
</style>
