import type { LocalizedText } from '@/shared/types'

/**
 * Snake_case mirror of the backend `company_brandings` row
 * (DOCUMENTS.md §`company_brandings`). One-to-one with a company; drives the
 * look of HTML commercial proposals (logo, palette, fonts, header / footer,
 * requisites).
 */

/** Palette tokens applied to a rendered proposal. */
export interface BrandingColorsDto {
  primary: string
  secondary: string
  accent: string
  text: string
  bg: string
}

/** Font family choices for headings vs body copy. */
export interface BrandingFontsDto {
  heading: string
  body: string
}

export interface BrandingDto {
  company_id: number
  /** Path to the logo on disk `public`; `null` until a logo is uploaded. */
  logo_path: string | null
  /**
   * Absolute public URL of the logo (`Storage::disk('public')->url($path)`),
   * `null` when no logo is set. This is what the UI renders — `logo_path` is a
   * bare storage path and would not resolve as an `<img src>`.
   */
  logo_url: string | null
  colors: BrandingColorsDto
  fonts: BrandingFontsDto
  /** Translatable header text rendered at the top of the proposal. */
  header: LocalizedText | null
  /** Translatable footer text rendered at the bottom of the proposal. */
  footer: LocalizedText | null
  /** Free-form company requisites (INN / KPP / address / contacts …). */
  requisites: Record<string, unknown> | null
}

/**
 * Response from `POST /api/companies/{id}/branding/logo`. Unlike the full
 * branding GET/PUT this returns only the freshly-stored logo path + URL.
 */
export interface UploadBrandingLogoDto {
  logo_path: string
  logo_url: string
}

/**
 * Body for `PUT /api/companies/{id}/branding`. All fields optional so a partial
 * patch (e.g. only the palette) is valid; backend merges onto the existing row.
 */
export interface UpdateBrandingRequest {
  colors?: Partial<BrandingColorsDto>
  fonts?: Partial<BrandingFontsDto>
  header?: LocalizedText | null
  footer?: LocalizedText | null
  requisites?: Record<string, unknown> | null
}

/**
 * Response from `POST /api/companies/{id}/branding/logo`. Backend stores the
 * uploaded file on disk `public` and returns only the fresh logo path + URL
 * (NOT the full branding row).
 */
export type UploadBrandingLogoResponseDto = UploadBrandingLogoDto
