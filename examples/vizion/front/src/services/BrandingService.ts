import { brandingApi } from '@/api/branding'
import { mapBrandingDtoToBranding } from '@/entities/branding'
import type { Branding } from '@/entities/branding'
import type { UpdateBrandingRequest, UploadBrandingLogoDto } from '@/api/types/branding'

export class BrandingService {
  async fetchBranding(companyId: number): Promise<Branding> {
    return mapBrandingDtoToBranding(await brandingApi.getBranding(companyId))
  }

  async updateBranding(companyId: number, payload: UpdateBrandingRequest): Promise<Branding> {
    return mapBrandingDtoToBranding(await brandingApi.updateBranding(companyId, payload))
  }

  /**
   * Upload a logo. The backend returns only the fresh `{logo_path, logo_url}`
   * (NOT a full branding row), so callers refresh just the preview URL.
   */
  async uploadLogo(companyId: number, file: File): Promise<UploadBrandingLogoDto> {
    return brandingApi.uploadLogo(companyId, file)
  }
}
