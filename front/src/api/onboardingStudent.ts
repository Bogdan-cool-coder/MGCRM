/**
 * Onboarding Student API — S3.8.
 * All /api/onboarding/* endpoints (student-facing).
 */

import { apiClient } from '@/api/client'
import type { CourseAssignment } from '@/entities/assignment'
import type { Quiz, QuizAttempt, QuizAttemptResult, QuizSubmitPayload } from '@/entities/quiz'
import type { Certificate } from '@/entities/certificate'

// ─── MY COURSES ──────────────────────────────────────────────────────────────

async function getMyCourses(): Promise<CourseAssignment[]> {
  const res = await apiClient.get<{ data: (CourseAssignment & { assignment_id?: number })[] }>('/api/onboarding/my-courses')
  // Normalize assignment_id → id if backend returns it under that key
  return res.data.data.map((a) => ({
    ...a,
    id: a.id ?? a.assignment_id ?? 0,
  }))
}

async function getAssignment(assignmentId: number): Promise<CourseAssignment> {
  const res = await apiClient.get<{ data: CourseAssignment }>(`/api/onboarding/assignments/${assignmentId}`)
  return res.data.data
}

// ─── LESSON PROGRESS ─────────────────────────────────────────────────────────

async function completeLesson(lessonId: number, timeSpentSeconds?: number): Promise<void> {
  await apiClient.post(`/api/onboarding/lessons/${lessonId}/complete`, {
    time_spent_seconds: timeSpentSeconds ?? 0,
  })
}

// ─── QUIZ ────────────────────────────────────────────────────────────────────

async function getStudentQuiz(lessonId: number): Promise<Quiz> {
  const res = await apiClient.get<{ data: Quiz }>(`/api/onboarding/lessons/${lessonId}/quiz`)
  return res.data.data
}

async function startQuiz(lessonId: number): Promise<QuizAttempt> {
  const res = await apiClient.post<{ data: QuizAttempt }>(`/api/onboarding/lessons/${lessonId}/quiz/start`)
  return res.data.data
}

async function submitQuizAttempt(attemptId: number, payload: QuizSubmitPayload): Promise<QuizAttemptResult> {
  const res = await apiClient.post<{ data: QuizAttemptResult }>(`/api/onboarding/quiz-attempts/${attemptId}/submit`, payload)
  return res.data.data
}

async function getQuizAttempt(attemptId: number): Promise<QuizAttemptResult> {
  const res = await apiClient.get<{ data: QuizAttemptResult }>(`/api/onboarding/quiz-attempts/${attemptId}`)
  return res.data.data
}

// ─── AI TUTOR ────────────────────────────────────────────────────────────────

export interface AiTutorMessage {
  role: 'user' | 'assistant'
  content: string
  created_at: string
}

export interface AiTutorAskResponse {
  answer: string
  session_id: string
}

async function getAiTutorHistory(lessonId: number): Promise<AiTutorMessage[]> {
  const res = await apiClient.get<{ data: AiTutorMessage[] }>(`/api/onboarding/lessons/${lessonId}/ai-tutor/history`)
  return res.data.data
}

async function askAiTutor(lessonId: number, question: string, sessionId?: string): Promise<AiTutorAskResponse> {
  const res = await apiClient.post<{ data: AiTutorAskResponse }>(`/api/onboarding/lessons/${lessonId}/ai-tutor`, {
    question,
    session_id: sessionId,
  })
  return res.data.data
}

async function clearAiTutorHistory(lessonId: number): Promise<void> {
  await apiClient.delete(`/api/onboarding/lessons/${lessonId}/ai-tutor/history`)
}

// ─── CERTIFICATES ─────────────────────────────────────────────────────────────

async function getMyCertificates(): Promise<Certificate[]> {
  const res = await apiClient.get<{ data: Certificate[] }>('/api/onboarding/my-certificates')
  return res.data.data
}

async function getCertificate(assignmentId: number): Promise<Certificate> {
  const res = await apiClient.get<{ data: Certificate }>(`/api/onboarding/certificates/${assignmentId}`)
  return res.data.data
}

async function downloadCertificate(assignmentId: number): Promise<Blob> {
  const res = await apiClient.get(`/api/onboarding/certificates/${assignmentId}/download`, {
    responseType: 'blob',
  })
  return res.data as Blob
}

// ─── Export ───────────────────────────────────────────────────────────────────

export const onboardingStudentApi = {
  getMyCourses,
  getAssignment,
  completeLesson,
  getStudentQuiz,
  startQuiz,
  submitQuizAttempt,
  getQuizAttempt,
  getAiTutorHistory,
  askAiTutor,
  clearAiTutorHistory,
  getMyCertificates,
  getCertificate,
  downloadCertificate,
}
