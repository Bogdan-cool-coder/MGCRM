import type { LocalizedText } from '@/shared/types'
import type { BrandingColorsDto, BrandingFontsDto } from '@/api/types/branding'

export type BrandingColors = BrandingColorsDto
export type BrandingFonts = BrandingFontsDto

/**
 * Per-company branding — camelCase mirror of `BrandingDto`. Drives the look of
 * rendered HTML commercial proposals.
 */
export interface Branding {
  companyId: number
  logoPath: string | null
  /** Absolute public URL of the logo (for `<img src>`); `null` when unset. */
  logoUrl: string | null
  colors: BrandingColors
  fonts: BrandingFonts
  header: LocalizedText | null
  footer: LocalizedText | null
  requisites: Record<string, unknown> | null
}
