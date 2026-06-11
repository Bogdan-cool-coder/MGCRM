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
