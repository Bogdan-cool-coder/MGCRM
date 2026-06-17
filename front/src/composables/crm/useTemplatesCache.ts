/**
 * Shared singleton cache for contract templates list (for generate_document config).
 * All components share one fetch; avoids per-component onMounted storm.
 */
import { ref } from 'vue'
import { templatesApi } from '@/api/templates'

export interface TemplateCacheItem {
  code: string
  title: string
}

const templates = ref<TemplateCacheItem[]>([])
const loading = ref(false)
const loaded = ref(false)

export function useTemplatesCache() {
  async function load(): Promise<void> {
    if (loaded.value || loading.value) return
    loading.value = true
    try {
      const list = await templatesApi.getTemplates({ kind: 'contract' })
      templates.value = list.map((t) => ({ code: t.code, title: t.title }))
      loaded.value = true
    } catch {
      // non-critical
    } finally {
      loading.value = false
    }
  }

  return { templates, loading, load }
}
