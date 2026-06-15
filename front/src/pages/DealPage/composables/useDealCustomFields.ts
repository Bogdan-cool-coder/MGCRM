/**
 * Deal custom fields composable — loads definitions + values for a deal.
 * Caches definitions in module scope (they rarely change mid-session).
 */
import { ref, computed } from 'vue'
import { customFieldsApi } from '@/api/crm/customFields'
import type { CustomFieldDef } from '@/entities/crm'

// Module-level cache — definitions per scope
const defsCache: Record<string, CustomFieldDef[]> = {}

export function useDealCustomFields(dealId: () => number) {
  const definitions = ref<CustomFieldDef[]>([])
  const values = ref<Record<string, unknown>>({})
  const loading = ref(false)
  const error = ref<unknown>(null)

  const dealCustomDefs = computed(() =>
    definitions.value.filter((d) => d.is_active),
  )

  async function load() {
    loading.value = true
    error.value = null
    try {
      // Load definitions for scope=deal (cached)
      if (!defsCache['deal']) {
        defsCache['deal'] = await customFieldsApi.getDefinitions('deal')
      }
      definitions.value = defsCache['deal']

      // Load current values for this deal
      const res = await customFieldsApi.getDealCustomFields(dealId())
      values.value = res.values ?? {}
    } catch (err) {
      error.value = err
    } finally {
      loading.value = false
    }
  }

  function updateLocalValue(code: string, value: unknown) {
    values.value = { ...values.value, [code]: value }
  }

  return {
    definitions,
    values,
    dealCustomDefs,
    loading,
    error,
    load,
    updateLocalValue,
  }
}
