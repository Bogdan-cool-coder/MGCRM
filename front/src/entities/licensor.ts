/**
 * Licensor entities — our legal entity (licensor) per country.
 * Sensitive data: restricted to admin | lawyer | director.
 */

// ─── Bank account ─────────────────────────────────────────────────────────────

export interface LicensorBankAccountDto {
  id: number
  licensor_id: number
  currency: string
  bank: string
  bank_code_label: string
  bank_code: string
  account: string
  swift: string | null
  is_primary: boolean
  note: string | null
}

export interface StoreLicensorBankAccountPayload {
  currency: string
  bank: string
  bank_code_label: string
  bank_code: string
  account: string
  swift?: string | null
  is_primary?: boolean
  note?: string | null
}

export type PatchLicensorBankAccountPayload = Partial<StoreLicensorBankAccountPayload>

// ─── Licensor entity ──────────────────────────────────────────────────────────

export interface LicensorEntityDto {
  id: number
  country_code: string
  is_default: boolean
  legal_form: string
  full_legal_form: string
  gender_ending_oe: string | null
  name: string
  director_position: string
  director_short: string
  director_genitive: string
  acts_basis: string | null
  tax_id_label: string
  tax_id: string
  address: string
  bank: string
  bank_code_label: string
  bank_code: string
  account: string
  phone: string | null
  email: string | null
  website: string | null
  training_login: string | null
  accounts: LicensorBankAccountDto[]
  created_at: string
  updated_at: string
}

export type StoreLicensorEntityPayload = Omit<
  LicensorEntityDto,
  'id' | 'accounts' | 'created_at' | 'updated_at'
>

export type PatchLicensorEntityPayload = Partial<Omit<StoreLicensorEntityPayload, 'country_code'>>
