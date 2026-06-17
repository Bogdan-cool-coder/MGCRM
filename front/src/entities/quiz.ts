/**
 * Quiz domain entities — S3.8 Onboarding.
 */

export type QuestionKind = 'single_choice' | 'multiple_choice'
export type AiGenerationStatus = 'idle' | 'pending' | 'generating' | 'completed' | 'failed'

export interface Quiz {
  id: number
  title: string
  description: string | null
  pass_score_pct: number
  time_limit_minutes: number
  ai_generation_status: AiGenerationStatus
  questions: QuizQuestion[]
  created_at: string
}

export interface QuizQuestion {
  id: number
  quiz_id: number
  kind: QuestionKind
  text: string
  explanation: string | null
  points: number
  sort_order: number
  options: QuizOption[]
}

export interface QuizOption {
  id: number
  question_id: number
  text: string
  is_correct: boolean
  sort_order: number
}

export interface QuizAttempt {
  id: number
  quiz_id: number
  user_id: number
  status: 'in_progress' | 'submitted'
  started_at: string
  submitted_at: string | null
  score_pct: number | null
  passed: boolean | null
}

export interface QuizAttemptResult {
  id: number
  score_pct: number
  passed: boolean
  answers: QuizAnswerResult[]
}

export interface QuizAnswerResult {
  question_id: number
  question_text: string
  kind: QuestionKind
  explanation: string | null
  selected_option_ids: number[]
  correct_option_ids: number[] | null
  is_correct: boolean
}

export interface QuizCreatePayload {
  title: string
  description?: string | null
  pass_score_pct: number
  time_limit_minutes?: number
}

export interface QuizPatchPayload {
  title?: string
  description?: string | null
  pass_score_pct?: number
  time_limit_minutes?: number
}

export interface QuestionCreatePayload {
  kind: QuestionKind
  text: string
  explanation?: string | null
  points: number
  options: { text: string; is_correct: boolean }[]
}

export interface QuestionPatchPayload {
  kind?: QuestionKind
  text?: string
  explanation?: string | null
  points?: number
}

export interface OptionCreatePayload {
  text: string
  is_correct: boolean
}

export interface QuizSubmitPayload {
  answers: { question_id: number; selected_option_ids: number[] }[]
}

// Draft questions from AI generation
export interface DraftQuestion {
  text: string
  kind: QuestionKind
  explanation: string | null
  points: number
  options: { text: string; is_correct: boolean }[]
}
