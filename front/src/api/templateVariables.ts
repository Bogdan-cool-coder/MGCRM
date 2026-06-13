/**
 * TemplateVariables API — S2.10.
 */
import { apiClient } from '@/api/client'
import type {
  TemplateVariableDto,
  TemplateVariableListParams,
  CreateTemplateVariablePayload,
  PatchTemplateVariablePayload,
} from '@/entities/templateVariable'

export async function getTemplateVariables(
  params?: TemplateVariableListParams,
): Promise<TemplateVariableDto[]> {
  const response = await apiClient.get<{ data: TemplateVariableDto[] }>(
    '/api/template-variables',
    { params },
  )
  return response.data.data
}

export async function createTemplateVariable(
  payload: CreateTemplateVariablePayload,
): Promise<TemplateVariableDto> {
  const response = await apiClient.post<{ data: TemplateVariableDto }>(
    '/api/template-variables',
    payload,
  )
  return response.data.data
}

export async function patchTemplateVariable(
  id: number,
  payload: PatchTemplateVariablePayload,
): Promise<TemplateVariableDto> {
  const response = await apiClient.patch<{ data: TemplateVariableDto }>(
    `/api/template-variables/${id}`,
    payload,
  )
  return response.data.data
}

export async function deleteTemplateVariable(id: number): Promise<void> {
  await apiClient.delete(`/api/template-variables/${id}`)
}

export const templateVariablesApi = {
  getTemplateVariables,
  createTemplateVariable,
  patchTemplateVariable,
  deleteTemplateVariable,
}
