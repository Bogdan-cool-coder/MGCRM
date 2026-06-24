/**
 * Documents API — S2.10.
 * All calls via apiClient (Bearer token, no raw axios in components).
 */
import { apiClient } from '@/api/client'
import type {
  DocumentDto,
  DocumentPaginatedResponse,
  DocumentListParams,
  DocumentItemDto,
  DocumentRevisionDto,
  DocumentRemarkDto,
  DocumentAttachmentDto,
  ApprovalSummaryDto,
  CreateDocumentPayload,
  PatchDocumentPayload,
  DecideDocumentPayload,
  CreateDocumentItemPayload,
  UpdateDocumentItemPayload,
} from '@/entities/document'

// ─── Documents list & CRUD ────────────────────────────────────────────────────

export async function getDocuments(params?: DocumentListParams): Promise<DocumentPaginatedResponse> {
  const response = await apiClient.get<DocumentPaginatedResponse>('/api/documents', { params })
  return response.data
}

export async function getDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.get<{ data: DocumentDto }>(`/api/documents/${id}`)
  return response.data.data
}

export async function createDocument(payload: CreateDocumentPayload): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>('/api/documents', payload)
  return response.data.data
}

export async function patchDocument(id: number, payload: PatchDocumentPayload): Promise<DocumentDto> {
  const response = await apiClient.patch<{ data: DocumentDto }>(`/api/documents/${id}`, payload)
  return response.data.data
}

export async function deleteDocument(id: number): Promise<void> {
  await apiClient.delete(`/api/documents/${id}`)
}

export async function duplicateDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/duplicate`)
  return response.data.data
}

// ─── Document actions ─────────────────────────────────────────────────────────

export interface GenerateDocumentResponse {
  data: {
    document_id: number
    number: string
    docx_url: string
    pdf_url: string
  }
  warnings: string[]
}

export async function generateDocument(id: number): Promise<GenerateDocumentResponse> {
  const response = await apiClient.post<GenerateDocumentResponse>(`/api/documents/${id}/generate`)
  return response.data
}

export async function submitDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/submit`)
  return response.data.data
}

export async function decideDocument(id: number, payload: DecideDocumentPayload): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/decide`, payload)
  return response.data.data
}

export async function signDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/sign`)
  return response.data.data
}

export async function unsignDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/unsign`)
  return response.data.data
}

export async function archiveDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/archive`)
  return response.data.data
}

export async function unarchiveDocument(id: number): Promise<DocumentDto> {
  const response = await apiClient.post<{ data: DocumentDto }>(`/api/documents/${id}/unarchive`)
  return response.data.data
}

// ─── Downloads ────────────────────────────────────────────────────────────────

export function getDownloadDocxUrl(id: number): string {
  return `/api/documents/${id}/download/docx`
}

export function getDownloadPdfUrl(id: number): string {
  return `/api/documents/${id}/download/pdf`
}

// ─── Document Items ───────────────────────────────────────────────────────────

export async function getDocumentItems(docId: number): Promise<DocumentItemDto[]> {
  const response = await apiClient.get<{ data: DocumentItemDto[] }>(`/api/documents/${docId}/items`)
  return response.data.data
}

export async function createDocumentItem(
  docId: number,
  payload: CreateDocumentItemPayload,
): Promise<DocumentItemDto> {
  const response = await apiClient.post<{ data: DocumentItemDto }>(
    `/api/documents/${docId}/items`,
    payload,
  )
  return response.data.data
}

export async function updateDocumentItem(
  docId: number,
  itemId: number,
  payload: UpdateDocumentItemPayload,
): Promise<DocumentItemDto> {
  const response = await apiClient.patch<{ data: DocumentItemDto }>(
    `/api/documents/${docId}/items/${itemId}`,
    payload,
  )
  return response.data.data
}

export async function deleteDocumentItem(docId: number, itemId: number): Promise<void> {
  await apiClient.delete(`/api/documents/${docId}/items/${itemId}`)
}

// ─── Revisions ────────────────────────────────────────────────────────────────

export async function getDocumentRevisions(docId: number): Promise<DocumentRevisionDto[]> {
  const response = await apiClient.get<{ data: DocumentRevisionDto[] }>(
    `/api/documents/${docId}/revisions`,
  )
  return response.data.data
}

// ─── Remarks ─────────────────────────────────────────────────────────────────

export async function getDocumentRemarks(
  docId: number,
  attempt?: number,
): Promise<DocumentRemarkDto[]> {
  const response = await apiClient.get<{ data: DocumentRemarkDto[] }>(
    `/api/documents/${docId}/remarks`,
    { params: attempt != null ? { attempt } : undefined },
  )
  return response.data.data
}

export async function resolveRemark(docId: number, remarkId: number): Promise<DocumentRemarkDto> {
  const response = await apiClient.post<{ data: DocumentRemarkDto }>(
    `/api/documents/${docId}/remarks/${remarkId}/resolve`,
  )
  return response.data.data
}

// ─── Attachments ──────────────────────────────────────────────────────────────

export async function getDocumentAttachments(docId: number): Promise<DocumentAttachmentDto[]> {
  const response = await apiClient.get<{ data: DocumentAttachmentDto[] }>(
    `/api/documents/${docId}/attachments`,
  )
  return response.data.data
}

export async function uploadAttachment(
  docId: number,
  file: File,
  kind: string,
): Promise<DocumentAttachmentDto> {
  const form = new FormData()
  form.append('file', file)
  form.append('kind', kind)
  const response = await apiClient.post<{ data: DocumentAttachmentDto }>(
    `/api/documents/${docId}/attachments`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return response.data.data
}

export function getAttachmentDownloadUrl(docId: number, attachmentId: number): string {
  return `/api/documents/${docId}/attachments/${attachmentId}/download`
}

export async function deleteAttachment(docId: number, attachmentId: number): Promise<void> {
  await apiClient.delete(`/api/documents/${docId}/attachments/${attachmentId}`)
}

// ─── Approval Summary ────────────────────────────────────────────────────────

export async function getApprovalSummary(docId: number): Promise<ApprovalSummaryDto> {
  const response = await apiClient.get<{ data: ApprovalSummaryDto }>(
    `/api/documents/${docId}/approval-summary`,
  )
  return response.data.data
}

// ─── Generate from deal / company ────────────────────────────────────────────

export interface GenerateFromContextResponse {
  document_id: number
  number: string | null
  docx_url: string | null
  pdf_url: string | null
  warnings: string[]
}

export async function generateFromDeal(
  dealId: number,
  payload: { kind: string; template_id?: number; product_code?: string; country_code?: string; city?: string; currency?: string; context?: Record<string, unknown> },
): Promise<GenerateFromContextResponse> {
  const response = await apiClient.post<{ data: GenerateFromContextResponse }>(
    `/api/deals/${dealId}/documents/generate`,
    payload,
  )
  return response.data.data
}

export async function generateFromCompany(
  companyId: number,
  payload: { kind: string; template_id?: number; product_code?: string; country_code?: string; city?: string; currency?: string; context?: Record<string, unknown> },
): Promise<GenerateFromContextResponse> {
  const response = await apiClient.post<{ data: GenerateFromContextResponse }>(
    `/api/companies/${companyId}/documents/generate`,
    payload,
  )
  return response.data.data
}

export const documentsApi = {
  getDocuments,
  getDocument,
  createDocument,
  patchDocument,
  deleteDocument,
  duplicateDocument,
  generateDocument,
  submitDocument,
  decideDocument,
  signDocument,
  unsignDocument,
  archiveDocument,
  unarchiveDocument,
  getDownloadDocxUrl,
  getDownloadPdfUrl,
  getDocumentItems,
  createDocumentItem,
  updateDocumentItem,
  deleteDocumentItem,
  getDocumentRevisions,
  getDocumentRemarks,
  resolveRemark,
  getDocumentAttachments,
  uploadAttachment,
  getAttachmentDownloadUrl,
  deleteAttachment,
  getApprovalSummary,
  generateFromDeal,
  generateFromCompany,
}
