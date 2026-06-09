import { documentsApi } from '@/api/documents'
import {
  mapDocumentTemplateDtoToTemplate,
  mapDocumentTemplateListItemDtoToItem,
  mapFieldCatalogResponseToCatalog,
  mapGeneratedDocumentDtoToGenerated,
} from '@/entities/document'
import type {
  DocumentFieldCatalog,
  DocumentTemplate,
  DocumentTemplateListItem,
  GeneratedDocument,
} from '@/entities/document'
import type {
  CreateDocumentTemplateRequest,
  DocumentGenerateParams,
  DocumentPreviewParams,
  GeneratedDocumentFormat,
  UpdateDocumentTemplateRequest,
} from '@/api/types/documents'

export class DocumentService {
  async fetchAllTemplates(): Promise<DocumentTemplateListItem[]> {
    return (await documentsApi.list()).map(mapDocumentTemplateListItemDtoToItem)
  }

  async fetchTemplate(id: number): Promise<DocumentTemplate> {
    return mapDocumentTemplateDtoToTemplate(await documentsApi.get(id))
  }

  async createTemplate(payload: CreateDocumentTemplateRequest): Promise<DocumentTemplate> {
    return mapDocumentTemplateDtoToTemplate(await documentsApi.create(payload))
  }

  async updateTemplate(
    id: number,
    payload: UpdateDocumentTemplateRequest,
  ): Promise<DocumentTemplate> {
    return mapDocumentTemplateDtoToTemplate(await documentsApi.update(id, payload))
  }

  async deleteTemplate(id: number): Promise<void> {
    await documentsApi.remove(id)
  }

  async publishTemplate(id: number): Promise<DocumentTemplate> {
    return mapDocumentTemplateDtoToTemplate(await documentsApi.publish(id))
  }

  async unpublishTemplate(id: number): Promise<DocumentTemplate> {
    return mapDocumentTemplateDtoToTemplate(await documentsApi.unpublish(id))
  }

  /**
   * Kick off async generation. Returns the new `GeneratedDocument` id to poll
   * via `fetchGeneratedStatus`.
   */
  async generate(id: number, params: DocumentGenerateParams): Promise<number> {
    const response = await documentsApi.generate(id, params)
    return response.generated_document_id
  }

  async fetchGeneratedStatus(generatedId: number): Promise<GeneratedDocument> {
    return mapGeneratedDocumentDtoToGenerated(
      await documentsApi.getGeneratedStatus(generatedId),
    )
  }

  /** Raw file blob for `utils/fileDownload`. Defaults to PDF. */
  async downloadGenerated(
    generatedId: number,
    format: GeneratedDocumentFormat = 'pdf',
  ): Promise<Blob> {
    return documentsApi.downloadGenerated(generatedId, format)
  }

  /** Server-rendered HTML for the sandboxed preview iframe. */
  async previewHtml(id: number, params: DocumentPreviewParams): Promise<string> {
    const response = await documentsApi.previewHtml(id, params)
    return response.html
  }

  /**
   * Upload the `.docx` source for a docx-type template. Returns the stored
   * `source_path` (callers refetch placeholders afterwards).
   */
  async uploadSourceFile(id: number, file: File): Promise<string> {
    const response = await documentsApi.uploadSourceFile(id, file)
    return response.source_path
  }

  /** The `${...}` placeholder tokens declared in the uploaded docx source. */
  async fetchPlaceholders(id: number): Promise<string[]> {
    const response = await documentsApi.getPlaceholders(id)
    return response.placeholders
  }

  /** Static catalogue of substitutable fields grouped by bucket. */
  async fetchFieldCatalog(): Promise<DocumentFieldCatalog> {
    return mapFieldCatalogResponseToCatalog(await documentsApi.getFieldCatalog())
  }
}
