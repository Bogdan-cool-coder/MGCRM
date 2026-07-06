/**
 * useCreateDeal — instant-create helper shared by every «Новая сделка» entry-point
 * (kanban toolbar, list, company/contact tabs, command palette, quick-actions, and
 * the /deals/new compatibility shim).
 *
 * Deal Create 2.0: no more intermediate form. Any create button POSTs a minimal
 * body (server fills title/stage/owner/currency defaults) and redirects straight
 * into the full card at /deals/{id}. `company_id` may be null; `contact_id` is
 * carried into the card via ?link_contact= so the card links it after bootstrap.
 *
 * Server-state writes go through useMutation (no bare axios in the composable is
 * exposed to callers; salesApi.createDeal wraps the apiClient POST).
 */
import { ref, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { salesApi } from '@/api/sales'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto } from '@/entities/sales'

export interface CreateDealInstantParams {
  /** Pipeline for the new deal. When omitted, the first sales pipeline is used. */
  pipeline_id?: number | null
  /** Optional company to pre-link (e.g. from a company card / deals tab). */
  company_id?: number | null
  /** Optional contact to link after redirect (carried via ?link_contact=). */
  contact_id?: number | null
}

export interface UseCreateDeal {
  /** True while a create request is in flight (drives :loading on the trigger). */
  creating: Ref<boolean>
  /**
   * Instantly creates a deal and replaces the route with /deals/{id}.
   * Returns the created deal on success, or null on failure (toast shown).
   */
  createDealInstant: (params?: CreateDealInstantParams) => Promise<DealDto | null>
}

export function useCreateDeal(): UseCreateDeal {
  const router = useRouter()
  const { t } = useI18n()
  const toast = useToast()
  const mutation = useMutation<DealDto>()

  // Double-click guard: a fast second click while a POST is in flight must not
  // create a second deal.
  const creating = ref(false)

  async function resolvePipelineId(explicit?: number | null): Promise<number | null> {
    if (explicit != null) return explicit
    try {
      const pipelines = await salesApi.getPipelines('sales')
      return pipelines[0]?.id ?? null
    } catch {
      return null
    }
  }

  async function createDealInstant(
    params: CreateDealInstantParams = {},
  ): Promise<DealDto | null> {
    if (creating.value) return null
    creating.value = true
    try {
      const pipelineId = await resolvePipelineId(params.pipeline_id)
      if (pipelineId == null) {
        toast.add({
          severity: 'error',
          summary: t('sales.deal.instant.noPipeline'),
          life: 4000,
        })
        return null
      }

      const created = await mutation.run(() =>
        salesApi.createDeal({
          title: t('sales.deal.instant.defaultTitle'),
          pipeline_id: pipelineId,
          // company_id only when provided — null/omitted is valid (Deal Create 2.0).
          ...(params.company_id != null ? { company_id: params.company_id } : {}),
        }),
      )

      // Carry a contact to auto-link into the card (DealPage links it post-bootstrap
      // and strips the query). company link is already applied server-side above.
      const query = params.contact_id != null
        ? { link_contact: String(params.contact_id) }
        : {}
      await router.replace({ path: `/deals/${created.id}`, query })
      return created
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
      return null
    } finally {
      creating.value = false
    }
  }

  return { creating, createDealInstant }
}
