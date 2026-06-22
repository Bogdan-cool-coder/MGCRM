<template>
  <div class="entity-header">
    <!--
      Single flex row: avatar-col → info-col (flex:1) → control-col.
      NO separate top-row above avatar. Spec §1.
    -->
    <div class="entity-header__main-row">
      <!-- Avatar -->
      <div class="entity-header__avatar-col">
        <EntityAvatar
          :entity-id="entityId"
          :initials="resolvedInitials"
          size="md"
          on-brand
        />
      </div>

      <!-- Info column -->
      <div class="entity-header__info-col">
        <!-- Title row: name + engagement chip + status badge + category badge -->
        <div class="entity-header__title-row">
          <h2 class="entity-header__title">{{ title }}</h2>
          <!-- Client status badge (company only, optional) -->
          <slot name="status" />
          <!-- Category badge (company only) -->
          <Tag
            v-if="categoryCode"
            :value="categoryCode"
            :severity="categorySeverity"
            size="small"
            class="entity-header__category-tag"
          />
          <EngagementChip
            v-if="engagementTier"
            :tier="engagementTier"
            :last-activity-at="lastActivityAt"
            class="entity-header__engagement"
          />
        </div>

        <!-- Meta row: subtitle (contact position) first, then meta items -->
        <div class="entity-header__meta-row">
          <!-- Subtitle (position for contacts) — first in meta row, 13px muted -->
          <span v-if="subtitle" class="entity-header__subtitle">{{ subtitle }}</span>
          <!-- Author -->
          <span class="entity-header__meta-item">
            <span class="entity-header__meta-label">{{ t('crm.entity.author') }}:</span>
            <span class="entity-header__meta-value">{{ authorName || '—' }}</span>
          </span>
          <!-- Company name (contact only) or Responsible (company only) -->
          <span v-if="companyName !== undefined" class="entity-header__meta-item">
            <span class="entity-header__meta-label">{{ t('crm.entity.primaryCompany') }}:</span>
            <span class="entity-header__meta-value">{{ companyName || '—' }}</span>
          </span>
          <span v-else-if="worksWithName !== undefined" class="entity-header__meta-item">
            <span class="entity-header__meta-label">{{ t('crm.entity.worksWithCompany') }}:</span>
            <span class="entity-header__meta-value">{{ worksWithName || '—' }}</span>
          </span>
          <!-- Source -->
          <span v-if="sourceLabel" class="entity-header__meta-item">
            <span class="entity-header__meta-label">{{ t('crm.entity.source') }}:</span>
            <span class="entity-header__meta-value">{{ sourceLabel }}</span>
          </span>
          <!-- Created -->
          <span v-if="createdAt" class="entity-header__meta-item">
            <span class="entity-header__meta-label">{{ t('crm.entity.createdAt') }}:</span>
            <span class="entity-header__meta-value">{{ formatDate(createdAt) }}</span>
          </span>
          <!-- Updated -->
          <span v-if="updatedAt" class="entity-header__meta-item">
            <span class="entity-header__meta-label">{{ t('crm.entity.updatedAt') }}:</span>
            <span class="entity-header__meta-value">{{ formatDate(updatedAt) }}</span>
          </span>
          <slot name="meta" />
        </div>

        <!-- Tags row (max 3 + +N) -->
        <div v-if="tags && tags.length > 0" class="entity-header__tags-row">
          <i class="pi pi-tag entity-header__tags-icon" />
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

      <!-- Control column: back arrow + ⋮ menu, right-aligned, flex-shrink:0 -->
      <div class="entity-header__control-col">
        <button class="entity-header__btn-icon" :aria-label="t('common.back')" @click="emit('back')">
          <i class="pi pi-arrow-left" />
        </button>
        <button
          class="entity-header__btn-icon"
          :aria-label="t('common.actions')"
          @click="menuRef?.toggle($event)"
        >
          <i class="pi pi-ellipsis-v" />
        </button>
        <EntityActionMenu ref="menuRef" :items="menuItems" />
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
    /** Primary company name (contact only) */
    companyName?: string
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
    /** Acquisition channel / source label */
    sourceLabel?: string | null
    /** ISO date string — record created at */
    createdAt?: string | null
    /** ISO date string — record updated at */
    updatedAt?: string | null
  }>(),
  {
    subtitle: null,
    authorName: null,
    companyName: undefined,
    worksWithName: undefined,
    categoryCode: null,
    engagementTier: null,
    lastActivityAt: null,
    tags: () => [],
    avatarInitials: undefined,
    sourceLabel: null,
    createdAt: null,
    updatedAt: null,
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

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
  } catch {
    return iso
  }
}
</script>

<style lang="scss" scoped>
.entity-header {
  background: $brand-header-bg;
  padding: $space-3 $space-4 $space-4;
  flex-shrink: 0;
}

// ── Single main row: avatar → info → control ──────────────────────────────────

.entity-header__main-row {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
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
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 22px; // brand invariant — entity card header title
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

.entity-header__category-tag {
  flex-shrink: 0;
  margin-top: 2px;
}

// ── Meta row: subtitle as first entry + meta items ────────────────────────────

.entity-header__meta-row {
  display: flex;
  align-items: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  gap: 18px; // brand header overlay — meta gap (not a token)
  flex-wrap: wrap;
  // spec §1: meta row = 12px; $font-size-xs = 0.75rem × 14px = 10.5px is too small;
  // $font-size-sm = 0.875rem × 14px ≈ 12.25px matches spec
  font-size: $font-size-sm;
}

// Subtitle inside meta row — 13px muted white, spec §1
.entity-header__subtitle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.6); // brand header overlay — static subtitle on navy panel
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 13px; // spec §1: subtitle 13px
}

.entity-header__meta-item {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm; // inherits from meta-row — kept explicit to avoid cascade override
}

.entity-header__meta-label {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.4); // brand header overlay — static muted label on navy panel
}

.entity-header__meta-value {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.8); // brand header overlay — static meta text on navy panel
  font-weight: $font-weight-medium;
}

// ── Tags row ───────────────────────────────────────────────────────────────────

.entity-header__tags-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  margin-top: $space-1;
}

.entity-header__tags-icon {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 10px; // brand invariant — small tag icon in navy header
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.4); // brand header overlay
  flex-shrink: 0;
}

.entity-header__tag {
  flex-shrink: 0;
}

// ── Control column: back + ⋮ menu ─────────────────────────────────────────────

.entity-header__control-col {
  display: flex;
  align-items: center;
  gap: 6px; // spec §1: gap 6px between control buttons
  flex-shrink: 0;
  padding-top: 2px; // align with title baseline
}

.entity-header__btn-icon {
  background: transparent;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border: 1px solid rgba(255, 255, 255, 0.22); // brand header overlay — static border on navy
  cursor: pointer;
  color: $sidebar-text-active;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-md; // spec §1 §13: icon-only buttons → radius-md (6px)
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
</style>
