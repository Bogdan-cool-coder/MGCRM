/**
 * MessageTemplates API — S2.10.
 */
import { apiClient } from '@/api/client'
import type {
  MessageTemplateDto,
  MessageTemplateListItemDto,
  CreateMessageTemplatePayload,
  PatchMessageTemplatePayload,
  CreateBindingPayload,
  MessageTemplateBindingDto,
  PreviewPayload,
  PreviewResponseDto,
} from '@/entities/messageTemplate'

export async function getMessageTemplates(): Promise<MessageTemplateListItemDto[]> {
  const response = await apiClient.get<{ data: MessageTemplateListItemDto[] }>('/api/message-templates')
  return response.data.data
}

export async function getMessageTemplate(id: number): Promise<MessageTemplateDto> {
  const response = await apiClient.get<{ data: MessageTemplateDto }>(`/api/message-templates/${id}`)
  return response.data.data
}

export async function createMessageTemplate(
  payload: CreateMessageTemplatePayload,
): Promise<MessageTemplateDto> {
  const response = await apiClient.post<{ data: MessageTemplateDto }>('/api/message-templates', payload)
  return response.data.data
}

export async function patchMessageTemplate(
  id: number,
  payload: PatchMessageTemplatePayload,
): Promise<MessageTemplateDto> {
  const response = await apiClient.patch<{ data: MessageTemplateDto }>(
    `/api/message-templates/${id}`,
    payload,
  )
  return response.data.data
}

export async function deleteMessageTemplate(id: number): Promise<void> {
  await apiClient.delete(`/api/message-templates/${id}`)
}

export async function previewMessageTemplate(
  id: number,
  payload: PreviewPayload,
): Promise<PreviewResponseDto> {
  const response = await apiClient.post<{ data: PreviewResponseDto }>(
    `/api/message-templates/${id}/preview`,
    payload,
  )
  return response.data.data
}

export async function addTemplateBinding(
  id: number,
  payload: CreateBindingPayload,
): Promise<MessageTemplateBindingDto> {
  const response = await apiClient.post<{ data: MessageTemplateBindingDto }>(
    `/api/message-templates/${id}/bindings`,
    payload,
  )
  return response.data.data
}

export async function deleteTemplateBinding(
  templateId: number,
  bindingId: number,
): Promise<void> {
  await apiClient.delete(`/api/message-templates/${templateId}/bindings/${bindingId}`)
}

export const messageTemplatesApi = {
  getMessageTemplates,
  getMessageTemplate,
  createMessageTemplate,
  patchMessageTemplate,
  deleteMessageTemplate,
  previewMessageTemplate,
  addTemplateBinding,
  deleteTemplateBinding,
}
