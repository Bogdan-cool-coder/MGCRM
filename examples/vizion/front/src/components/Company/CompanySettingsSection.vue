<template>
  <div class="company-settings-section">
    <div v-if="company" class="settings-grid">
      <div class="setting-item">
        <span class="setting-label">{{ t('settingsName') }}</span>
        <span class="setting-value">{{ company.name }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">{{ t('settingsSystem') }}</span>
        <span class="setting-value">{{ company.is_system ? t('yes') : t('no') }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">{{ t('settingsCrmUrl') }}</span>
        <span class="setting-value">{{ company.crm_url || '—' }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">{{ t('settingsCurrency') }}</span>
        <span class="setting-value">{{ company.currency_code || '—' }}</span>
      </div>
      <div class="setting-item">
        <span class="setting-label">{{ t('settingsTimezone') }}</span>
        <span class="setting-value">{{ company.timezone || '—' }}</span>
      </div>
      <div v-if="company.macrodata_host" class="setting-item">
        <span class="setting-label">{{ t('settingsHost') }}</span>
        <span class="setting-value">{{ company.macrodata_host }}</span>
      </div>
      <div v-if="company.macrodata_port" class="setting-item">
        <span class="setting-label">{{ t('settingsPort') }}</span>
        <span class="setting-value">{{ company.macrodata_port }}</span>
      </div>
      <div v-if="company.macrodata_database" class="setting-item">
        <span class="setting-label">{{ t('settingsDatabase') }}</span>
        <span class="setting-value">{{ company.macrodata_database }}</span>
      </div>
      <div v-if="company.macrodata_username" class="setting-item">
        <span class="setting-label">{{ t('settingsUsername') }}</span>
        <span class="setting-value">{{ company.macrodata_username }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { Company } from '@/entities/company'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

defineProps<{
  company: Company | null
}>()

defineEmits<{
  edit: []
}>()
</script>

<style lang="scss" scoped>
.company-settings-section {
  .settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;

    @media (max-width: 767px) {
      grid-template-columns: 1fr;
      gap: 0.75rem;
    }

    .setting-item {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;

      .setting-label {
        font-size: $font-size-xs;
        color: $surface-600;
        text-transform: uppercase;
        font-weight: $font-weight-semibold;
      }

      .setting-value {
        font-size: $font-size-sm;
        color: $surface-900;
      }
    }
  }
}
</style>
