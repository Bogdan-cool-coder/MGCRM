import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import {
  inboxApi,
  type InboxFormMeta,
  type InboxFormSubmitResult,
} from '@/api/inbox'
import { getApiErrorMessage, getApiErrorStatus } from '@/utils/errors'

/**
 * Page-composable for the public (anonymous) lead form at /f/:slug.
 *
 * Loads anon-safe meta via GET /forms/public/{slug}, renders the declared
 * fields + a hidden honeypot, and POSTs to .../submit. On success it shows the
 * thank-you text. All requests are unauthenticated — the public endpoints never
 * 401, so the global axios 401 handler is never triggered here.
 */

/** Hidden honeypot field name — mirrors config('inbox.honeypot_field'). */
const HONEYPOT_FIELD = 'website'

export const usePublicLeadFormPage = (slug: string) => {
  const { t } = useI18n()

  const meta = useAsyncResource<InboxFormMeta | null>(null)
  const submitMutation = useMutation<InboxFormSubmitResult>()

  // Field-name → value map for the declared fields (NOT the honeypot).
  const values = reactive<Record<string, string>>({})
  // Hidden honeypot — a real user never fills it; a bot does.
  const honeypot = ref('')

  const fieldErrors = ref<Record<string, string>>({})
  const generalError = ref('')
  const submitted = ref(false)
  const thankYouText = ref<string | null>(null)

  const isMissing = ref(false)

  const isLoading = computed(() => meta.loading.value)
  const isSubmitting = computed(() => submitMutation.isPending.value)
  const fields = computed(() => meta.data.value?.fields ?? [])
  const formName = computed(() => meta.data.value?.name ?? '')

  async function loadMeta(): Promise<void> {
    isMissing.value = false
    try {
      await meta.run(() => inboxApi.formMeta(slug))
      // Seed the value map so v-model bindings are reactive-stable.
      for (const field of meta.data.value?.fields ?? []) {
        if (!(field.name in values)) {
          values[field.name] = ''
        }
      }
    } catch (error: unknown) {
      // 404 → the form does not exist or is inactive (existence is hidden).
      if (getApiErrorStatus(error) === 404) {
        isMissing.value = true
      }
    }
  }

  function validate(): boolean {
    fieldErrors.value = {}
    for (const field of fields.value) {
      const raw = (values[field.name] ?? '').trim()
      if (field.required && raw === '') {
        fieldErrors.value[field.name] = t('inbox.publicForm.required')
        continue
      }
      if (raw === '') {
        continue
      }
      if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) {
        fieldErrors.value[field.name] = t('inbox.publicForm.invalidEmail')
      } else if (field.type === 'phone' && !/^[+\d][\d\s()\-]{4,30}$/.test(raw)) {
        fieldErrors.value[field.name] = t('inbox.publicForm.invalidPhone')
      }
    }
    return Object.keys(fieldErrors.value).length === 0
  }

  async function handleSubmit(): Promise<void> {
    generalError.value = ''
    if (!validate()) {
      return
    }

    // Build the payload: declared field values + the hidden honeypot.
    const payload: Record<string, string> = { [HONEYPOT_FIELD]: honeypot.value }
    for (const field of fields.value) {
      payload[field.name] = values[field.name] ?? ''
    }

    try {
      const result = await submitMutation.run(() => inboxApi.submitForm(slug, payload))
      submitted.value = true
      thankYouText.value = result.thank_you_text
    } catch (error: unknown) {
      generalError.value = getApiErrorMessage(error, t('inbox.publicForm.submitFailed'))
    }
  }

  return {
    formName,
    fields,
    values,
    honeypot,
    honeypotField: HONEYPOT_FIELD,
    fieldErrors,
    generalError,
    isLoading,
    isSubmitting,
    isMissing,
    submitted,
    thankYouText,
    loadMeta,
    handleSubmit,
  }
}
