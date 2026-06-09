import type { LocalizedText } from '@/shared/types'
import type {
  DocumentAuthorDto,
  DocumentFieldCatalogGroup,
  DocumentFieldFilter,
  DocumentFieldMappingDto,
  DocumentTemplateConfigDto,
  DocumentTemplateType,
  GeneratedDocumentStatus,
} from '@/api/types/documents'

export type DocumentAuthor = DocumentAuthorDto
export type DocumentFieldMapping = DocumentFieldMappingDto
export type {
  DocumentFieldCatalogGroup,
  DocumentFieldFilter,
  DocumentTemplateType,
  GeneratedDocumentStatus,
}

/**
 * One substitutable field for the reference modal / placeholder lookup —
 * camelCase mirror of `DocumentFieldCatalogEntryDto`. `label` stays a
 * `LocalizedText` so the consumer resolves it with the active locale. `filters`
 * are the render filters appendable after a pipe (`${key|words}`).
 */
export interface DocumentFieldCatalogEntry {
  key: string
  label: LocalizedText
  group: DocumentFieldCatalogGroup
  filters: DocumentFieldFilter[]
  example: string | null
  pii: boolean
}

/**
 * Field catalogue grouped by bucket — camelCase mirror of the API response.
 * Every group key is present (the mapper backfills empty groups).
 */
export type DocumentFieldCatalog = Record<
  DocumentFieldCatalogGroup,
  DocumentFieldCatalogEntry[]
>

/**
 * Library list item — camelCase mirror of `DocumentTemplateListItemDto`.
 */
export interface DocumentTemplateListItem {
  id: number
  name: LocalizedText
  description: LocalizedText | null
  type: DocumentTemplateType
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  author: DocumentAuthor | null
}

/**
 * Full template detail — camelCase mirror of `DocumentTemplateDto`.
 */
export interface DocumentTemplate {
  id: number
  name: LocalizedText
  description: LocalizedText | null
  type: DocumentTemplateType
  config: DocumentTemplateConfigDto
  sourcePath: string | null
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  author: DocumentAuthor | null
  chatMessageId: number | null
  createdAt: string
  updatedAt: string
}

/**
 * One generation run — camelCase mirror of `GeneratedDocumentDto`. The status
 * endpoint returns a tight projection (no company / user / params snapshot), so
 * those are intentionally absent here.
 */
export interface GeneratedDocument {
  id: number
  documentTemplateId: number
  title: string
  status: GeneratedDocumentStatus
  pdfPath: string | null
  docxPath: string | null
  error: string | null
  createdAt: string
  updatedAt: string
}
