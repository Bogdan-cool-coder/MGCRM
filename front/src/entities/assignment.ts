/**
 * CourseAssignment domain entity — S3.8 Onboarding.
 */

import type { Course } from '@/entities/course'

export type AssignmentStatus = 'pending' | 'in_progress' | 'completed' | 'overdue' | 'archived'

export interface CourseAssignment {
  id: number
  user_id: number
  user_name: string | null
  course_id: number
  status: AssignmentStatus
  progress_pct: number
  due_date: string | null
  assigned_at: string
  completed_at: string | null
  course: Course
  user?: AssignmentUser
}

export interface AssignmentUser {
  id: number
  full_name: string
  email: string
}

export interface AssignmentListParams {
  course_id?: number | null
  user_id?: number | null
  status?: AssignmentStatus | ''
  page?: number
  per_page?: number
}

export interface BulkAssignPayload {
  user_ids: number[]
  course_id: number
  due_date?: string | null
}

export interface BulkAssignResult {
  assigned: number
  skipped: number
}

export interface AssignmentPatchPayload {
  due_date?: string | null
}
