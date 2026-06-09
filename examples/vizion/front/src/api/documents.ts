import { apiClient } from '@/api/client'
import type {
  CreateDocumentTemplateRequest,
  DocumentFieldCatalogResponseDto,
  DocumentGenerateParams,
  DocumentPlaceholdersResponseDto,
  DocumentPreviewParams,
  DocumentPreviewResponseDto,
  DocumentTemplateDto,
  DocumentTemplateListItemDto,
  GenerateDocumentResponseDto,
  GeneratedDocumentDto,
  GeneratedDocumentFormat,
  UpdateDocumentTemplateRequest,
  UploadDocumentSourceResponseDto,
} from '@/api/types/documents'

export interface DocumentsApi {
  /**
   * Library — system + published + personal templates for the active company.
   * Active company is resolved by backend middleware; no `company_id` param.
   */
  list(): Promise<DocumentTemplateListItemDto[]>
  get(_id: number): Promise<DocumentTemplateDto>
  create(_payload: CreateDocumentTemplateRequest): Promise<DocumentTemplateDto>
  /** 403 for system templates (clone instead). */
  update(_id: number, _payload: UpdateDocumentTemplateRequest): Promise<DocumentTemplateDto>
  /** owner / admin only; 403 for system templates. */
  remove(_id: number): Promise<void>
  /** Publish to the whole company. admin / superadmin; 403 for system templates. */
  publish(_id: number): Promise<DocumentTemplateDto>
  unpublish(_id: number): Promise<DocumentTemplateDto>
  /**
   * Kick off async generation. Returns 202 + the new `GeneratedDocument` id to
   * poll via `getGeneratedStatus`.
   */
  generate(_id: number, _params: DocumentGenerateParams): Promise<GenerateDocumentResponseDto>
  /** Poll a generation run's status (`pending` → `processing` → `done` | `error`). */
  getGeneratedStatus(_generatedId: number): Promise<GeneratedDocumentDto>
  /**
   * Download a finished file as a Blob (`responseType: 'blob'`). Caller wraps
   * it via `utils/fileDownload`. Defaults to PDF.
   */
  downloadGenerated(_generatedId: number, _format?: GeneratedDocumentFormat): Promise<Blob>
  /**
   * Server-rendered HTML for the sandboxed `<iframe :srcdoc>` preview
   * (`POST /api/documents/{id}/preview-html`). Same selectors as `generate`
   * plus an explicit render `locale`; synchronous and never persists a file.
   */
  previewHtml(_id: number, _params: DocumentPreviewParams): Promise<DocumentPreviewResponseDto>
  /**
   * Upload the `.docx` source for a docx-type template (multipart, field
   * `file`; ≤10 MB). Write-ACL identical to `update`; viewer / system templates
   * → 403, non-docx → 422. Returns the stored `source_path`.
   */
  uploadSourceFile(_id: number, _file: File): Promise<UploadDocumentSourceResponseDto>
  /**
   * The `${...}` placeholder tokens declared in the uploaded docx source.
   * 422 when no source file has been uploaded yet.
   */
  getPlaceholders(_id: number): Promise<DocumentPlaceholdersResponseDto>
  /**
   * Static catalogue of substitutable fields grouped by `object` / `branding` /
   * `discount`. Backs the placeholder-mapping selects and the reference modal.
   */
  getFieldCatalog(): Promise<DocumentFieldCatalogResponseDto>
}

export const documentsApi: DocumentsApi = {
  async list(): Promise<DocumentTemplateListItemDto[]> {
    const response = await apiClient.get<DocumentTemplateListItemDto[]>('/api/documents')
    return response.data
  },

  async get(id: number): Promise<DocumentTemplateDto> {
    const response = await apiClient.get<DocumentTemplateDto>(`/api/documents/${id}`)
    return response.data
  },

  async create(payload: CreateDocumentTemplateRequest): Promise<DocumentTemplateDto> {
    const response = await apiClient.post<DocumentTemplateDto>('/api/documents', payload)
    return response.data
  },

  async update(
    id: number,
    payload: UpdateDocumentTemplateRequest,
  ): Promise<DocumentTemplateDto> {
    const response = await apiClient.put<DocumentTemplateDto>(`/api/documents/${id}`, payload)
    return response.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/documents/${id}`)
  },

  async publish(id: number): Promise<DocumentTemplateDto> {
    const response = await apiClient.post<DocumentTemplateDto>(`/api/documents/${id}/publish`)
    return response.data
  },

  async unpublish(id: number): Promise<DocumentTemplateDto> {
    const response = await apiClient.post<DocumentTemplateDto>(`/api/documents/${id}/unpublish`)
    return response.data
  },

  async generate(
    id: number,
    params: DocumentGenerateParams,
  ): Promise<GenerateDocumentResponseDto> {
    const response = await apiClient.post<GenerateDocumentResponseDto>(
      `/api/documents/${id}/generate`,
      params,
    )
    return response.data
  },

  async getGeneratedStatus(generatedId: number): Promise<GeneratedDocumentDto> {
    const response = await apiClient.get<GeneratedDocumentDto>(
      `/api/documents/generated/${generatedId}`,
    )
    return response.data
  },

  async downloadGenerated(
    generatedId: number,
    format: GeneratedDocumentFormat = 'pdf',
  ): Promise<Blob> {
    const response = await apiClient.get<Blob>(
      `/api/documents/generated/${generatedId}/download`,
      { params: { format }, responseType: 'blob' },
    )
    return response.data
  },

  async previewHtml(
    id: number,
    params: DocumentPreviewParams,
  ): Promise<DocumentPreviewResponseDto> {
    const response = await apiClient.post<DocumentPreviewResponseDto>(
      `/api/documents/${id}/preview-html`,
      params,
    )
    return response.data
  },

  async uploadSourceFile(
    id: number,
    file: File,
  ): Promise<UploadDocumentSourceResponseDto> {
    const formData = new FormData()
    formData.append('file', file)
    // Drop the instance-level `application/json` header so the browser sets the
    // multipart boundary itself (mirrors brandingApi.uploadLogo).
    const response = await apiClient.post<UploadDocumentSourceResponseDto>(
      `/api/documents/${id}/source-file`,
      formData,
      { headers: { 'Content-Type': undefined } },
    )
    return response.data
  },

  async getPlaceholders(id: number): Promise<DocumentPlaceholdersResponseDto> {
    const response = await apiClient.get<DocumentPlaceholdersResponseDto>(
      `/api/documents/${id}/placeholders`,
    )
    return response.data
  },

  async getFieldCatalog(): Promise<DocumentFieldCatalogResponseDto> {
    const response = await apiClient.get<DocumentFieldCatalogResponseDto>(
      '/api/documents/field-catalog',
    )
    return response.data
  },
}
