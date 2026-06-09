import { apiClient } from '@/api/client'
import type {
  BrandingDto,
  UpdateBrandingRequest,
  UploadBrandingLogoResponseDto,
} from '@/api/types/branding'

export interface BrandingApi {
  /** Read a company's branding. Readable by all roles (needed to render proposals). */
  getBranding(_companyId: number): Promise<BrandingDto>
  /** Update branding. admin (own company) / superadmin (any). 403 otherwise. */
  updateBranding(_companyId: number, _payload: UpdateBrandingRequest): Promise<BrandingDto>
  /**
   * Upload a logo (multipart `FormData`). admin / superadmin. Returns the fresh
   * branding row so callers can refresh the preview without an extra GET.
   */
  uploadLogo(_companyId: number, _file: File): Promise<UploadBrandingLogoResponseDto>
}

export const brandingApi: BrandingApi = {
  async getBranding(companyId: number): Promise<BrandingDto> {
    const response = await apiClient.get<BrandingDto>(`/api/companies/${companyId}/branding`)
    return response.data
  },

  async updateBranding(
    companyId: number,
    payload: UpdateBrandingRequest,
  ): Promise<BrandingDto> {
    const response = await apiClient.put<BrandingDto>(
      `/api/companies/${companyId}/branding`,
      payload,
    )
    return response.data
  },

  async uploadLogo(companyId: number, file: File): Promise<UploadBrandingLogoResponseDto> {
    const formData = new FormData()
    formData.append('logo', file)
    // Let the browser set the multipart boundary — overriding Content-Type with
    // a manual `multipart/form-data` string would omit the boundary and break
    // parsing server-side. We pass an explicit `undefined` to drop the default
    // `application/json` header set on the axios instance for this one request.
    const response = await apiClient.post<UploadBrandingLogoResponseDto>(
      `/api/companies/${companyId}/branding/logo`,
      formData,
      { headers: { 'Content-Type': undefined } },
    )
    return response.data
  },
}
