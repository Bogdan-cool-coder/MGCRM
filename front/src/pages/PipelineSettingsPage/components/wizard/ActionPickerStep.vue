<template>
  <div class="action-picker-step">
    <!-- Search -->
    <div class="action-picker-step__search mb-3">
      <IconField>
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="searchQuery"
          :placeholder="t('automation.wizard.step1.searchPlaceholder')"
          fluid
        />
      </IconField>
    </div>

    <!-- Grid -->
    <div class="row row-cols-2 row-cols-md-3 g-3">
      <div v-for="action in filteredActions" :key="action.kind" class="col">
        <div
          :class="[
            'action-card',
            { 'action-card--selected': modelValue === action.kind },
            { 'action-card--disabled': action.disabled },
          ]"
          role="button"
          :tabindex="action.disabled ? -1 : 0"
          @click="!action.disabled && select(action.kind)"
          @keydown.enter="!action.disabled && select(action.kind)"
          @keydown.space.prevent="!action.disabled && select(action.kind)"
        >
          <div class="action-card__icon-wrap">
            <i :class="['pi', action.icon, 'action-card__icon']" />
            <i
              v-if="modelValue === action.kind"
              class="pi pi-check action-card__check"
            />
          </div>
          <div class="action-card__title">{{ action.label }}</div>
          <div class="action-card__desc">{{ action.description }}</div>
          <div v-if="action.badge" class="action-card__badge mt-1">
            <Tag
              :value="action.badge.label"
              :severity="action.badge.severity"
              size="small"
            />
          </div>
        </div>
      </div>
    </div>

    <div v-if="filteredActions.length === 0" class="action-picker-step__empty">
      <i class="pi pi-search" />
      <span>{{ t('automation.wizard.step1.noResults') }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Tag from 'primevue/tag'
import type { ActionKind } from '@/entities/automation'
import { useUserStore } from '@/stores/user'

defineProps<{
  modelValue: ActionKind | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ActionKind]
}>()

const { t } = useI18n()
const userStore = useUserStore()
const isAdmin = computed(() => userStore.getUserRole === 'admin')

const searchQuery = ref('')

interface ActionDef {
  kind: ActionKind
  icon: string
  label: string
  description: string
  disabled?: boolean
  badge?: { label: string; severity: 'warning' | 'info' | 'danger' | 'secondary' | 'success' }
}

const allActions = computed<ActionDef[]>(() => [
  {
    kind: 'tg_notify',
    icon: 'pi-telegram',
    label: t('automation.action.tg_notify'),
    description: t('automation.actionDesc.tg_notify'),
  },
  {
    kind: 'create_task',
    icon: 'pi-clipboard',
    label: t('automation.action.create_task'),
    description: t('automation.actionDesc.create_task'),
  },
  {
    kind: 'set_field',
    icon: 'pi-pencil-square',
    label: t('automation.action.set_field'),
    description: t('automation.actionDesc.set_field'),
  },
  {
    kind: 'generate_document',
    icon: 'pi-file',
    label: t('automation.action.generate_document'),
    description: t('automation.actionDesc.generate_document'),
  },
  {
    kind: 'change_owner',
    icon: 'pi-user-edit',
    label: t('automation.action.change_owner'),
    description: t('automation.actionDesc.change_owner'),
  },
  {
    kind: 'change_stage',
    icon: 'pi-arrow-right-circle',
    label: t('automation.action.change_stage'),
    description: t('automation.actionDesc.change_stage'),
  },
  {
    kind: 'webhook',
    icon: 'pi-wifi',
    label: t('automation.action.webhook'),
    description: t('automation.actionDesc.webhook'),
    disabled: !isAdmin.value,
    badge: !isAdmin.value
      ? undefined
      : { label: t('automation.actionBadge.adminOnly'), severity: 'info' },
  },
  {
    kind: 'email',
    icon: 'pi-envelope',
    label: t('automation.action.email'),
    description: t('automation.actionDesc.email'),
    badge: { label: t('automation.actionBadge.requiresSmtp'), severity: 'warning' },
  },
])

const filteredActions = computed<ActionDef[]>(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return allActions.value
  return allActions.value.filter(
    (a) =>
      a.label.toLowerCase().includes(q) ||
      a.description.toLowerCase().includes(q),
  )
})

function select(kind: ActionKind) {
  emit('update:modelValue', kind)
}
</script>

<style lang="scss" scoped>
.action-picker-step {
  &__search {
    max-width: 360px;
  }

  &__empty {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-6;
    justify-content: center;
    color: var(--p-text-muted-color);
    font-size: $font-size-sm;
  }
}

.action-card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: $space-1;
  padding: $space-3;
  border-radius: $radius-md;
  border: 1px solid var(--p-surface-200);
  background-color: var(--p-surface-0);
  cursor: pointer;
  transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);
  height: 100%;
  user-select: none;

  .app-dark & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }

  &:hover:not(&--disabled):not(&--selected) {
    border-color: var(--p-primary-300);

    .app-dark & {
      border-color: var(--p-primary-600);
    }
  }

  &--selected {
    border: 2px solid var(--p-primary-color);
    background-color: var(--p-primary-50);

    .app-dark & {
      background-color: var(--p-primary-900);
      border-color: var(--p-primary-400);
    }
  }

  &--disabled {
    opacity: 0.45;
    cursor: not-allowed;
    pointer-events: none;
  }

  &__icon-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    margin-bottom: $space-1;
  }

  &__icon {
    font-size: $font-size-xl;
    color: var(--p-primary-color);
  }

  &__check {
    position: absolute;
    right: -8px;
    top: -6px;
    font-size: $font-size-3xs; // snap from 0.65rem (≈10.4px → 10px)
    color: var(--p-primary-color);
    background: var(--p-surface-0);
    border-radius: $radius-circle;
    padding: 1px;

    .app-dark & {
      background: var(--p-primary-900);
    }
  }

  &__title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    line-height: 1.3;
  }

  &__desc {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  &__badge {
    margin-top: auto;
  }
}
</style>
