<template>
  <div class="contact-rail">
    <div v-if="contact.source" class="contact-rail__section">
      <div class="contact-rail__section-title">{{ t('company.page.rail.source') }}</div>
      <Tag :value="directoriesStore.getSourceLabel(contact.source)" severity="secondary" size="small" />
    </div>

    <div v-if="contact.status" class="contact-rail__section">
      <div class="contact-rail__section-title">{{ t('contact.page.fields.status') }}</div>
      <Tag
        :value="contact.status === 'active' ? t('contact.page.status.active') : t('contact.page.status.inactive')"
        :severity="contact.status === 'active' ? 'success' : 'secondary'"
        size="small"
      />
    </div>

    <div class="contact-rail__section">
      <div class="contact-rail__section-title">{{ t('company.page.rail.tags') }}</div>
      <div class="contact-rail__tags">
        <Tag
          v-for="tag in contact.tags"
          :key="tag"
          :value="tag"
          severity="secondary"
          size="small"
        />
        <span v-if="!contact.tags.length" class="contact-rail__empty">—</span>
      </div>
    </div>

    <div class="contact-rail__section">
      <div class="contact-rail__section-title">{{ t('company.page.rail.createdAt') }}</div>
      <div class="contact-rail__date">{{ formatDate(contact.created_at) }}</div>
    </div>
    <div class="contact-rail__section">
      <div class="contact-rail__section-title">{{ t('company.page.rail.updatedAt') }}</div>
      <div class="contact-rail__date">{{ formatDate(contact.updated_at) }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import { useDirectoriesStore } from '@/stores/directories'
import type { Contact } from '@/entities/crm'

defineProps<{
  contact: Contact
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>

<style lang="scss" scoped>
.contact-rail {
  display: flex;
  flex-direction: column;
  gap: $space-5;
}

.contact-rail__section {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-rail__section-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.contact-rail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1;
}

.contact-rail__empty {
  font-size: $font-size-sm;
  color: $surface-400;
}

.contact-rail__date {
  font-size: $font-size-sm;
  color: $surface-700;
}
</style>
