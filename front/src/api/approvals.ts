/**
 * Approvals API — S2.10.
 * My pending approvals + history.
 */
import { apiClient } from '@/api/client'
import type {
  MyApprovalItemDto,
  MyApprovalsPaginatedResponse,
  MyApprovalsListParams,
} from '@/entities/approval'

export async function getMyApprovals(
  params?: MyApprovalsListParams,
): Promise<MyApprovalsPaginatedResponse> {
  const response = await apiClient.get<MyApprovalsPaginatedResponse>('/api/approvals/my', { params })
  return response.data
}

export async function getMyPendingCount(): Promise<number> {
  const response = await apiClient.get<MyApprovalsPaginatedResponse>('/api/approvals/my', {
    params: { status: 'pending', per_page: 1 },
  })
  return response.data.meta?.total ?? 0
}

export async function getApproval(id: number): Promise<MyApprovalItemDto> {
  const response = await apiClient.get<{ data: MyApprovalItemDto }>(`/api/approvals/${id}`)
  return response.data.data
}

export const approvalsApi = {
  getMyApprovals,
  getMyPendingCount,
  getApproval,
}
