import { apiClient } from '@/api/client'

/**
 * Inbox (S1.9) — public lead-form intake. Namespaced as `inboxApi` /
 * `InboxFormField` to avoid collision with the unrelated communication channels
 * (`/api/contacts/{id}/channels`) and CRM acquisition channels.
 *
 * Only the public (unauthenticated) form endpoints live here. Admin channel /
 * form / inbound-log management is deferred to the integrations sprint.
 */

/** A single declared field of a public form (anon-safe meta). */
export interface InboxFormField {
  name: string
  label: string
  /** 'text' | 'email' | 'phone' | textarea/other (rendered as text). */
  type: string
  required?: boolean
}

/** GET /api/forms/public/{slug} → anon-safe form metadata. */
export interface InboxFormMeta {
  name: string
  fields: InboxFormField[]
  thank_you_text: string | null
}

/** POST /api/forms/public/{slug}/submit → idempotent ack. */
export interface InboxFormSubmitResult {
  ok: boolean
  thank_you_text: string | null
  deal_created: boolean
  deal_id: number | null
}

export const inboxApi = {
  /**
   * GET /api/forms/public/{slug} — anon-safe form meta. 404 for an inactive /
   * missing form.
   */
  async formMeta(slug: string): Promise<InboxFormMeta> {
    const response = await apiClient.get<{ data: InboxFormMeta }>(
      `/api/forms/public/${encodeURIComponent(slug)}`,
    )
    return response.data.data
  },

  /**
   * POST /api/forms/public/{slug}/submit — submit the form. The body is the
   * field-name → value map (plus the hidden honeypot field). 201 with an
   * idempotent ack on success / dedup / silent honeypot; 400 on validation.
   */
  async submitForm(
    slug: string,
    payload: Record<string, string>,
  ): Promise<InboxFormSubmitResult> {
    const response = await apiClient.post<{ data: InboxFormSubmitResult }>(
      `/api/forms/public/${encodeURIComponent(slug)}/submit`,
      payload,
    )
    return response.data.data
  },
}
