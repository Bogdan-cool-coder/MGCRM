<template>
  <div class="saved-views">
    <Select
      :model-value="modelValue"
      :options="allOptions"
      option-label="label"
      option-value="value"
      option-group-label="label"
      option-group-children="items"
      :loading="isLoading"
      class="saved-views__select"
      @update:model-value="onSelect"
    >
      <template #value="{ value }">
        <span class="saved-views__value">
          <i v-if="value === 'duplicates'" class="pi pi-copy saved-views__icon" />
          <i v-else-if="isDefaultView(value)" class="pi pi-star-fill saved-views__icon saved-views__icon--default" />
          <i v-else class="pi pi-bookmark saved-views__icon" />
          {{ getLabelFor(value) }}
        </span>
      </template>

      <!-- Custom option template for user views (to show actions) -->
      <template #optiongroup="{ option }">
        <span class="saved-views__group-label">{{ option.label }}</span>
      </template>

      <template #footer>
        <div class="saved-views__footer">
          <Button
            :label="t('crm.saved_views.save')"
            icon="pi pi-plus"
            size="small"
            text
            severity="secondary"
            @click.stop="openSaveDialog"
          />
        </div>
      </template>
    </Select>

    <!-- Manage menu (ellipsis for current non-system view) -->
    <Button
      v-if="currentUserView"
      ref="manageBtn"
      icon="pi pi-ellipsis-v"
      text
      severity="secondary"
      size="small"
      :aria-label="t('crm.saved_views.manage')"
      class="saved-views__manage-btn"
      @click="manageMenu?.toggle($event)"
    />
    <Menu
      v-if="currentUserView"
      ref="manageMenu"
      :model="manageMenuItems"
      popup
    />

    <!-- Save current view dialog -->
    <Dialog
      v-model:visible="saveDialogOpen"
      :header="t('crm.saved_views.save')"
      modal
      :style="{ width: '380px' }"
    >
      <div class="saved-views__dialog">
        <div class="saved-views__field">
          <label class="saved-views__label">{{ t('crm.saved_views.nameLabel') }}</label>
          <InputText
            v-model="newViewName"
            class="w-full"
            autofocus
            :placeholder="t('crm.saved_views.namePlaceholder')"
            @keydown.enter="confirmSave"
          />
        </div>
        <div class="saved-views__field">
          <SelectButton
            v-model="newViewType"
            :options="viewTypeOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="saved-views__field saved-views__field--row">
          <Checkbox
            v-model="newViewDefault"
            input-id="sv-default"
            :binary="true"
          />
          <label for="sv-default" class="saved-views__label saved-views__label--inline">
            {{ t('crm.saved_views.setAsDefault') }}
          </label>
        </div>
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="saveDialogOpen = false"
        />
        <Button
          :label="t('common.save')"
          :disabled="!newViewName.trim()"
          :loading="isSaving"
          @click="confirmSave"
        />
      </template>
    </Dialog>

    <!-- Rename dialog -->
    <Dialog
      v-model:visible="renameDialogOpen"
      :header="t('crm.saved_views.rename')"
      modal
      :style="{ width: '360px' }"
    >
      <div class="saved-views__dialog">
        <div class="saved-views__field">
          <label class="saved-views__label">{{ t('crm.saved_views.nameLabel') }}</label>
          <InputText
            v-model="renameValue"
            class="w-full"
            autofocus
            @keydown.enter="confirmRename"
          />
        </div>
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="renameDialogOpen = false"
        />
        <Button
          :label="t('common.save')"
          :disabled="!renameValue.trim()"
          :loading="isUpdating"
          @click="confirmRename"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Menu from 'primevue/menu'
import type { SavedView } from '../composables/useSavedViews'

const props = defineProps<{
  modelValue: string
  savedViews: SavedView[]
  defaultViewId?: string | null
  isLoading?: boolean
  isSaving?: boolean
  isUpdating?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'save': [name: string, type: 'personal' | 'team', makeDefault: boolean]
  'delete': [id: string]
  'set-default': [id: string]
  'rename': [id: string, name: string]
}>()

const { t } = useI18n()

// ── Save dialog ──────────────────────────────────────────────────────────────

const saveDialogOpen = ref(false)
const newViewName = ref('')
const newViewType = ref<'personal' | 'team'>('personal')
const newViewDefault = ref(false)

const viewTypeOptions = [
  { label: t('crm.saved_views.my'), value: 'personal' },
  { label: t('crm.saved_views.team'), value: 'team' },
]

function openSaveDialog() {
  newViewName.value = ''
  newViewType.value = 'personal'
  newViewDefault.value = false
  saveDialogOpen.value = true
}

function confirmSave() {
  if (!newViewName.value.trim()) return
  emit('save', newViewName.value.trim(), newViewType.value, newViewDefault.value)
  saveDialogOpen.value = false
}

// ── Rename dialog ────────────────────────────────────────────────────────────

const renameDialogOpen = ref(false)
const renameValue = ref('')
const renameTargetId = ref<string | null>(null)

function openRename() {
  const view = currentUserView.value
  if (!view) return
  renameTargetId.value = view.id
  renameValue.value = view.name
  renameDialogOpen.value = true
}

function confirmRename() {
  if (!renameValue.value.trim() || !renameTargetId.value) return
  emit('rename', renameTargetId.value, renameValue.value.trim())
  renameDialogOpen.value = false
}

// ── Manage menu (for currently selected user view) ───────────────────────────

const manageBtn = ref<InstanceType<typeof Button> | null>(null)
const manageMenu = ref<InstanceType<typeof Menu> | null>(null)

const currentUserView = computed<SavedView | null>(() => {
  if (props.modelValue === 'default' || props.modelValue === 'duplicates') return null
  return props.savedViews.find((v) => v.id === props.modelValue) ?? null
})

const manageMenuItems = computed(() => {
  const view = currentUserView.value
  if (!view) return []
  return [
    {
      label: view.isDefault
        ? t('crm.saved_views.unsetDefault')
        : t('crm.saved_views.setAsDefault'),
      icon: view.isDefault ? 'pi pi-star' : 'pi pi-star-fill',
      command: () => emit('set-default', view.id),
    },
    {
      label: t('crm.saved_views.rename'),
      icon: 'pi pi-pencil',
      command: () => openRename(),
    },
    { separator: true },
    {
      label: t('common.delete'),
      icon: 'pi pi-trash',
      class: 'p-menuitem--danger',
      command: () => emit('delete', view.id),
    },
  ]
})

// ── Options list ─────────────────────────────────────────────────────────────

const systemViews = computed(() => [
  { label: t('crm.saved_views.default'), value: 'default' },
  { label: t('crm.saved_views.duplicates'), value: 'duplicates' },
])

const myViews = computed(() =>
  props.savedViews.filter((v) => v.type === 'personal'),
)
const teamViews = computed(() =>
  props.savedViews.filter((v) => v.type === 'team'),
)

const allOptions = computed(() => {
  const groups: Array<{ label: string; items: Array<{ label: string; value: string }> }> = [
    {
      label: t('crm.saved_views.default'),
      items: systemViews.value,
    },
  ]
  if (myViews.value.length > 0) {
    groups.push({
      label: t('crm.saved_views.my'),
      items: myViews.value.map((v) => ({
        label: v.isDefault ? `${v.name} ★` : v.name,
        value: v.id,
      })),
    })
  }
  if (teamViews.value.length > 0) {
    groups.push({
      label: t('crm.saved_views.team'),
      items: teamViews.value.map((v) => ({
        label: v.isDefault ? `${v.name} ★` : v.name,
        value: v.id,
      })),
    })
  }
  return groups
})

// ── Helpers ──────────────────────────────────────────────────────────────────

function getLabelFor(value: string): string {
  if (value === 'default') return t('crm.saved_views.default')
  if (value === 'duplicates') return t('crm.saved_views.duplicates')
  const view = props.savedViews.find((v) => v.id === value)
  return view?.name ?? value
}

function isDefaultView(value: string): boolean {
  // System "default" uses star only if it's actually starred by server
  if (value === 'default') return !props.defaultViewId || props.defaultViewId === 'default'
  return props.defaultViewId === value
}

function onSelect(value: string) {
  emit('update:modelValue', value)
}
</script>

<style lang="scss" scoped>
.saved-views {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.saved-views__select {
  min-width: 180px;
}

.saved-views__group-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;

  :global(.app-dark) & {
    color: var(--p-surface-400);
  }
}

.saved-views__value {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.saved-views__icon {
  font-size: $font-size-xs;
  color: $surface-500;
  flex-shrink: 0;

  :global(.app-dark) & {
    color: var(--p-surface-400);
  }

  &--default {
    color: var(--p-yellow-500);

    :global(.app-dark) & {
      color: var(--p-yellow-400);
    }
  }
}

.saved-views__manage-btn {
  flex-shrink: 0;
}

.saved-views__footer {
  padding: $space-2 $space-3;
  border-top: 1px solid $surface-200;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}

.saved-views__dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.saved-views__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &--row {
    flex-direction: row;
    align-items: center;
    gap: $space-2;
  }
}

.saved-views__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  :global(.app-dark) & {
    color: var(--p-surface-200);
  }

  &--inline {
    cursor: pointer;
    font-weight: normal;
  }
}

.w-full {
  width: 100%;
}

// Danger menu item
:global(.p-menuitem--danger .p-menuitem-link) {
  color: var(--p-red-500) !important;

  .p-menuitem-icon {
    color: var(--p-red-500) !important;
  }
}
</style>
