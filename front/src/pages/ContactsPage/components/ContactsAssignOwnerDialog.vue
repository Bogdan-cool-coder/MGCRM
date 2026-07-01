<template>
  <Dialog
    v-model:visible="visible"
    :header="t('crm.contacts_page.bulk.assignResponsible')"
    modal
    :style="{ width: '360px' }"
  >
    <div class="assign-dialog__field">
      <label class="assign-dialog__label">{{ t('crm.entity.responsible') }}</label>
      <Select
        v-model="selectedUserId"
        :options="users"
        option-label="full_name"
        option-value="id"
        filter
        show-clear
        class="w-full"
        :placeholder="t('crm.entity.responsible')"
      />
    </div>
    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="visible = false" />
      <Button
        :label="t('common.apply')"
        :disabled="selectedUserId === null"
        :loading="loading"
        @click="onApply"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Button from 'primevue/button'

const props = defineProps<{
  modelValue: boolean
  users: { id: number; full_name: string }[]
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  apply: [userId: number]
}>()

const { t } = useI18n()

const visible = ref(props.modelValue)
const selectedUserId = ref<number | null>(null)

watch(() => props.modelValue, (v) => { visible.value = v; if (v) selectedUserId.value = null })
watch(visible, (v) => emit('update:modelValue', v))

function onApply() {
  if (selectedUserId.value === null) return
  emit('apply', selectedUserId.value)
}
</script>

<style lang="scss" scoped>
.assign-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}
.assign-dialog__label {
  font-size: $font-size-sm;
  color: $surface-600;
}
.w-full { width: 100%; }
</style>
