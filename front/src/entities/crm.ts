/**
 * CRM entities — Contact, Company, M2M links, Dedup, Directories.
 * Typed manually from Laravel API Resources (S1.1).
 */

// ─── Directory types ──────────────────────────────────────────────────────────

export interface CompanyType {
  id: number
  name: string
  description: string | null
  sort_order: number
  is_active: boolean
}

export interface Source {
  id: number
  code: string
  name: string
  sort_order: number
  is_active: boolean
}

export interface Country {
  id: number
  code: string
  name: string
  name_en: string
  phone_prefix: string | null
  sort_order: number
  is_active: boolean
}

export interface City {
  id: number
  country_code: string
  name: string
  sort_order: number
  is_active: boolean
}

export interface ContactPosition {
  id: number
  name: string
  sort_order: number
  is_active: boolean
}

// ─── Contact ──────────────────────────────────────────────────────────────────

export type ContactStatus = 'active' | 'inactive'

export interface Contact {
  id: number
  full_name: string
  position: string | null
  phone: string | null
  email: string | null
  tg_username: string | null
  notes: string | null
  source: string | null
  status: ContactStatus | null
  tags: string[]
  extra_fields: Record<string, unknown>
  owner_id: number | null
  owner?: { id: number; full_name: string } | null
  company_links?: ContactCompanyLink[]
  // Acquisition channel (N1)
  acquisition_channel_id: number | null
  created_at: string | null
  updated_at: string | null
}

// ─── Company ──────────────────────────────────────────────────────────────────

export type CategoryCode = 'L' | 'M' | 'S1' | 'S2'
export type HoldingRole = 'parent' | 'child'

export interface Company {
  id: number

  // Identity
  name: string
  legal_name: string | null
  short_name: string | null

  // Legal requisites
  full_legal_form: string | null
  legal_form: string | null
  gender_ending_oe: string | null
  director_position: string | null
  director_genitive: string | null
  director_short: string | null
  acts_basis: string | null
  tax_id_label: string | null
  tax_id: string | null
  address: string | null
  bank: string | null
  bank_code_label: string | null
  bank_code: string | null
  account: string | null

  // Contact
  phone: string | null
  email: string | null
  website: string | null
  notes: string | null

  // Geo
  country_code: string | null
  city: string | null

  // Classification
  source: string | null
  industry: string | null
  company_type_id: number | null
  company_type?: CompanyType | null

  // Holding
  holding_id: number | null
  holding_role: HoldingRole | null

  // Ownership
  responsible_user_id: number | null
  owner_user_id: number | null
  department_id: number | null
  responsible_user?: { id: number; full_name: string } | null
  owner_user?: { id: number; full_name: string } | null

  // Tags & custom fields
  tags: string[]
  extra_fields: Record<string, unknown>

  // Category
  category_code: CategoryCode | null

  // Specialization (N1 enum)
  specialization: 'agency' | 'developer' | 'builder' | 'contractor' | 'supplier' | 'partner' | null

  // Acquisition channel (N1)
  acquisition_channel_id: number | null

  // Client status (N5)
  client_status: ClientStatus | null
  unique_client_since: string | null
  disconnected_at: string | null
  turnover_rub: number | null
  category_recalc_at: string | null

  // Relations
  contact_links?: ContactCompanyLink[]

  created_at: string | null
  updated_at: string | null
}

// ─── M2M Link ─────────────────────────────────────────────────────────────────

export type EmploymentStatus = 'works' | 'left'

export interface ContactCompanyLink {
  id: number
  contact_id: number
  company_id: number
  position: string | null
  position_id: number | null
  employment_status: EmploymentStatus | null
  is_primary: boolean
  created_at: string | null
  updated_at: string | null
  company?: Company | null
  contact?: Contact | null
}

// ─── Dedup ────────────────────────────────────────────────────────────────────

export type DedupScope = 'company' | 'contact'

export interface DedupCandidate {
  id: number
  type: DedupScope
  // Contact fields
  full_name?: string
  email?: string | null
  phone?: string | null
  source?: string | null
  status?: string | null
  // Company fields
  name?: string
  legal_name?: string | null
  tax_id?: string | null
  created_at: string | null
}

export interface DedupGroup {
  key: string
  entities: DedupCandidate[]
}

// ─── Pagination ───────────────────────────────────────────────────────────────

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
  links?: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
}

// ─── List-item union (for ContactsPage DataTable) ─────────────────────────────

export type ContactListItem = Contact & { _type: 'contact' }
export type CompanyListItem = Company & { _type: 'company' }
export type CrmListItem = ContactListItem | CompanyListItem

// ─── Engagement ───────────────────────────────────────────────────────────────

export type EngagementTier = 'fresh' | 'cooling' | 'cold'

// Extended Contact with Slice 1 fields
export interface ContactExtended extends Contact {
  last_activity_at: string | null
  engagement_tier: EngagementTier | null
  /** loaded via /api/contacts/{id} show (not list) */
  channels?: ContactChannel[]
}

// Extended Company with Slice 1 fields
export interface CompanyExtended extends Company {
  last_activity_at: string | null
  engagement_tier: EngagementTier | null
  deal_totals?: DealTotalsDto | null
}

export interface DealTotalsDto {
  per_currency: Record<string, number>  // kopecks
  base_total: number                     // kopecks
  base_currency: string
  open_count: number
  as_of_date: string
}

// ─── Contact Relation (Slice 1) ───────────────────────────────────────────────

export type RelationType = 'partner' | 'referrer' | 'colleague' | 'friend' | 'investor' | 'mentor' | 'other'

export interface ContactRelation {
  id: number
  relation_type: RelationType
  note: string | null
  contact: { id: number; full_name: string }
  related_contact: { id: number; full_name: string }
  created_by?: { id: number; full_name: string } | null
  created_at: string
}

// ─── Holding Tree ─────────────────────────────────────────────────────────────

export interface HoldingCompanyNode {
  id: number
  name: string
  holding_role: HoldingRole | null
  you_are_here: boolean
}

export interface HoldingTreeDto {
  company: HoldingCompanyNode
  ancestors: HoldingCompanyNode[]
  children: HoldingCompanyNode[]
}

// ─── Contact Channel (Phase G) ────────────────────────────────────────────────

export type ChannelType = 'phone' | 'email' | 'tg' | 'wa' | 'linkedin' | 'instagram' | 'viber'

export interface ContactChannel {
  id: number
  contact_id: number
  channel_type: ChannelType
  value: string
  is_primary_for_channel: boolean
  created_at: string | null
  updated_at: string | null
}

// ─── Custom Field Definition (scope=deal|company) ─────────────────────────────

export type CustomFieldScope = 'deal' | 'company' | 'contact'
export type CustomFieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'select'
  | 'multiselect'
  | 'date'
  | 'url'
  | 'bool'
  | 'boolean'
  | 'user_ref'

export interface CustomFieldDef {
  id: number
  scope: CustomFieldScope
  code: string
  label: string
  field_type: CustomFieldType
  options?: string[] | null
  sort_order: number
  is_active: boolean
}

export interface DealCustomFieldsResponse {
  definitions: CustomFieldDef[]
  values: Record<string, unknown>
}

// ─── Acquisition Channel (directories) ───────────────────────────────────────

export interface AcquisitionChannel {
  id: number
  name: string
  sort_order: number
  is_active: boolean
}

// ─── Disconnect Reason (directories, N6) ─────────────────────────────────────

export interface DisconnectReason {
  id: number
  name: string
  sort_order: number
  is_active: boolean
}

// ─── Channel History ──────────────────────────────────────────────────────────

export interface ChannelHistoryEntry {
  id: number
  from_channel: string | null
  to_channel: string | null
  changed_by_name: string | null
  changed_at: string
}

// ─── Client status ────────────────────────────────────────────────────────────

export type ClientStatus = 'prospect' | 'active' | 'disconnected'

export interface CompanyClientStatusLogEntry {
  id: number
  company_id: number
  old_status: ClientStatus | null
  new_status: ClientStatus
  changed_by: number | null
  changed_by_user: { id: number; full_name: string } | null
  changed_at: string | null
  reason_id: number | null
  reason: { id: number; name: string } | null
  meta: Record<string, unknown> | null
  created_at: string | null
}

// ─── Company Requisite (N2) ───────────────────────────────────────────────────

export interface CompanyRequisiteBankDetails {
  bank: string | null
  account: string | null
  bik: string | null
}

export interface CompanyRequisite {
  id: number
  company_id: number
  label: string | null
  legal_name: string
  full_legal_form: string | null
  tax_id_label: string | null
  tax_id: string | null
  country_code: string | null
  director: string | null
  director_genitive: string | null
  address: string | null
  bank_details: CompanyRequisiteBankDetails | null
  is_current: boolean
  valid_from: string | null
  valid_to: string | null
  note: string | null
  created_at: string | null
  updated_at: string | null
}

export interface CreateRequisitePayload {
  label?: string | null
  legal_name: string
  full_legal_form?: string | null
  tax_id_label?: string | null
  tax_id?: string | null
  country_code?: string | null
  director?: string | null
  director_genitive?: string | null
  address?: string | null
  bank?: string | null
  account?: string | null
  bik?: string | null
  valid_from?: string | null
  note?: string | null
  set_as_current?: boolean
}

export type UpdateRequisitePayload = Partial<CreateRequisitePayload>

// ─── Entity Log (action log / audit trail) ────────────────────────────────────

export type EntityLogEventType =
  | 'created'
  | 'updated'
  | 'stage_changed'
  | 'contact_added'
  | 'contact_removed'
  | 'task_completed'
  | 'meeting_held'
  | 'note_added'
  | 'document_created'
  | 'document_signed'
  | 'finance_added'
  | 'status_changed'
  | 'employee_added'
  | 'employee_removed'
  | 'relation_added'
  | 'relation_removed'
  | 'custom_field_changed'

export interface EntityLogEntry {
  id: number
  event_type: EntityLogEventType
  /** Human-readable description from backend */
  description: string | null
  /** Old value (for field changes / stage changes) */
  old_value: string | null
  /** New value (for field changes / stage changes) */
  new_value: string | null
  /** Extra metadata (arbitrary key-value pairs from backend) */
  meta: Record<string, unknown> | null
  user: { id: number; full_name: string } | null
  created_at: string
}

export interface EntityLogPaginatedResponse {
  data: EntityLogEntry[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
