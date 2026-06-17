<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deals.page.bulk.assignOwnerDialog.title', { n: dealIds.length })"
    modal
    style="width: 420px"
    :closable="!saving"
    class="bulk-assign-dialog"
  >
    <div class="bulk-assign-dialog__body">
      <div class="bulk-assign-dialog__field">
        <label class="bulk-assign-dialog__label">
          {{ t('sales.deals.page.bulk.assignOwnerDialog.owner') }}
          <span class="req">*</span>
        </label>
        <Select
          v-model="selectedUserId"
          :options="users"
          option-label="full_name"
          option-value="id"
          filter
          show-clear
          class="w-full"
          :class="{ 'p-invalid': hasError }"
          :placeholder="t('sales.deals.page.bulk.assignOwnerDialog.ownerPlaceholder')"
          :loading="loadingUsers"
        />
        <small v-if="hasError" class="p-error">
          {{ t('sales.deals.page.bulk.assignOwnerDialog.ownerRequired') }}
        </small>
      </div>
    </div>

    <template #footer>
      <div class="bulk-assign-dialog__footer">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('sales.deals.page.bulk.assignOwnerDialog.apply')"
          :loading="saving"
          @click="onSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { usersApi, type UserOptionDto } from '@/api/users'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'

const props = defineProps<{
  modelValue: boolean
  dealIds: number[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  done: []
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const selectedUserId = ref<number | null>(null)
const hasError = ref(false)
const users = ref<UserOptionDto[]>([])
const loadingUsers = ref(false)

const mutation = useMutation()
const saving = computed(() => mutation.isPending.value)

async function loadUsers() {
  if (users.value.length > 0) return
  loadingUsers.value = true
  try {
    users.value = await usersApi.getUsers()
  } finally {
    loadingUsers.value = false
  }
}

onMounted(() => {
  void loadUsers()
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      selectedUserId.value = null
      hasError.value = false
      void loadUsers()
    }
  },
)

async function onSubmit() {
  if (!selectedUserId.value) {
    hasError.value = true
    return
  }
  hasError.value = false

  await mutation.run(() =>
    salesApi.bulkPatchDeals({
      deal_ids: props.dealIds,
      operation: 'change_owner',
      owner_id: selectedUserId.value,
    }),
  )

  visible.value = false
  emit('done')
}
</script>

<style lang="scss" scoped>
.bulk-assign-dialog {
  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    padding: $space-2 0;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
