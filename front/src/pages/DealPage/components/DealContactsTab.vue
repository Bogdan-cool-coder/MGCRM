<template>
  <div class="deal-contacts">
    <div class="deal-contacts__header">
      <h3 class="deal-contacts__title">{{ t('sales.deal.page.contacts.sectionTitle') }}</h3>
      <Button
        icon="pi pi-plus"
        :label="t('sales.deal.page.contacts.addContact')"
        severity="secondary"
        outlined
        size="small"
        @click="emit('addContact')"
      />
    </div>

    <!-- Empty -->
    <div v-if="contacts.length === 0" class="deal-contacts__empty">
      <i class="pi pi-user deal-contacts__empty-icon" />
      <p class="deal-contacts__empty-title">{{ t('sales.deal.page.contacts.empty.title') }}</p>
    </div>

    <!-- Contact list -->
    <div v-else class="deal-contacts__list">
      <div
        v-for="link in contacts"
        :key="link.id"
        class="deal-contacts__item"
      >
        <div class="deal-contacts__item-info">
          <div class="deal-contacts__item-name">
            <RouterLink :to="`/contacts/${link.contact.id}`" class="deal-contacts__link">
              {{ link.contact.full_name }}
            </RouterLink>
            <Tag
              v-if="link.is_primary"
              :value="t('sales.deal.page.contacts.primary')"
              severity="info"
              class="deal-contacts__primary-tag"
            />
          </div>
          <div class="deal-contacts__item-meta">
            <span v-if="link.contact.position" class="deal-contacts__position">
              {{ link.contact.position }}
            </span>
            <span v-if="link.contact.email" class="deal-contacts__email">
              {{ link.contact.email }}
            </span>
            <span v-if="link.contact.phone" class="deal-contacts__phone">
              {{ link.contact.phone }}
            </span>
          </div>
        </div>
        <Button
          icon="pi pi-times"
          text
          severity="secondary"
          size="small"
          :loading="removingId === link.contact.id"
          @click="emit('removeContact', link.contact.id)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { DealContactDto } from '@/entities/sales'

defineProps<{
  contacts: DealContactDto[]
  removingId?: number | null
}>()

const emit = defineEmits<{
  addContact: []
  removeContact: [contactId: number]
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.deal-contacts {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.deal-contacts__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.deal-contacts__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;
}

.deal-contacts__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6;
  text-align: center;
}

.deal-contacts__empty-icon {
  font-size: 2rem;
  color: $surface-400;
}

.deal-contacts__empty-title {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.deal-contacts__list {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.deal-contacts__item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: $space-3;
  background: $surface-50;
  border-radius: $radius-md;
  border: 1px solid $surface-200;

  :global(.app-dark) & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

.deal-contacts__item-info {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.deal-contacts__item-name {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deal-contacts__link {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.deal-contacts__primary-tag {
  font-size: $font-size-xs;
}

.deal-contacts__item-meta {
  display: flex;
  gap: $space-2;
  flex-wrap: wrap;
}

.deal-contacts__position,
.deal-contacts__email,
.deal-contacts__phone {
  font-size: $font-size-xs;
  color: $surface-500;
}
</style>
