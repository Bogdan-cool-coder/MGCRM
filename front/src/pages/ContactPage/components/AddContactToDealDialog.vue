<template>
  <Dialog
    v-model:visible="visible"
    :header="t('crm.contact.deals.addDialog.title')"
    modal
    :style="{ width: '360px' }"
    class="add-contact-to-deal-dialog"
    @hide="reset"
  >
    <div class="add-contact-to-deal-dialog__body">
      <div class="add-contact-to-deal-dialog__field">
        <label class="add-contact-to-deal-dialog__label">
          {{ t('crm.contact.deals.addDialog.dealLabel') }} *
        </label>
        <AutoComplete
          v-model="dealSearch"
          :suggestions="dealSuggestions"
          option-label="title"
          :placeholder="t('crm.contact.deals.addDialog.dealPlaceholder')"
          class="w-full"
          force-selection
          :loading="searching"
          :min-length="1"
          append-to="body"
          @complete="onSearch($event.query)"
          @option-select="onSelect($event.value)"
          @clear="selectedDeal = null"
        />
      </div>
      <Message v-if="formError" severity="error" class="mt-2">{{ formError }}</Message>
    </div>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        @click="visible = false"
      />
      <Button
        :label="t('common.save')"
        :loading="saving"
        :disabled="!selectedDeal"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import AutoComplete from 'primevue/autocomplete'
import Message from 'primevue/message'
import { salesApi } from '@/api/sales'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto } from '@/entities/sales'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  modelValue: boolean
  contactId: number
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  added: []
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const dealSearch = ref<string | DealDto>('')
const dealSuggestions = ref<DealDto[]>([])
const selectedDeal = ref<DealDto | null>(null)
const searching = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)

// ─── Search ───────────────────────────────────────────────────────────────────

let searchTimer: ReturnType<typeof setTimeout> | null = null

async function onSearch(query: string) {
  if (searchTimer) clearTimeout(searchTimer)
  if (!query || query.length < 1) {
    dealSuggestions.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    searching.value = true
    try {
      const result = await salesApi.getDeals({ q: query, per_page: 20, status: 'open' })
      dealSuggestions.value = result.data
    } catch {
      dealSuggestions.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

function onSelect(deal: DealDto) {
  selectedDeal.value = deal
  dealSearch.value = deal.title ?? ''
}

// ─── Submit ───────────────────────────────────────────────────────────────────

async function submit() {
  if (!selectedDeal.value) return
  saving.value = true
  formError.value = null
  try {
    await salesApi.addDealContact(selectedDeal.value.id, { contact_id: props.contactId })
    toast.add({ severity: 'success', summary: t('crm.contact.deals.addDialog.success'), life: 2500 })
    emit('added')
    visible.value = false
  } catch (err) {
    formError.value = getApiErrorMessage(err, t('errors.server_error'))
  } finally {
    saving.value = false
  }
}

function reset() {
  dealSearch.value = ''
  dealSuggestions.value = []
  selectedDeal.value = null
  formError.value = null
}
</script>

<style lang="scss" scoped>
.add-contact-to-deal-dialog__body {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.add-contact-to-deal-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.add-contact-to-deal-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.w-full {
  width: 100%;
}
</style>
