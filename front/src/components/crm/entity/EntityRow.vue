<template>
  <div class="entity-row">
    <!-- Avatar / icon slot -->
    <div class="entity-row__avatar">
      <slot name="avatar">
        <div class="entity-row__avatar-icon">
          <i :class="['pi', icon ?? 'pi-user']" />
        </div>
      </slot>
    </div>

    <!-- Main content -->
    <div class="entity-row__content">
      <div class="entity-row__top">
        <RouterLink
          v-if="linkTo"
          :to="linkTo"
          class="entity-row__title entity-row__title--link"
        >
          {{ title }}
        </RouterLink>
        <span v-else class="entity-row__title">{{ title }}</span>

        <!-- Primary star -->
        <button
          v-if="isPrimary !== undefined"
          class="entity-row__star"
          :class="{ 'entity-row__star--active': isPrimary }"
          :title="isPrimary ? t('crm.entity.row.primary') : t('crm.entity.row.setPrimary')"
          type="button"
          @click.stop="emit('setPrimary')"
        >
          <i :class="['pi', isPrimary ? 'pi-star-fill' : 'pi-star']" />
        </button>
      </div>

      <div v-if="subtitle" class="entity-row__subtitle">{{ subtitle }}</div>

      <div class="entity-row__tags">
        <Tag
          v-if="tagLabel"
          :value="tagLabel"
          :severity="tagSeverity"
          size="small"
          class="entity-row__tag"
        />
        <slot name="tags" />
      </div>
    </div>

    <!-- Quick-action icons -->
    <div class="entity-row__actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'

withDefaults(
  defineProps<{
    title: string
    subtitle?: string | null
    icon?: string | null
    linkTo?: string | null
    isPrimary?: boolean
    tagLabel?: string | null
    tagSeverity?: 'success' | 'secondary' | 'danger' | 'warning' | 'info'
  }>(),
  {
    subtitle: null,
    icon: null,
    linkTo: null,
    isPrimary: undefined,
    tagLabel: null,
    tagSeverity: 'secondary',
  },
)

const emit = defineEmits<{
  setPrimary: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.entity-row {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
  padding: $space-2 0;
  border-bottom: 1px solid var(--p-surface-100);

  .app-dark & {
    border-bottom-color: var(--p-surface-800);
  }

  &:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }
}

.entity-row__avatar {
  flex-shrink: 0;
}

.entity-row__avatar-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--p-primary-100);
  color: var(--p-primary-color);
  display: flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-300);
  }

  i {
    font-size: 13px;
  }
}

.entity-row__content {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.entity-row__top {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.entity-row__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &--link {
    text-decoration: none;
    color: var(--p-primary-color);

    &:hover {
      text-decoration: underline;
    }
  }
}

.entity-row__star {
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
  flex-shrink: 0;
  color: $surface-300;
  line-height: 1;

  &:hover {
    color: var(--p-yellow-400);
  }

  &--active {
    color: var(--p-yellow-400);
  }

  i {
    font-size: 12px;
  }
}

.entity-row__subtitle {
  font-size: $font-size-xs;
  color: $surface-500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.entity-row__tags {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  margin-top: 2px;
}

.entity-row__tag {
  flex-shrink: 0;
}

.entity-row__actions {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: $space-1;
}
</style>
