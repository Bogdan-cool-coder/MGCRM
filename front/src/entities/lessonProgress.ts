/**
 * LessonProgress entity — S3.8 Onboarding.
 */

export interface LessonProgress {
  id: number
  assignment_id: number
  lesson_id: number
  status: 'not_started' | 'in_progress' | 'completed'
  completed_at: string | null
  time_spent_seconds: number
}

export interface LessonCompletePayload {
  time_spent_seconds?: number
}
