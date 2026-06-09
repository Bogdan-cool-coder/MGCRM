import type { BrandingDto } from '@/api/types/branding'
import type { Branding } from './types'

export const mapBrandingDtoToBranding = (dto: BrandingDto): Branding => ({
  companyId: dto.company_id,
  logoPath: dto.logo_path,
  logoUrl: dto.logo_url,
  colors: dto.colors,
  fonts: dto.fonts,
  header: dto.header,
  footer: dto.footer,
  requisites: dto.requisites,
})
