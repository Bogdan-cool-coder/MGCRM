/**
 * Templates API — S2.10.
 */
import { apiClient } from '@/api/client'
import type {
  TemplateDto,
  TemplateListItemDto,
  TemplateVersionDto,
  TemplateListParams,
  PatchTemplatePayload,
  CreateTemplatePayload,
  TemplateKind,
} from '@/entities/template'

// Re-export for convenience
export type { TemplateKind, CreateTemplatePayload }

export async function createTemplate(payload: CreateTemplatePayload): Promise<TemplateDto> {
  const response = await apiClient.post<{ data: TemplateDto }>('/api/templates', payload)
  return response.data.data
}

export async function getTemplates(params?: TemplateListParams): Promise<TemplateListItemDto[]> {
  const response = await apiClient.get<{ data: TemplateListItemDto[] }>('/api/templates', { params })
  return response.data.data
}

export async function getTemplate(id: number): Promise<TemplateDto> {
  const response = await apiClient.get<{ data: TemplateDto }>(`/api/templates/${id}`)
  return response.data.data
}

export async function patchTemplate(id: number, payload: PatchTemplatePayload): Promise<TemplateDto> {
  const response = await apiClient.patch<{ data: TemplateDto }>(`/api/templates/${id}`, payload)
  return response.data.data
}

export async function getTemplateVersions(templateId: number): Promise<TemplateVersionDto[]> {
  const response = await apiClient.get<{ data: TemplateVersionDto[] }>(
    `/api/templates/${templateId}/versions`,
  )
  return response.data.data
}

export async function getTemplateVersion(
  templateId: number,
  versionId: number,
): Promise<TemplateVersionDto> {
  const response = await apiClient.get<{ data: TemplateVersionDto }>(
    `/api/templates/${templateId}/versions/${versionId}`,
  )
  return response.data.data
}

export async function uploadTemplateVersion(
  templateId: number,
  file: File,
): Promise<TemplateVersionDto> {
  const form = new FormData()
  form.append('file', file)
  const response = await apiClient.post<{ data: TemplateVersionDto }>(
    `/api/templates/${templateId}/upload`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return response.data.data
}

export async function recheckTemplateVersion(
  templateId: number,
  versionId: number,
): Promise<TemplateVersionDto> {
  const response = await apiClient.post<{ data: TemplateVersionDto }>(
    `/api/templates/${templateId}/versions/${versionId}/check`,
  )
  return response.data.data
}

export async function overrideTemplateVersion(
  templateId: number,
  versionId: number,
): Promise<TemplateVersionDto> {
  const response = await apiClient.post<{ data: TemplateVersionDto }>(
    `/api/templates/${templateId}/versions/${versionId}/override`,
  )
  return response.data.data
}

export const templatesApi = {
  createTemplate,
  getTemplates,
  getTemplate,
  patchTemplate,
  getTemplateVersions,
  getTemplateVersion,
  uploadTemplateVersion,
  recheckTemplateVersion,
  overrideTemplateVersion,
}
