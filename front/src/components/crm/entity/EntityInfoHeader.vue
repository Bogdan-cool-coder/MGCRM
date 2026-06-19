<template>
  <div class="entity-header">
    <!-- Top row: back + id + menu -->
    <div class="entity-header__top-row">
      <button class="entity-header__btn-icon" :aria-label="t('common.back')" @click="emit('back')">
        <i class="pi pi-arrow-left" />
      </button>
      <div class="entity-header__spacer" />
      <span class="entity-header__id">#{{ entityId }}</span>
      <button
        ref="menuBtnRef"
        class="entity-header__btn-icon"
        :aria-label="t('common.actions')"
        @click="menuRef?.toggle($event)"
      >
        <i class="pi pi-ellipsis-v" />
      </button>
      <EntityActionMenu ref="menuRef" :items="menuItems" />
    </div>

    <!-- Title + engagement chip -->
    <div class="entity-header__title-row">
      <h2 class="entity-header__title">{{ title }}</h2>
      <EngagementChip
        v-if="engagementTier"
        :tier="engagementTier"
        :last-activity-at="lastActivityAt"
        class="entity-header__engagement"
      />
    </div>

    <!-- Metadata row: slot for custom chips/labels -->
    <div class="entity-header__meta-row">
      <!-- Category chip (company only) -->
      <Tag
        v-if="categoryCode"
        :value="categoryCode"
        :severity="categorySeverity"
        size="small"
        class="entity-header__category-tag"
      />
      <!-- Author -->
      <span class="entity-header__meta-item">
        <span class="entity-header__meta-label">{{ t('crm.entity.author') }}:</span>
        <span class="entity-header__meta-value">{{ authorName || '—' }}</span>
      </span>
      <!-- Works with (company only) -->
      <span v-if="worksWithName !== undefined" class="entity-header__meta-item">
        <span class="entity-header__meta-label">{{ t('crm.entity.worksWithCompany') }}:</span>
        <span class="entity-header__meta-value">{{ worksWithName || '—' }}</span>
      </span>
      <!-- Position (contact only, via subtitle) -->
      <span v-if="subtitle" class="entity-header__meta-item">
        <span class="entity-header__meta-value entity-header__meta-value--subtitle">{{ subtitle }}</span>
      </span>
      <slot name="meta" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import EngagementChip from './EngagementChip.vue'
import EntityActionMenu from './EntityActionMenu.vue'
import type { EngagementTier } from './EngagementChip.vue'
import type { MenuItem } from 'primevue/menuitem'
import type { CategoryCode } from '@/entities/crm'

const props = withDefaults(
  defineProps<{
    entityId: number
    title: string
    /** Optional subtitle (e.g. position for contacts) */
    subtitle?: string | null
    /** Author of the record (owner_user) */
    authorName?: string | null
    /** Responsible person's name (company only) */
    worksWithName?: string
    /** Category code (company only) */
    categoryCode?: CategoryCode | null
    /** Engagement tier from API */
    engagementTier?: EngagementTier | null
    lastActivityAt?: string | null
    menuItems: MenuItem[]
  }>(),
  {
    subtitle: null,
    authorName: null,
    categoryCode: null,
    engagementTier: null,
    lastActivityAt: null,
  },
)

const emit = defineEmits<{
  back: []
}>()

const { t } = useI18n()

const menuRef = ref<InstanceType<typeof EntityActionMenu> | null>(null)

const categorySeverity = computed((): 'danger' | 'warning' | 'success' | 'info' | 'secondary' => {
  const map: Record<CategoryCode, 'danger' | 'warning' | 'success' | 'info'> = {
    L: 'danger',
    M: 'warning',
    S1: 'success',
    S2: 'info',
  }
  return props.categoryCode ? map[props.categoryCode] : 'secondary'
})
</script>

<style lang="scss" scoped>
.entity-header {
  background: $brand-header-bg;
  padding: $space-3 $space-4 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  flex-shrink: 0;
}

.entity-header__top-row {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.entity-header__spacer {
  flex: 1;
}

.entity-header__id {
  font-size: $font-size-xs;
  color: rgba(255, 255, 255, 0.4);
  letter-spacing: 0.02em;
}

.entity-header__btn-icon {
  background: transparent;
  border: none;
  cursor: pointer;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  transition: background 0.15s;
  padding: 0;

  &:hover {
    background: rgba(255, 255, 255, 0.12);
  }

  i {
    font-size: 14px;
  }
}

.entity-header__title-row {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  flex-wrap: wrap;
}

.entity-header__title {
  color: #fff;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.35;
  flex: 1;
  min-width: 0;
}

.entity-header__engagement {
  flex-shrink: 0;
  margin-top: 3px;
}

.entity-header__meta-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.entity-header__category-tag {
  flex-shrink: 0;
}

.entity-header__meta-item {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
}

.entity-header__meta-label {
  color: rgba(255, 255, 255, 0.5);
}

.entity-header__meta-value {
  color: rgba(255, 255, 255, 0.85);
  font-weight: $font-weight-medium;

  &--subtitle {
    color: rgba(255, 255, 255, 0.6);
  }
}
</style>
