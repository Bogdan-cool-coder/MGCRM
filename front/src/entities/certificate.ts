/**
 * Certificate entity — S3.8 Onboarding.
 */

export interface Certificate {
  id: number
  assignment_id: number
  user_id: number
  course_title: string
  certificate_number: string
  issued_at: string
  pdf_url: string | null
}
