import { apiClient } from '@/api/client'

// ── DTOs ────────────────────────────────────────────────────────────────────

export interface FolderDto {
  id: number
  name: string
  is_system: boolean
  read_only: boolean
  sort_order: number
  owner_entity_type: 'company' | 'contact'
  owner_entity_id: number
  created_at: string
}

export interface UploaderDto {
  id: number
  name: string
}

export interface CrmFileDto {
  id: number | string          // string "doc_7" for scans-of-contracts folder items
  folder_id: number
  original_name: string
  mime_type: string | null
  file_size: number | null
  disk?: string
  source?: 'document'          // only present for Сканы договоров document-backed items
  document_id?: number
  status?: string
  uploaded_by: UploaderDto
  created_at: string
  download_url: string
}

// ── Payloads ─────────────────────────────────────────────────────────────────

export interface CreateFolderPayload {
  name: string
}

// ── Entity type guard ─────────────────────────────────────────────────────────

export type EntityKind = 'companies' | 'contacts'

// ── API ───────────────────────────────────────────────────────────────────────

function base(kind: EntityKind, entityId: number): string {
  return `/api/${kind}/${entityId}`
}

export const filesApi = {
  // Folders
  async getFolders(kind: EntityKind, entityId: number): Promise<FolderDto[]> {
    const res = await apiClient.get<{ data: FolderDto[] }>(
      `${base(kind, entityId)}/folders`,
    )
    return res.data.data ?? []
  },

  async createFolder(kind: EntityKind, entityId: number, payload: CreateFolderPayload): Promise<FolderDto> {
    const res = await apiClient.post<{ data: FolderDto }>(
      `${base(kind, entityId)}/folders`,
      payload,
    )
    return res.data.data
  },

  async deleteFolder(kind: EntityKind, entityId: number, folderId: number): Promise<void> {
    await apiClient.delete(`${base(kind, entityId)}/folders/${folderId}`)
  },

  // Files
  async getFiles(kind: EntityKind, entityId: number, folderId: number): Promise<CrmFileDto[]> {
    const res = await apiClient.get<{ data: CrmFileDto[] }>(
      `${base(kind, entityId)}/folders/${folderId}/files`,
    )
    return res.data.data ?? []
  },

  async uploadFile(kind: EntityKind, entityId: number, folderId: number, file: File): Promise<CrmFileDto> {
    const form = new FormData()
    form.append('file', file)
    const res = await apiClient.post<{ data: CrmFileDto }>(
      `${base(kind, entityId)}/folders/${folderId}/files`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return res.data.data
  },

  async deleteFile(kind: EntityKind, entityId: number, fileId: number): Promise<void> {
    await apiClient.delete(`${base(kind, entityId)}/files/${fileId}`)
  },
}
