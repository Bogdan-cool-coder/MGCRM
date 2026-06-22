<template>
  <div class="stage-item-wrapper">
    <!-- Main stage row -->
    <div class="stage-item">
      <!-- Drag handle — hidden for system stages (won/lost) -->
      <span v-if="!stage.is_won && !stage.is_lost" class="stage-item__drag-handle pi pi-bars" />

      <!-- Color dot (click → open drawer) -->
      <button
        class="stage-item__dot"
        :style="{ backgroundColor: stage.color ?? 'var(--p-surface-400)' }"
        :title="t('sales.stageEditor.editDrawer.title')"
        @click="emit('edit', stage)"
      />

      <!-- Name inline-rename -->
      <div class="stage-item__name-area" @dblclick="startRename">
        <template v-if="renaming">
          <InputText
            ref="inputRef"
            v-model="localName"
            class="stage-item__rename-input"
            @keydown="onKeydown"
            @blur="commitRename"
            @click.stop
          />
          <Button icon="pi pi-check" size="small" @click.stop="commitRename" />
          <Button
            icon="pi pi-times"
            size="small"
            severity="secondary"
            text
            @click.stop="cancelRename"
          />
        </template>
        <span v-else class="stage-item__name">{{ stage.name }}</span>
      </div>

      <!-- Badges -->
      <Tag
        v-if="stage.is_won || stage.is_lost"
        :value="t('sales.stageEditor.systemStageBadge')"
        severity="secondary"
        size="small"
        class="stage-item__badge"
      />

      <!-- Hidden toggle (only non-system stages) -->
      <div
        v-if="!stage.is_won && !stage.is_lost"
        class="stage-item__toggle-area"
        @click.stop
      >
        <ToggleSwitch
          :model-value="stage.hidden_by_default"
          :title="t('sales.stageEditor.fields.hiddenByDefault')"
          size="small"
          @update:model-value="(v) => emit('toggleHidden', stage.id, v)"
        />
      </div>

      <!-- SLA badge -->
      <span v-if="stage.sla_hours" class="stage-item__sla">
        {{ t('sales.stageEditor.fields.slaHours') }}: {{ stage.sla_hours }}ч
      </span>

      <!-- Actions -->
      <div class="stage-item__actions" @click.stop>
        <Button
          icon="pi pi-pencil"
          severity="secondary"
          text
          size="small"
          :title="t('common.edit')"
          @click="emit('edit', stage)"
        />
        <span
          v-tooltip.top="(stage.is_won || stage.is_lost) ? t('sales.stageEditor.deleteStage.systemTooltip') : undefined"
          :class="{ 'p-disabled': stage.is_won || stage.is_lost }"
          :style="(stage.is_won || stage.is_lost) ? { opacity: '0.4', pointerEvents: 'none' } : undefined"
        >
          <Button
            icon="pi pi-trash"
            severity="danger"
            text
            size="small"
            :disabled="stage.is_won || stage.is_lost"
            :title="t('common.delete')"
            @click="emit('delete', stage.id)"
          />
        </span>
      </div>
    </div>

    <!-- Automation accordion header row -->
    <div class="automation-accordion">
      <button class="automation-accordion__toggle" @click="automationsOpen = !automationsOpen">
        <i
          :class="[
            'pi',
            automationsOpen ? 'pi-chevron-down' : 'pi-chevron-right',
            'automation-accordion__chevron',
          ]"
        />
        <span class="automation-accordion__label">
          {{ t('automation.list.toggle') }} ({{ stageAutomations.length }})
        </span>
      </button>

      <Button
        :label="t('automation.list.addButton')"
        icon="pi pi-plus"
        severity="secondary"
        text
        size="small"
        class="automation-accordion__add-btn"
        @click="emit('addAutomation', stage.id)"
      />
    </div>

    <!-- Accordion body -->
    <div v-if="automationsOpen" class="automation-accordion__body">
      <!-- Loading state -->
      <template v-if="automationsLoading">
        <Skeleton height="32px" border-radius="8px" class="mb-1" />
        <Skeleton height="32px" border-radius="8px" class="mb-1" />
      </template>

      <!-- Error state -->
      <Message v-else-if="automationsError" severity="error" :closable="false" class="mb-1">
        {{ extractErrorMessage(automationsError) }}
        <Button
          :label="t('common.retry')"
          text
          size="small"
          class="ms-2"
          @click="emit('refetchAutomations', stage.id)"
        />
      </Message>

      <!-- Empty state -->
      <div v-else-if="stageAutomations.length === 0" class="automation-accordion__empty">
        <span class="automation-accordion__empty-text">{{ t('automation.list.empty') }}</span>
        <Button
          :label="t('automation.list.addFirstButton')"
          icon="pi pi-plus"
          text
          size="small"
          @click="emit('addAutomation', stage.id)"
        />
      </div>

      <!-- Cards -->
      <template v-else>
        <AutomationInlineCard
          v-for="automation in stageAutomations"
          :key="automation.id"
          :automation="automation"
          class="mb-1"
          @edit="emit('editAutomation', $event)"
          @delete="emit('deleteAutomation', $event)"
          @toggle="(id, isActive) => emit('toggleAutomation', id, isActive)"
        />
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import AutomationInlineCard from './AutomationInlineCard.vue'
import type { PipelineStageDto } from '@/entities/sales'
import type { AutomationDto } from '@/entities/automation'

const props = defineProps<{
  stage: PipelineStageDto
  stageAutomations: AutomationDto[]
  automationsLoading: boolean
  automationsError: unknown
}>()

const emit = defineEmits<{
  edit: [stage: PipelineStageDto]
  delete: [id: number]
  rename: [id: number, name: string]
  toggleHidden: [id: number, value: boolean]
  // Automation emits
  addAutomation: [stageId: number]
  editAutomation: [automation: AutomationDto]
  deleteAutomation: [id: number]
  toggleAutomation: [id: number, isActive: boolean]
  refetchAutomations: [stageId: number]
}>()

const { t } = useI18n()

// ─── Rename logic (unchanged) ──────────────────────────────────────────────────

const renaming = ref(false)
const localName = ref('')
const inputRef = ref<{ $el?: HTMLElement } | null>(null)

function startRename() {
  localName.value = props.stage.name
  renaming.value = true
  nextTick(() => {
    const el =
      inputRef.value?.$el?.querySelector<HTMLInputElement>('input') ??
      (inputRef.value?.$el as HTMLInputElement | null)
    el?.focus()
    el?.select()
  })
}

function cancelRename() {
  renaming.value = false
  localName.value = ''
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    commitRename()
  } else if (e.key === 'Escape') {
    cancelRename()
  }
}

function commitRename() {
  if (!renaming.value) return
  const newName = localName.value.trim()
  if (!newName || newName === props.stage.name) {
    cancelRename()
    return
  }
  renaming.value = false
  emit('rename', props.stage.id, newName)
}

// ─── Automation accordion ──────────────────────────────────────────────────────

const automationsOpen = ref(false)

function extractErrorMessage(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const err = e as Record<string, unknown>
    if ('message' in err && typeof err.message === 'string') return err.message
  }
  return String(e)
}
</script>

<style lang="scss" scoped>
// ─── Stage item wrapper ───────────────────────────────────────────────────────
.stage-item-wrapper {
  display: flex;
  flex-direction: column;
}

.stage-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-md $radius-md 0 0;
  background-color: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-bottom: none;
  transition: background-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-700);
  }

  &__drag-handle {
    cursor: grab;
    color: var(--p-surface-400);
    font-size: $font-size-sm;
    flex-shrink: 0;
    user-select: none;

    &:active {
      cursor: grabbing;
    }
  }

  &__dot {
    width: 16px;
    height: 16px;
    border-radius: $radius-circle;
    flex-shrink: 0;
    border: none;
    cursor: pointer;
    padding: 0;
    transition: transform var(--app-transition-fast);

    &:hover {
      transform: scale(1.2);
    }
  }

  &__name-area {
    flex: 1;
    display: flex;
    align-items: center;
    gap: $space-1;
    min-width: 0;
    cursor: text;
  }

  &__name {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__rename-input {
    flex: 1;
    font-size: $font-size-sm;
  }

  &__badge {
    flex-shrink: 0;
  }

  &__toggle-area {
    flex-shrink: 0;
    display: flex;
    align-items: center;
  }

  &__sla {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    flex-shrink: 0;
    white-space: nowrap;
  }

  &__actions {
    display: flex;
    gap: $space-1;
    flex-shrink: 0;
  }
}

// ─── Automation accordion ─────────────────────────────────────────────────────

.automation-accordion {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-1 $space-3;
  background-color: var(--p-surface-50);
  border: 1px solid var(--p-surface-200);
  border-top: none;
  border-bottom: none;

  .app-dark & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }

  &__toggle {
    display: flex;
    align-items: center;
    gap: $space-2;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    color: var(--p-text-muted-color);

    &:hover {
      color: var(--p-text-color);
    }
  }

  &__chevron {
    font-size: $font-size-3xs; // snap from 0.65rem (≈10.4px→10px)
    transition: transform var(--app-transition-fast);
  }

  &__label {
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
  }

  &__add-btn {
    flex-shrink: 0;
  }
}

.automation-accordion__body {
  padding: $space-2 $space-3;
  background-color: var(--p-surface-50);
  border: 1px solid var(--p-surface-200);
  border-top: none;
  border-radius: 0 0 $radius-md $radius-md;
  display: flex;
  flex-direction: column;
  gap: $space-1;

  .app-dark & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

.automation-accordion__empty {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 0;
}

.automation-accordion__empty-text {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

// Ghost / dragging states
:global(.stage-item--ghost) {
  opacity: 0.5;
  background-color: var(--p-primary-100);
}

:global(.stage-item--dragging) {
  box-shadow: $shadow-dragging;
}
</style>
