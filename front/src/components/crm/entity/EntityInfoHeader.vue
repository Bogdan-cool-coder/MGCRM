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

    <!-- Top section: avatar + info column -->
    <div class="entity-header__top-section">
      <!-- Avatar -->
      <div class="entity-header__avatar-col">
        <EntityAvatar
          :entity-id="entityId"
          :initials="resolvedInitials"
          size="md"
        />
      </div>

      <!-- Info column -->
      <div class="entity-header__info-col">
        <!-- Title + engagement chip + status badge slot -->
        <div class="entity-header__title-row">
          <h2 class="entity-header__title">{{ title }}</h2>
          <EngagementChip
            v-if="engagementTier"
            :tier="engagementTier"
            :last-activity-at="lastActivityAt"
            class="entity-header__engagement"
          />
          <!-- Client status badge (company only, optional) -->
          <slot name="status" />
        </div>

        <!-- Metadata row -->
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

        <!-- Tags row (max 3 + +N) -->
        <div v-if="tags && tags.length > 0" class="entity-header__tags-row">
          <Tag
            v-for="tag in visibleTags"
            :key="tag"
            :value="tag"
            severity="secondary"
            size="small"
            class="entity-header__tag"
          />
          <Tag
            v-if="hiddenTagsCount > 0"
            :value="`+${hiddenTagsCount}`"
            severity="secondary"
            size="small"
            class="entity-header__tag"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import EngagementChip from './EngagementChip.vue'
import EntityActionMenu from './EntityActionMenu.vue'
import EntityAvatar from './EntityAvatar.vue'
import type { EngagementTier } from './EngagementChip.vue'
import type { MenuItem } from 'primevue/menuitem'
import type { CategoryCode } from '@/entities/crm'

const MAX_TAGS = 3

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
    /** Tags list — renders Tag severity="secondary" badges (max 3 + +N) */
    tags?: string[]
    /** Override initials for avatar (auto-computed from title if omitted) */
    avatarInitials?: string
  }>(),
  {
    subtitle: null,
    authorName: null,
    categoryCode: null,
    engagementTier: null,
    lastActivityAt: null,
    tags: () => [],
    avatarInitials: undefined,
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

/** Compute initials from title if not overridden */
const resolvedInitials = computed(() => {
  if (props.avatarInitials) return props.avatarInitials
  return props.title
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 3)
    .map((w) => w[0])
    .join('')
    .toUpperCase() || '?'
})

const visibleTags = computed(() => (props.tags ?? []).slice(0, MAX_TAGS))
const hiddenTagsCount = computed(() => Math.max(0, (props.tags?.length ?? 0) - MAX_TAGS))
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
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.4); // brand header overlay — static muted text on navy panel
  letter-spacing: 0.02em;
}

.entity-header__btn-icon {
  background: transparent;
  border: none;
  cursor: pointer;
  color: $sidebar-text-active;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  transition: background 0.15s;
  padding: 0;

  &:hover {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(255, 255, 255, 0.12); // brand header overlay — static hover on navy panel
  }

  i {
    font-size: $font-size-sm;
  }
}

// ── Top section: avatar + info col ─────────────────────────────────────────────

.entity-header__top-section {
  display: flex;
  gap: $space-3;
  align-items: flex-start;
}

.entity-header__avatar-col {
  flex-shrink: 0;
  padding-top: 2px;
}

.entity-header__info-col {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

// ── Title row ──────────────────────────────────────────────────────────────────

.entity-header__title-row {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  flex-wrap: wrap;
}

.entity-header__title {
  color: $sidebar-text-active;
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

// ── Meta row ───────────────────────────────────────────────────────────────────

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
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.35); // brand header overlay — static muted label on navy panel
}

.entity-header__meta-value {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.75); // brand header overlay — static meta text on navy panel
  font-weight: $font-weight-medium;
  transition: opacity 0.15s;

  &:hover {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: rgba(255, 255, 255, 1); // brand header overlay — static hover text on navy panel
  }

  &--subtitle {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: rgba(255, 255, 255, 0.6); // brand header overlay — static subtitle on navy panel
  }
}

// ── Tags row ───────────────────────────────────────────────────────────────────

.entity-header__tags-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  margin-top: $space-1;
}

.entity-header__tag {
  flex-shrink: 0;
}
</style>
