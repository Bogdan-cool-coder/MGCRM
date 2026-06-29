import { apiClient } from '@/api/client'

/**
 * Inbox (S1.9) — public lead-form intake + authenticated inbound-message triage.
 *
 * Public (unauthenticated) form endpoints are in the `inboxApi.formMeta` /
 * `inboxApi.submitForm` methods below.
 *
 * Authenticated triage endpoints require `inbox.manage` permission
 * (admin / director / manager roles in current gate model).
 */

// ─── Public form types ────────────────────────────────────────────────────────

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

// ─── Authenticated triage types ───────────────────────────────────────────────

export type ChannelKind = 'tg' | 'wa' | 'email' | 'web_form' | 'api'
export type RoutingStatus = 'routed' | 'dedup' | 'failed'

export interface InboundChannel {
  id: number
  name: string
  kind: ChannelKind
}

export interface InboundDealStage {
  id: number
  name: string
}

export interface InboundTargetDeal {
  id: number
  title: string
  stage: InboundDealStage | null
}

export interface InboundMessage {
  id: number
  channel_id: number
  external_id: string | null
  from_identifier: string | null
  from_name: string | null
  subject: string | null
  body: string | null
  raw_payload: Record<string, unknown> | null
  target_deal_id: number | null
  target_deal_created: boolean
  routing_status: RoutingStatus
  /** null = unread */
  read_at: string | null
  received_at: string
  channel: InboundChannel
  target_deal: InboundTargetDeal | null
}

export interface InboundMessageListParams {
  q?: string
  channel_id?: number
  /** channel_kind filter — static enum; no API call needed */
  channel?: ChannelKind
  routing_status?: RoutingStatus
  has_deal?: boolean
  unread?: boolean
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}

export interface PaginatedInboundMessages {
  data: InboundMessage[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface InboxUnreadCountResponse {
  count: number
}

// ─── API client ───────────────────────────────────────────────────────────────

export const inboxApi = {
  // ── Public (unauthenticated) lead-form endpoints ──────────────────────────

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

  // ── Authenticated triage endpoints ────────────────────────────────────────

  /**
   * GET /api/inbox — paginated list of inbound messages with filters.
   * Requires inbox.manage (admin/director/manager).
   */
  async list(params: InboundMessageListParams = {}): Promise<PaginatedInboundMessages> {
    const response = await apiClient.get<PaginatedInboundMessages>('/api/inbox', { params })
    return response.data
  },

  /**
   * GET /api/inbox/{id} — single inbound message detail (does NOT auto-mark read).
   */
  async detail(id: number): Promise<InboundMessage> {
    const response = await apiClient.get<{ data: InboundMessage }>(`/api/inbox/${id}`)
    return response.data.data
  },

  /**
   * GET /api/inbox/unread-count → { count } — lightweight poll for the sidebar badge.
   */
  async unreadCount(): Promise<InboxUnreadCountResponse> {
    const response = await apiClient.get<InboxUnreadCountResponse>('/api/inbox/unread-count')
    return response.data
  },

  /**
   * POST /api/inbox/{id}/read — mark a message as read (set read_at = now).
   * Returns the updated InboundMessage resource.
   */
  async markRead(id: number): Promise<InboundMessage> {
    const response = await apiClient.post<{ data: InboundMessage }>(`/api/inbox/${id}/read`)
    return response.data.data
  },

  /**
   * POST /api/inbox/{id}/unread — clear read_at (mark as unread).
   * Returns the updated InboundMessage resource.
   */
  async markUnread(id: number): Promise<InboundMessage> {
    const response = await apiClient.post<{ data: InboundMessage }>(`/api/inbox/${id}/unread`)
    return response.data.data
  },

  /**
   * POST /api/inbox/{id}/reroute — attempt to re-route a failed message.
   * Returns the updated resource. routing_status may stay 'failed' if there is
   * still no matching pipeline — surface that as informational, NOT an error.
   * TODO: when multiple channels of the same kind exist, switch the channel
   * filter from kind enum to channel_id loaded from /api/channels.
   */
  async reroute(id: number): Promise<InboundMessage> {
    const response = await apiClient.post<{ data: InboundMessage }>(`/api/inbox/${id}/reroute`)
    return response.data.data
  },
}
