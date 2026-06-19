<template>
  <div class="saved-views">
    <Select
      :model-value="modelValue"
      :options="allOptions"
      option-label="label"
      option-value="value"
      option-group-label="label"
      option-group-children="items"
      class="saved-views__select"
      @update:model-value="onSelect"
    >
      <template #value="{ value }">
        <span class="saved-views__value">
          <i v-if="value === 'duplicates'" class="pi pi-copy saved-views__icon" />
          <i v-else-if="isDefault(value)" class="pi pi-star saved-views__icon" />
          <i v-else class="pi pi-bookmark saved-views__icon" />
          {{ getLabelFor(value) }}
        </span>
      </template>
      <template #footer>
        <div class="saved-views__footer">
          <Button
            :label="t('crm.contacts_page.savedViews.save')"
            icon="pi pi-plus"
            size="small"
            text
            severity="secondary"
            @click.stop="openSaveDialog"
          />
        </div>
      </template>
    </Select>

    <!-- Save current view dialog -->
    <Dialog
      v-model:visible="saveDialogOpen"
      :header="t('crm.contacts_page.savedViews.save')"
      modal
      :style="{ width: '380px' }"
    >
      <div class="saved-views__dialog">
        <div class="saved-views__field">
          <label class="saved-views__label">{{ t('contacts.page.quickCreate.title') }}</label>
          <InputText
            v-model="newViewName"
            class="w-full"
            autofocus
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
          @click="confirmSave"
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
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import type { SavedView } from '../composables/useSavedViews'

const props = defineProps<{
  modelValue: string
  savedViews: SavedView[]
  defaultViewId?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'save': [name: string, type: 'personal' | 'team']
}>()

const { t } = useI18n()

const saveDialogOpen = ref(false)
const newViewName = ref('')
const newViewType = ref<'personal' | 'team'>('personal')

const viewTypeOptions = [
  { label: t('crm.contacts_page.savedViews.my'), value: 'personal' },
  { label: t('crm.contacts_page.savedViews.team'), value: 'team' },
]

const systemViews = computed(() => [
  {
    label: t('crm.contacts_page.savedViews.default'),
    value: 'default',
  },
  {
    label: t('crm.contacts_page.savedViews.duplicates'),
    value: 'duplicates',
  },
])

const myViews = computed(() =>
  props.savedViews.filter((v) => v.type === 'personal'),
)
const teamViews = computed(() =>
  props.savedViews.filter((v) => v.type === 'team'),
)

const allOptions = computed(() => {
  const groups = [
    {
      label: t('crm.contacts_page.savedViews.default'),
      items: systemViews.value,
    },
  ]
  if (myViews.value.length > 0) {
    groups.push({
      label: t('crm.contacts_page.savedViews.my'),
      items: myViews.value.map((v) => ({ label: v.name, value: v.id })),
    })
  }
  if (teamViews.value.length > 0) {
    groups.push({
      label: t('crm.contacts_page.savedViews.team'),
      items: teamViews.value.map((v) => ({ label: v.name, value: v.id })),
    })
  }
  return groups
})

function getLabelFor(value: string): string {
  if (value === 'default') return t('crm.contacts_page.savedViews.default')
  if (value === 'duplicates') return t('crm.contacts_page.savedViews.duplicates')
  const view = props.savedViews.find((v) => v.id === value)
  return view?.name ?? value
}

function isDefault(value: string): boolean {
  return value === (props.defaultViewId ?? 'default')
}

function onSelect(value: string) {
  emit('update:modelValue', value)
}

function openSaveDialog() {
  newViewName.value = ''
  newViewType.value = 'personal'
  saveDialogOpen.value = true
}

function confirmSave() {
  if (!newViewName.value.trim()) return
  emit('save', newViewName.value.trim(), newViewType.value)
  saveDialogOpen.value = false
}
</script>

<style lang="scss" scoped>
.saved-views__select {
  min-width: 180px;
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
}

.saved-views__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.w-full {
  width: 100%;
}
</style>
