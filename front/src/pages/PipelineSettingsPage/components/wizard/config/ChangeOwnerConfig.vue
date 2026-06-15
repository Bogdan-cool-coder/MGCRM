<template>
  <div class="change-owner-config">
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.rule') }} <span class="required">*</span></label>
      <Select
        v-model="rule"
        :options="ruleOptions"
        option-label="label"
        option-value="value"
        fluid
      />
    </div>

    <div v-if="rule === 'round_robin'" class="mb-3">
      <label class="field-label">{{ t('automation.fields.pool') }}</label>
      <MultiSelect
        v-model="pool"
        :options="users"
        option-label="full_name"
        option-value="id"
        :placeholder="t('automation.fields.searchUser')"
        filter
        display="chip"
        fluid
      />
      <small class="field-hint">{{ t('automation.fields.poolHint') }}</small>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import { usersApi } from '@/api/users'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

interface UserOption {
  id: number
  full_name: string
}
const users = ref<UserOption[]>([])

onMounted(async () => {
  try {
    users.value = await usersApi.getUsers()
  } catch {
    // non-critical
  }
})

const rule = ref<string>((props.config.rule as string) ?? 'round_robin')
const pool = ref<number[]>((props.config.pool as number[]) ?? [])

const ruleOptions = computed(() => [
  { label: t('automation.ownerRule.round_robin'), value: 'round_robin' },
  { label: t('automation.ownerRule.by_product'), value: 'by_product' },
  { label: t('automation.ownerRule.by_country'), value: 'by_country' },
  { label: t('automation.ownerRule.by_department'), value: 'by_department' },
])

watch([rule, pool], () => {
  emit('update:config', { rule: rule.value, pool: pool.value })
})

watch(
  () => props.config,
  (v) => {
    rule.value = (v.rule as string) ?? 'round_robin'
    pool.value = (v.pool as number[]) ?? []
  },
  { deep: true },
)
</script>

<style lang="scss" scoped>
.change-owner-config {
  .field-label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
  }

  .field-hint {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    display: block;
    margin-top: $space-1;
  }

  .required {
    color: var(--p-red-500);
    margin-left: 2px;
  }
}
</style>
