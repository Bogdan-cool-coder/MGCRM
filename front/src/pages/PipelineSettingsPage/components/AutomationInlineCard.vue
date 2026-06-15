<template>
  <div class="automation-inline-card">
    <div class="automation-inline-card__icon-col">
      <i :class="['pi', actionIcon, 'automation-inline-card__icon']" />
    </div>

    <div class="automation-inline-card__body">
      <div class="automation-inline-card__name">{{ automation.name }}</div>
      <div class="automation-inline-card__meta">
        {{ triggerLabel }} &rarr; {{ actionLabel }}
      </div>
    </div>

    <div class="automation-inline-card__controls" @click.stop>
      <ToggleSwitch
        :model-value="automation.is_active"
        size="small"
        :title="automation.is_active ? t('automation.toast.deactivated') : t('automation.toast.activated')"
        @update:model-value="onToggle"
      />
      <Button
        icon="pi pi-pencil"
        severity="secondary"
        text
        size="small"
        :title="t('common.edit')"
        @click="emit('edit', automation)"
      />
      <Button
        icon="pi pi-trash"
        severity="danger"
        text
        size="small"
        :title="t('common.delete')"
        @click="onDelete"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import type { AutomationDto, ActionKind } from '@/entities/automation'

const props = defineProps<{
  automation: AutomationDto
}>()

const emit = defineEmits<{
  edit: [automation: AutomationDto]
  delete: [id: number]
  toggle: [id: number, isActive: boolean]
}>()

const { t } = useI18n()
const confirm = useConfirm()

// ─── Icons per action_kind ────────────────────────────────────────────────────

const ACTION_ICONS: Record<ActionKind, string> = {
  tg_notify: 'pi-telegram',
  create_task: 'pi-clipboard',
  set_field: 'pi-pencil-square',
  generate_document: 'pi-file',
  change_owner: 'pi-user-edit',
  change_stage: 'pi-arrow-right-circle',
  webhook: 'pi-wifi',
  email: 'pi-envelope',
}

const actionIcon = computed<string>(
  () => ACTION_ICONS[props.automation.action_kind] ?? 'pi-bolt',
)

const triggerLabel = computed<string>(
  () => t(`automation.trigger.${props.automation.trigger_kind}`),
)

const actionLabel = computed<string>(
  () => t(`automation.action.${props.automation.action_kind}`),
)

// ─── Handlers ─────────────────────────────────────────────────────────────────

function onToggle(value: boolean) {
  emit('toggle', props.automation.id, value)
}

function onDelete() {
  confirm.require({
    header: t('automation.toast.deleteConfirm'),
    message: t('automation.toast.deleteBody'),
    acceptLabel: t('common.delete'),
    rejectLabel: t('common.cancel'),
    acceptProps: { severity: 'danger' },
    accept: () => {
      emit('delete', props.automation.id)
    },
  })
}
</script>

<style lang="scss" scoped>
.automation-inline-card {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-3;
  border-radius: $radius-md;
  background-color: var(--p-surface-0);
  border: 1px solid var(--p-surface-200);
  transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);

  .app-dark & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }

  &:hover {
    border-color: var(--p-surface-300);

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__icon-col {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: $radius-sm;
    background-color: var(--p-primary-50);

    .app-dark & {
      background-color: var(--p-primary-900);
    }
  }

  &__icon {
    font-size: $font-size-sm;
    color: var(--p-primary-color);
  }

  &__body {
    flex: 1;
    min-width: 0;
  }

  &__name {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__meta {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__controls {
    display: flex;
    align-items: center;
    gap: $space-1;
    flex-shrink: 0;
  }
}
</style>
