import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { getLocalizedText } from '@/utils/localization'
import type { DocumentTemplate } from '@/entities/document'
import type { Promotion } from '@/entities/promotion'
import type { EstateSellOptionDto } from '@/api/types/macrodata'
import type { DocumentPreviewParams } from '@/api/types/documents'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

/** Debounce for the live HTML preview re-render when selectors change. */
const PREVIEW_DEBOUNCE_MS = 400
/** Debounce for the async estate-object search-as-you-type. */
const SEARCH_DEBOUNCE_MS = 300

/**
 * Data layer for `/documents/:id` (HTML commercial-proposal flow):
 *   - loads the template (reactive to the route id),
 *   - async-searches MacroData estate objects for the object picker,
 *   - loads active promotions for the discount calculator,
 *   - drives the debounced server-rendered HTML preview.
 *
 * Generation / download / navigation live in the actions composable; this one
 * owns the form state (object / promotion / discount) and exposes it.
 */
export const useDocumentPageData = () => {
  const route = useRoute()
  const { t, locale } = useLocalI18n({ en, ru })
  const { notifyApiError } = useNotifications()
  const { documentService, promotionService, macroDataLookupService } = useServices()

  const documentId = computed(() => Number(route.params.id))

  // ─── Template ────────────────────────────────────────────────────────────
  const template = ref<DocumentTemplate | null>(null)
  const templateLoading = ref(false)

  const loadTemplate = async (id: number) => {
    if (!id || id <= 0) return
    templateLoading.value = true
    try {
      template.value = await documentService.fetchTemplate(id)
    } catch (error: unknown) {
      template.value = null
      notifyApiError(error, t('errors.loadTemplate'))
    } finally {
      templateLoading.value = false
    }
  }

  /**
   * Re-fetch just the template (keeps the current form state — object /
   * promotion / discount selectors). Used after an edit (manual or AI) so the
   * page reflects content changes; the HTML preview re-renders off the watch.
   */
  const reloadTemplate = async () => {
    await loadTemplate(documentId.value)
    if (isHtml.value) await runPreview()
  }

  /** Directly swap in a fresh template (e.g. after a publish/edit DTO). */
  const setTemplate = (next: DocumentTemplate) => {
    template.value = next
  }

  const templateName = computed(() =>
    template.value ? getLocalizedText(template.value.name, locale.value) : '',
  )
  const isHtml = computed(() => template.value?.type === 'html')

  // ─── Object picker (async MacroData search) ───────────────────────────────
  const selectedEstateSellId = ref<number | null>(null)
  const objectOptions = ref<EstateSellOptionDto[]>([])
  const objectsLoading = ref(false)
  const objectsLoadedOnce = ref(false)
  // Persist the label of the chosen object so it survives a search that
  // filtered it out of `objectOptions` (mirrors AsyncSelectFilter).
  const selectedObjectLabel = ref<string | null>(null)
  let searchTimer: ReturnType<typeof setTimeout> | null = null

  const displayedObjectOptions = computed<EstateSellOptionDto[]>(() => {
    const list = [...objectOptions.value]
    const current = selectedEstateSellId.value
    if (current !== null && !list.some((o) => o.value === current)) {
      list.unshift({ value: current, label: selectedObjectLabel.value ?? String(current) })
    }
    return list
  })

  const runObjectSearch = async (q: string) => {
    objectsLoading.value = true
    try {
      objectOptions.value = await macroDataLookupService.searchEstateSells(q)
    } catch (error: unknown) {
      notifyApiError(error, t('errors.searchObjects'))
    } finally {
      objectsLoading.value = false
      objectsLoadedOnce.value = true
    }
  }

  const onObjectDropdownShow = () => {
    if (!objectsLoadedOnce.value) {
      void runObjectSearch('')
    }
  }

  const onObjectFilter = (q: string) => {
    if (searchTimer !== null) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => void runObjectSearch(q ?? ''), SEARCH_DEBOUNCE_MS)
  }

  const onObjectSelect = (value: number | null) => {
    selectedEstateSellId.value = value
    if (value !== null) {
      const chosen = displayedObjectOptions.value.find((o) => o.value === value)
      selectedObjectLabel.value = chosen?.label ?? null
    } else {
      selectedObjectLabel.value = null
    }
  }

  // ─── Promotions / discount calculator ─────────────────────────────────────
  const promotions = ref<Promotion[]>([])
  const promotionsLoading = ref(false)
  const selectedPromotionId = ref<number | null>(null)
  const discount = ref<number>(0)

  const loadPromotions = async () => {
    promotionsLoading.value = true
    try {
      // activeOnly — only currently-active promos are pickable on the КП page.
      promotions.value = await promotionService.fetchAllPromotions(true)
    } catch (error: unknown) {
      notifyApiError(error, t('errors.loadPromotions'))
    } finally {
      promotionsLoading.value = false
    }
  }

  const promotionOptions = computed(() =>
    promotions.value.map((p) => ({
      value: p.id,
      label: getLocalizedText(p.name, locale.value),
    })),
  )

  const selectedPromotion = computed<Promotion | null>(
    () => promotions.value.find((p) => p.id === selectedPromotionId.value) ?? null,
  )

  const discountMin = computed(() => selectedPromotion.value?.discountMin ?? 0)
  const discountMax = computed(() => selectedPromotion.value?.discountMax ?? 0)
  const isPercentDiscount = computed(
    () => selectedPromotion.value?.discountType === 'percent',
  )

  /** Clamp a value into the active promotion's [min, max] range. */
  const clampDiscount = (value: number): number => {
    if (selectedPromotion.value === null) return 0
    const min = discountMin.value
    const max = discountMax.value
    if (Number.isNaN(value)) return min
    if (value < min) return min
    if (value > max) return max
    return value
  }

  const onPromotionSelect = (value: number | null) => {
    selectedPromotionId.value = value
    // Snap the discount into the newly-selected promo's range (or reset to 0
    // when the promotion is cleared — generate without a discount block).
    discount.value = value === null ? 0 : clampDiscount(discount.value)
  }

  const onDiscountInput = (value: number | null) => {
    discount.value = clampDiscount(value ?? 0)
  }

  // PrimeVue Slider emits `number | number[]` (single thumb → number, but the
  // type is the union). Normalise to a single value before clamping.
  const onSliderInput = (value: number | number[]) => {
    const next = Array.isArray(value) ? (value[0] ?? 0) : value
    discount.value = clampDiscount(next)
  }

  // ─── Live HTML preview ─────────────────────────────────────────────────────
  const previewHtml = ref<string>('')
  const previewLoading = ref(false)
  const previewLoadedOnce = ref(false)
  let previewTimer: ReturnType<typeof setTimeout> | null = null

  const previewParams = computed<DocumentPreviewParams>(() => ({
    estate_sell_id: selectedEstateSellId.value ?? undefined,
    promotion_id: selectedPromotionId.value,
    discount: selectedPromotionId.value !== null ? discount.value : null,
    locale: String(locale.value).startsWith('en') ? 'en' : 'ru',
  }))

  const runPreview = async () => {
    const id = documentId.value
    if (!id || id <= 0 || !isHtml.value) return
    previewLoading.value = true
    try {
      previewHtml.value = await documentService.previewHtml(id, previewParams.value)
    } catch (error: unknown) {
      notifyApiError(error, t('errors.preview'))
    } finally {
      previewLoading.value = false
      previewLoadedOnce.value = true
    }
  }

  const schedulePreview = () => {
    if (previewTimer !== null) clearTimeout(previewTimer)
    previewTimer = setTimeout(() => void runPreview(), PREVIEW_DEBOUNCE_MS)
  }

  // Re-render the preview whenever the object / promotion / discount / locale
  // change. The template id is handled separately (initial load below).
  watch(
    () => [
      selectedEstateSellId.value,
      selectedPromotionId.value,
      discount.value,
      locale.value,
    ],
    () => {
      if (isHtml.value) schedulePreview()
    },
  )

  // ─── Initial load (reactive to route id) ──────────────────────────────────
  watch(
    documentId,
    async (id) => {
      // Reset per-document form state when navigating between templates.
      selectedEstateSellId.value = null
      selectedObjectLabel.value = null
      selectedPromotionId.value = null
      discount.value = 0
      previewHtml.value = ''
      previewLoadedOnce.value = false
      objectOptions.value = []
      objectsLoadedOnce.value = false

      await loadTemplate(id)
      await loadPromotions()
      // First preview = bare template (no object yet).
      if (isHtml.value) await runPreview()
    },
    { immediate: true },
  )

  return {
    t,
    locale,
    documentId,
    // template
    template,
    templateLoading,
    templateName,
    isHtml,
    reloadTemplate,
    setTemplate,
    // object picker
    selectedEstateSellId,
    displayedObjectOptions,
    objectsLoading,
    objectsLoadedOnce,
    onObjectDropdownShow,
    onObjectFilter,
    onObjectSelect,
    // promotions / discount
    promotions,
    promotionsLoading,
    promotionOptions,
    selectedPromotionId,
    selectedPromotion,
    discount,
    discountMin,
    discountMax,
    isPercentDiscount,
    onPromotionSelect,
    onDiscountInput,
    onSliderInput,
    // preview
    previewHtml,
    previewLoading,
    previewLoadedOnce,
  }
}
