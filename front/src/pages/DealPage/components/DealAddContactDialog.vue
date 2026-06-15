<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deal.page.contacts.addDialog.title')"
    modal
    style="width: 480px"
    :closable="!saving"
  >
    <div class="add-contact-dialog">
      <!-- Contact search -->
      <div class="add-contact-dialog__field">
        <label class="add-contact-dialog__label">
          {{ t('sales.deal.page.contacts.addDialog.fields.contact') }} <span class="req">*</span>
        </label>
        <AutoComplete
          v-model="selectedContact"
          :suggestions="contactSuggestions"
          option-label="full_name"
          force-selection
          dropdown
          class="w-full"
          :class="{ 'p-invalid': errors.contact_id }"
          :delay="300"
          @complete="searchContacts($event.query)"
        >
          <template #option="{ option }">
            <div class="add-contact-dialog__contact-option">
              <span>{{ option.full_name }}</span>
              <span v-if="option.email || option.position" class="add-contact-dialog__contact-meta">
                {{ [option.position, option.email].filter(Boolean).join(' · ') }}
              </span>
            </div>
          </template>
        </AutoComplete>
        <small v-if="errors.contact_id" class="p-error">{{ errors.contact_id }}</small>
      </div>

      <!-- Is primary -->
      <div class="add-contact-dialog__field add-contact-dialog__field--row">
        <label class="add-contact-dialog__label">
          {{ t('sales.deal.page.contacts.addDialog.fields.isPrimary') }}
        </label>
        <ToggleSwitch v-model="isPrimary" />
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('sales.deal.page.contacts.addDialog.cancel')"
        severity="secondary"
        text
        :disabled="saving"
        @click="visible = false"
      />
      <Button
        icon="pi pi-plus"
        :label="t('sales.deal.page.contacts.addDialog.save')"
        :loading="saving"
        :disabled="!selectedContact"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import AutoComplete from 'primevue/autocomplete'
import ToggleSwitch from 'primevue/toggleswitch'
import Button from 'primevue/button'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage, getApiErrorStatus, getValidationErrors } from '@/utils/errors'
import { contactsApi } from '@/api/crm/contacts'
import type { DealContactDto } from '@/entities/sales'

interface ContactOption {
  id: number
  full_name: string
  email: string | null
  position: string | null
}

const props = defineProps<{
  modelValue: boolean
  dealId: number
  companyId: number
  onAdd: (dealId: number, payload: { contact_id: number; is_primary: boolean }) => Promise<DealContactDto>
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  added: [contact: DealContactDto]
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const selectedContact = ref<ContactOption | null>(null)
const contactSuggestions = ref<ContactOption[]>([])
const isPrimary = ref(false)
const errors = ref<Record<string, string>>({})

const mutation = useMutation<DealContactDto>()
const saving = computed(() => mutation.isPending.value)

async function searchContacts(query: string) {
  if (!query) {
    contactSuggestions.value = []
    return
  }
  try {
    const result = await contactsApi.list({
      search: query,
      per_page: 15,
      company_id: props.companyId,
    })
    contactSuggestions.value = result.data as ContactOption[]
  } catch {
    contactSuggestions.value = []
  }
}

async function onSubmit() {
  if (!selectedContact.value) return
  errors.value = {}

  try {
    const contact = await mutation.run(() =>
      props.onAdd(props.dealId, {
        contact_id: selectedContact.value!.id,
        is_primary: isPrimary.value,
      }),
    )

    toast.add({
      severity: 'success',
      summary: t('sales.deal.page.contacts.addDialog.success'),
      life: 3000,
    })
    emit('added', contact)
    visible.value = false
    selectedContact.value = null
    isPrimary.value = false
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 409) {
      errors.value.contact_id = t('errors.conflict') ?? 'Контакт уже привязан'
      return
    }
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        errors.value = { contact_id: ve.contact_id ?? '' }
        return
      }
    }
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}
</script>

<style lang="scss" scoped>
.add-contact-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.add-contact-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &--row {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

.add-contact-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.add-contact-dialog__contact-option {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.add-contact-dialog__contact-meta {
  font-size: $font-size-xs;
  color: $surface-400;
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
