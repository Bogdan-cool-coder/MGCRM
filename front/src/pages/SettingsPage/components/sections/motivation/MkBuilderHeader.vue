<template>
  <div class="mkb-card">
    <div class="mkb-header">
      <div class="mkb-header__field mkb-header__field--grow">
        <label class="mkb-label">{{ t('motivation.builder.select_employee') }}</label>
        <AutoComplete
          :model-value="modelEmployee"
          :suggestions="suggestions"
          option-label="full_name"
          complete-on-focus
          :min-length="1"
          dropdown
          :placeholder="t('motivation.builder.select_employee')"
          :invalid="!!error"
          class="w-100"
          @complete="onComplete"
          @update:model-value="onSelect"
        >
          <template #option="{ option }">
            <div class="mkb-option">
              <Avatar :label="initials(option.full_name)" shape="circle" size="normal" class="mkb-option__avatar" />
              <span class="mkb-option__name">{{ option.full_name }}</span>
              <span class="mkb-option__email">{{ option.email }}</span>
            </div>
          </template>
        </AutoComplete>
        <small v-if="error" class="mkb-error">{{ error }}</small>
      </div>

      <div class="mkb-header__field">
        <label class="mkb-label">{{ t('motivation.builder.year') }}</label>
        <Select
          :model-value="year"
          :options="yearOptions"
          option-label="label"
          option-value="value"
          class="mkb-header__year"
          @update:model-value="(v) => emit('update:year', v as number)"
        />
      </div>

      <div class="mkb-header__field">
        <label class="mkb-label">{{ t('motivation.builder.month') }}</label>
        <Select
          :model-value="month"
          :options="monthOptions"
          option-label="label"
          option-value="value"
          class="mkb-header__month"
          @update:model-value="(v) => emit('update:month', v as number)"
        />
      </div>

      <div class="mkb-header__field">
        <Button
          :label="t('motivation.builder.load_card')"
          icon="pi pi-download"
          severity="secondary"
          :disabled="!modelEmployee"
          :loading="loading"
          @click="emit('load')"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AutoComplete, { type AutoCompleteCompleteEvent } from 'primevue/autocomplete'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Avatar from 'primevue/avatar'
import { searchManagers } from '@/api/motivation'
import { useUserStore } from '@/stores/user'
import type { MkManagerOption } from '@/entities/motivation'

defineProps<{
  modelEmployee: MkManagerOption | null
  year: number
  month: number
  yearOptions: { label: string; value: number }[]
  loading: boolean
  error?: string
}>()

const emit = defineEmits<{
  'update:modelEmployee': [value: MkManagerOption | null]
  'update:year': [value: number]
  'update:month': [value: number]
  load: []
}>()

const { t } = useI18n()
const userStore = useUserStore()

const MONTH_KEYS = [
  'january', 'february', 'march', 'april', 'may', 'june',
  'july', 'august', 'september', 'october', 'november', 'december',
]

const monthOptions = MONTH_KEYS.map((k, i) => ({
  label: t(`motivation.card.months.${k}`),
  value: i + 1,
}))

const suggestions = ref<MkManagerOption[]>([])

// director sees only own subordinates (managed_by=me); admin sees all (§6.5 / ОВ-3).
const managedBy = userStore.getUserRole === 'director' ? 'me' : undefined

const onComplete = async (event: AutoCompleteCompleteEvent): Promise<void> => {
  try {
    suggestions.value = await searchManagers({
      q: event.query,
      managed_by: managedBy,
    })
  } catch {
    suggestions.value = []
  }
}

const onSelect = (value: unknown): void => {
  // AutoComplete emits the raw string while typing; only accept object selections.
  if (value && typeof value === 'object') {
    emit('update:modelEmployee', value as MkManagerOption)
  } else if (value == null) {
    emit('update:modelEmployee', null)
  }
}

const initials = (name: string): string => {
  const parts = name.trim().split(/\s+/)
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?'
}
</script>

<style lang="scss" scoped>
@use '@/pages/SettingsPage/components/sections/motivation/mkb-shared' as *;

.mkb-header {
  display: flex;
  align-items: flex-end;
  gap: $space-3;
  flex-wrap: wrap;
}

.mkb-header__field {
  display: flex;
  flex-direction: column;
  gap: $space-2;

  &--grow {
    flex: 1;
    min-width: 240px;
  }
}

.mkb-header__year {
  width: 120px;
}

.mkb-header__month {
  width: 150px;
}

.mkb-option {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.mkb-option__avatar {
  flex-shrink: 0;
}

.mkb-option__name {
  font-weight: $font-weight-medium;
}

.mkb-option__email {
  font-size: $font-size-xs;
  color: $surface-500;
}
</style>
