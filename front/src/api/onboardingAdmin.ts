/**
 * Onboarding Admin API — S3.8.
 * All /api/admin/onboarding/* endpoints.
 */

import { apiClient } from '@/api/client'
import type {
  Course,
  CourseModule,
  Lesson,
  CourseListParams,
  CourseCreatePayload,
  CoursePatchPayload,
  ModuleCreatePayload,
  LessonCreatePayload,
  LessonPatchPayload,
  ReorderPayloadItem,
} from '@/entities/course'
import type {
  Quiz,
  QuizCreatePayload,
  QuizPatchPayload,
  QuizQuestion,
  QuestionCreatePayload,
  QuestionPatchPayload,
  QuizOption,
  OptionCreatePayload,
} from '@/entities/quiz'
import type {
  CourseAssignment,
  AssignmentListParams,
  BulkAssignPayload,
  BulkAssignResult,
  AssignmentPatchPayload,
} from '@/entities/assignment'
import type { Certificate } from '@/entities/certificate'

// ─── Paginated wrapper ────────────────────────────────────────────────────────
interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

// ─── COURSES ─────────────────────────────────────────────────────────────────

async function getCourses(params?: CourseListParams): Promise<Paginated<Course>> {
  const res = await apiClient.get<Paginated<Course>>('/api/admin/onboarding/courses', { params })
  return res.data
}

async function getCourse(id: number): Promise<Course> {
  const res = await apiClient.get<{ data: Course }>(`/api/admin/onboarding/courses/${id}`)
  return res.data.data
}

async function createCourse(payload: CourseCreatePayload): Promise<Course> {
  const res = await apiClient.post<{ data: Course }>('/api/admin/onboarding/courses', payload)
  return res.data.data
}

async function patchCourse(id: number, payload: CoursePatchPayload): Promise<Course> {
  const res = await apiClient.patch<{ data: Course }>(`/api/admin/onboarding/courses/${id}`, payload)
  return res.data.data
}

async function deleteCourse(id: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/courses/${id}`)
}

async function publishCourse(id: number): Promise<Course> {
  const res = await apiClient.post<{ data: Course }>(`/api/admin/onboarding/courses/${id}/publish`)
  return res.data.data
}

async function unpublishCourse(id: number): Promise<Course> {
  const res = await apiClient.post<{ data: Course }>(`/api/admin/onboarding/courses/${id}/unpublish`)
  return res.data.data
}

// ─── MODULES ──────────────────────────────────────────────────────────────────

async function getModules(courseId: number): Promise<CourseModule[]> {
  const res = await apiClient.get<{ data: CourseModule[] }>(`/api/admin/onboarding/courses/${courseId}/modules`)
  return res.data.data
}

async function createModule(courseId: number, payload: ModuleCreatePayload): Promise<CourseModule> {
  const res = await apiClient.post<{ data: CourseModule }>(`/api/admin/onboarding/courses/${courseId}/modules`, payload)
  return res.data.data
}

async function patchModule(courseId: number, moduleId: number, payload: { title: string }): Promise<CourseModule> {
  const res = await apiClient.patch<{ data: CourseModule }>(`/api/admin/onboarding/courses/${courseId}/modules/${moduleId}`, payload)
  return res.data.data
}

async function deleteModule(courseId: number, moduleId: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/courses/${courseId}/modules/${moduleId}`)
}

async function reorderModules(courseId: number, items: ReorderPayloadItem[]): Promise<void> {
  await apiClient.post(`/api/admin/onboarding/courses/${courseId}/modules/reorder`, {
    order: items.map((i) => ({ id: i.id })),
  })
}

// ─── LESSONS ─────────────────────────────────────────────────────────────────

async function getLessons(moduleId: number): Promise<Lesson[]> {
  const res = await apiClient.get<{ data: Lesson[] }>(`/api/admin/onboarding/modules/${moduleId}/lessons`)
  return res.data.data
}

async function createLesson(moduleId: number, payload: LessonCreatePayload): Promise<Lesson> {
  const res = await apiClient.post<{ data: Lesson }>(`/api/admin/onboarding/modules/${moduleId}/lessons`, payload)
  return res.data.data
}

async function patchLesson(moduleId: number, lessonId: number, payload: LessonPatchPayload): Promise<Lesson> {
  const res = await apiClient.patch<{ data: Lesson }>(`/api/admin/onboarding/modules/${moduleId}/lessons/${lessonId}`, payload)
  return res.data.data
}

async function deleteLesson(moduleId: number, lessonId: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/modules/${moduleId}/lessons/${lessonId}`)
}

async function reorderLessons(moduleId: number, items: ReorderPayloadItem[]): Promise<void> {
  await apiClient.post(`/api/admin/onboarding/modules/${moduleId}/lessons/reorder`, {
    order: items.map((i) => ({ id: i.id })),
  })
}

async function publishLesson(moduleId: number, lessonId: number): Promise<Lesson> {
  const res = await apiClient.post<{ data: Lesson }>(`/api/admin/onboarding/modules/${moduleId}/lessons/${lessonId}/publish`)
  return res.data.data
}

async function unpublishLesson(moduleId: number, lessonId: number): Promise<Lesson> {
  const res = await apiClient.post<{ data: Lesson }>(`/api/admin/onboarding/modules/${moduleId}/lessons/${lessonId}/unpublish`)
  return res.data.data
}

async function uploadLessonPdf(lessonId: number, file: File): Promise<Lesson> {
  const form = new FormData()
  form.append('file', file)
  const res = await apiClient.post<{ data: Lesson }>(`/api/admin/onboarding/lessons/${lessonId}/upload`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return res.data.data
}

async function generateQuizQuestions(lessonId: number): Promise<void> {
  await apiClient.post(`/api/admin/onboarding/lessons/${lessonId}/generate-questions`)
}

// ─── QUIZZES ─────────────────────────────────────────────────────────────────

async function getQuizzes(): Promise<Quiz[]> {
  const res = await apiClient.get<{ data: Quiz[] }>('/api/admin/onboarding/quizzes')
  return res.data.data
}

async function getQuiz(quizId: number): Promise<Quiz> {
  const res = await apiClient.get<{ data: Quiz }>(`/api/admin/onboarding/quizzes/${quizId}`)
  return res.data.data
}

async function createQuiz(payload: QuizCreatePayload): Promise<Quiz> {
  const res = await apiClient.post<{ data: Quiz }>('/api/admin/onboarding/quizzes', payload)
  return res.data.data
}

async function patchQuiz(quizId: number, payload: QuizPatchPayload): Promise<Quiz> {
  const res = await apiClient.patch<{ data: Quiz }>(`/api/admin/onboarding/quizzes/${quizId}`, payload)
  return res.data.data
}

async function deleteQuiz(quizId: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/quizzes/${quizId}`)
}

// ─── QUESTIONS ────────────────────────────────────────────────────────────────

async function createQuestion(quizId: number, payload: QuestionCreatePayload): Promise<QuizQuestion> {
  const res = await apiClient.post<{ data: QuizQuestion }>(`/api/admin/onboarding/quizzes/${quizId}/questions`, payload)
  return res.data.data
}

async function patchQuestion(quizId: number, questionId: number, payload: QuestionPatchPayload): Promise<QuizQuestion> {
  const res = await apiClient.patch<{ data: QuizQuestion }>(`/api/admin/onboarding/quizzes/${quizId}/questions/${questionId}`, payload)
  return res.data.data
}

async function deleteQuestion(quizId: number, questionId: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/quizzes/${quizId}/questions/${questionId}`)
}

async function reorderQuestions(quizId: number, items: ReorderPayloadItem[]): Promise<void> {
  await apiClient.post(`/api/admin/onboarding/quizzes/${quizId}/questions/reorder`, items)
}

// ─── OPTIONS ─────────────────────────────────────────────────────────────────

async function createOption(quizId: number, questionId: number, payload: OptionCreatePayload): Promise<QuizOption> {
  const res = await apiClient.post<{ data: QuizOption }>(`/api/admin/onboarding/quizzes/${quizId}/questions/${questionId}/options`, payload)
  return res.data.data
}

async function patchOption(quizId: number, questionId: number, optionId: number, payload: Partial<OptionCreatePayload>): Promise<QuizOption> {
  const res = await apiClient.patch<{ data: QuizOption }>(`/api/admin/onboarding/quizzes/${quizId}/questions/${questionId}/options/${optionId}`, payload)
  return res.data.data
}

async function deleteOption(quizId: number, questionId: number, optionId: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/quizzes/${quizId}/questions/${questionId}/options/${optionId}`)
}

async function reorderOptions(quizId: number, questionId: number, items: ReorderPayloadItem[]): Promise<void> {
  await apiClient.post(`/api/admin/onboarding/quizzes/${quizId}/questions/${questionId}/options/reorder`, items)
}

// ─── ASSIGNMENTS ─────────────────────────────────────────────────────────────

async function getAssignments(params?: AssignmentListParams): Promise<Paginated<CourseAssignment>> {
  const res = await apiClient.get<Paginated<CourseAssignment>>('/api/admin/onboarding/assignments', { params })
  return res.data
}

async function getCourseAssignments(courseId: number): Promise<CourseAssignment[]> {
  const res = await apiClient.get<{ data: CourseAssignment[] }>(`/api/admin/onboarding/courses/${courseId}/assignments`)
  return res.data.data
}

async function createAssignments(payload: BulkAssignPayload): Promise<BulkAssignResult> {
  const res = await apiClient.post<{ data: BulkAssignResult }>('/api/admin/onboarding/assignments', payload)
  return res.data.data
}

async function patchAssignment(id: number, payload: AssignmentPatchPayload): Promise<CourseAssignment> {
  const res = await apiClient.patch<{ data: CourseAssignment }>(`/api/admin/onboarding/assignments/${id}`, payload)
  return res.data.data
}

async function archiveAssignment(id: number): Promise<CourseAssignment> {
  const res = await apiClient.post<{ data: CourseAssignment }>(`/api/admin/onboarding/assignments/${id}/archive`)
  return res.data.data
}

async function deleteAssignment(id: number): Promise<void> {
  await apiClient.delete(`/api/admin/onboarding/assignments/${id}`)
}

// ─── HR PROGRESS ─────────────────────────────────────────────────────────────

export interface HrTopCoursesChart {
  labels: string[]
  datasets: { label: string; data: number[] }[]
  meta: { type: string; orientation: string }
}

export interface HrProgressSummary {
  total: number
  completed: number
  in_progress: number
  pending: number
  overdue: number
  top_courses_chart: HrTopCoursesChart
}

export interface HrProgressRow {
  assignment_id: number
  user_id: number
  user_name: string
  course_id: number
  course_title: string
  status: string
  progress_pct: number
  due_date: string | null
  avg_quiz_score: number | null
}

interface HrProgressParams {
  user_id?: number | null
  course_id?: number | null
  status?: string
  page?: number
  per_page?: number
}

async function getHrProgressSummary(): Promise<HrProgressSummary> {
  const res = await apiClient.get<{ data: HrProgressSummary }>('/api/admin/onboarding/progress/summary')
  return res.data.data
}

async function getHrProgress(params?: HrProgressParams): Promise<Paginated<HrProgressRow>> {
  const res = await apiClient.get<Paginated<HrProgressRow>>('/api/admin/onboarding/progress', { params })
  return res.data
}

// ─── CERTIFICATES (admin) ─────────────────────────────────────────────────────

async function getAdminCertificate(assignmentId: number): Promise<Certificate> {
  const res = await apiClient.get<{ data: Certificate }>(`/api/admin/onboarding/certificates/${assignmentId}`)
  return res.data.data
}

async function regenerateCertificate(assignmentId: number): Promise<Certificate> {
  const res = await apiClient.post<{ data: Certificate }>(`/api/admin/onboarding/certificates/${assignmentId}/regenerate`)
  return res.data.data
}

// ─── Export ───────────────────────────────────────────────────────────────────

export const onboardingAdminApi = {
  // courses
  getCourses,
  getCourse,
  createCourse,
  patchCourse,
  deleteCourse,
  publishCourse,
  unpublishCourse,
  // modules
  getModules,
  createModule,
  patchModule,
  deleteModule,
  reorderModules,
  // lessons
  getLessons,
  createLesson,
  patchLesson,
  deleteLesson,
  reorderLessons,
  publishLesson,
  unpublishLesson,
  uploadLessonPdf,
  generateQuizQuestions,
  // quizzes
  getQuizzes,
  getQuiz,
  createQuiz,
  patchQuiz,
  deleteQuiz,
  // questions
  createQuestion,
  patchQuestion,
  deleteQuestion,
  reorderQuestions,
  // options
  createOption,
  patchOption,
  deleteOption,
  reorderOptions,
  // assignments
  getAssignments,
  getCourseAssignments,
  createAssignments,
  patchAssignment,
  archiveAssignment,
  deleteAssignment,
  // hr progress
  getHrProgressSummary,
  getHrProgress,
  // certificates
  getAdminCertificate,
  regenerateCertificate,
}
