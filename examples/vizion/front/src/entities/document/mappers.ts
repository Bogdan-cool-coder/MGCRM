import type {
  DocumentFieldCatalogGroup,
  DocumentFieldCatalogResponseDto,
  DocumentTemplateDto,
  DocumentTemplateListItemDto,
  GeneratedDocumentDto,
} from '@/api/types/documents'
import type {
  DocumentFieldCatalog,
  DocumentTemplate,
  DocumentTemplateListItem,
  GeneratedDocument,
} from './types'

export const mapDocumentTemplateListItemDtoToItem = (
  dto: DocumentTemplateListItemDto,
): DocumentTemplateListItem => ({
  id: dto.id,
  name: dto.name,
  description: dto.description,
  type: dto.type,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  author: dto.author,
})

export const mapDocumentTemplateDtoToTemplate = (
  dto: DocumentTemplateDto,
): DocumentTemplate => ({
  id: dto.id,
  name: dto.name,
  description: dto.description,
  type: dto.type,
  config: dto.config,
  sourcePath: dto.source_path,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  author: dto.author,
  chatMessageId: dto.chat_message_id,
  createdAt: dto.created_at,
  updatedAt: dto.updated_at,
})

export const mapGeneratedDocumentDtoToGenerated = (
  dto: GeneratedDocumentDto,
): GeneratedDocument => ({
  id: dto.id,
  documentTemplateId: dto.document_template_id,
  title: dto.title,
  status: dto.status,
  pdfPath: dto.pdf_path,
  docxPath: dto.docx_path,
  error: dto.error,
  createdAt: dto.created_at,
  updatedAt: dto.updated_at,
})

/**
 * Bucket order the reference modal renders groups in. MacroData groups first
 * (object → deal → buyer → finances), then the render-time groups (discount /
 * common), then branding.
 */
export const FIELD_CATALOG_GROUPS: DocumentFieldCatalogGroup[] = [
  'object',
  'deal',
  'buyer',
  'finances',
  'discount',
  'common',
  'branding',
]

const emptyCatalog = (): DocumentFieldCatalog => ({
  object: [],
  deal: [],
  buyer: [],
  finances: [],
  discount: [],
  common: [],
  branding: [],
})

export const mapFieldCatalogResponseToCatalog = (
  dto: DocumentFieldCatalogResponseDto,
): DocumentFieldCatalog => {
  const groups = dto.groups ?? ({} as DocumentFieldCatalogResponseDto['groups'])
  return FIELD_CATALOG_GROUPS.reduce<DocumentFieldCatalog>((acc, group) => {
    acc[group] = (groups[group] ?? []).map((entry) => ({
      key: entry.key,
      label: entry.label,
      group: entry.group ?? group,
      filters: Array.isArray(entry.filters) ? entry.filters : [],
      example: entry.example ?? null,
      pii: entry.pii === true,
    }))
    return acc
  }, emptyCatalog())
}

/**
 * Set of catalog keys a placeholder can resolve against, for the known/unknown
 * marker. A bare `req_*` wildcard becomes a `req_` prefix match (any
 * `req_<dynamic>` requisite key is "known"); every other key matches literally.
 * A placeholder may carry a `|filter` suffix which is stripped before checking.
 */
export const isKnownPlaceholder = (token: string, catalog: DocumentFieldCatalog): boolean => {
  const key = token.split('|', 1)[0]?.trim() ?? ''
  if (key === '') return false
  for (const group of FIELD_CATALOG_GROUPS) {
    for (const entry of catalog[group]) {
      if (entry.key === key) return true
      // `req_*` wildcard → any `req_<key>` requisite token is recognised.
      if (entry.key.endsWith('*')) {
        const prefix = entry.key.slice(0, -1)
        if (prefix !== '' && key.startsWith(prefix)) return true
      }
    }
  }
  return false
}
