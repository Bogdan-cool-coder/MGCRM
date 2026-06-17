/**
 * ApprovalRoutes API — S2.10.
 */
import { apiClient } from '@/api/client'
import type {
  ApprovalRouteDto,
  ApprovalRouteListItemDto,
  CreateApprovalRoutePayload,
  PatchApprovalRoutePayload,
} from '@/entities/approvalRoute'

export async function getApprovalRoutes(): Promise<ApprovalRouteListItemDto[]> {
  const response = await apiClient.get<{ data: ApprovalRouteListItemDto[] }>('/api/approval-routes')
  return response.data.data
}

export async function getApprovalRoute(id: number): Promise<ApprovalRouteDto> {
  const response = await apiClient.get<{ data: ApprovalRouteDto }>(`/api/approval-routes/${id}`)
  return response.data.data
}

export async function createApprovalRoute(
  payload: CreateApprovalRoutePayload,
): Promise<ApprovalRouteDto> {
  const response = await apiClient.post<{ data: ApprovalRouteDto }>('/api/approval-routes', payload)
  return response.data.data
}

export async function patchApprovalRoute(
  id: number,
  payload: PatchApprovalRoutePayload,
): Promise<ApprovalRouteDto> {
  const response = await apiClient.patch<{ data: ApprovalRouteDto }>(
    `/api/approval-routes/${id}`,
    payload,
  )
  return response.data.data
}

export async function deleteApprovalRoute(id: number): Promise<void> {
  await apiClient.delete(`/api/approval-routes/${id}`)
}

export const approvalRoutesApi = {
  getApprovalRoutes,
  getApprovalRoute,
  createApprovalRoute,
  patchApprovalRoute,
  deleteApprovalRoute,
}
