/**
 * Course domain entities — S3.8 Onboarding.
 */

export type LessonKind = 'text' | 'video' | 'pdf' | 'quiz'
export type CompletionPolicy = 'soft_gate' | 'informational'

export interface Course {
  id: number
  title: string
  description: string | null
  completion_policy: CompletionPolicy
  passing_score_pct: number
  deadline_days: number | null
  cover_image_path: string | null
  is_published: boolean
  modules_count: number
  lessons_count: number
  created_at: string
  updated_at: string
  /** Eager-loaded modules with lessons — present in AssignmentDetailResource (student endpoint) */
  modules?: CourseModule[]
}

export interface CourseModule {
  id: number
  course_id: number
  title: string
  sort_order: number
  lessons: Lesson[] | undefined
  created_at: string
}

export interface LessonContentText {
  markdown: string | null
}

export interface LessonContentVideo {
  url: string | null
  provider: 'youtube' | 'loom' | 'vimeo' | null
}

export interface LessonContentPdf {
  path: string | null
}

export interface LessonContentQuiz {
  quiz_id: number | null
}

export type LessonContent = LessonContentText | LessonContentVideo | LessonContentPdf | LessonContentQuiz | null

export interface Lesson {
  id: number
  module_id: number
  title: string
  kind: LessonKind
  sort_order: number
  duration_minutes: number | null
  is_published: boolean
  content: LessonContent
  created_at: string
}

export interface ReorderPayloadItem {
  id: number
  sort_order: number
}

export interface CourseListParams {
  status?: 'draft' | 'published' | ''
  completion_policy?: CompletionPolicy | ''
  search?: string
  page?: number
  per_page?: number
}

export interface CourseCreatePayload {
  title: string
  description?: string | null
  completion_policy: CompletionPolicy
  passing_score_pct: number
  deadline_days?: number | null
  cover_image_url?: string | null
}

export interface CoursePatchPayload {
  title?: string
  description?: string | null
  completion_policy?: CompletionPolicy
  passing_score_pct?: number
  deadline_days?: number | null
  cover_image_url?: string | null
}

export interface ModuleCreatePayload {
  title: string
}

export interface LessonCreatePayload {
  title: string
  kind: LessonKind
  duration_minutes?: number | null
  content: LessonContent
}

export interface LessonPatchPayload {
  title?: string
  duration_minutes?: number | null
  content?: LessonContent
}
