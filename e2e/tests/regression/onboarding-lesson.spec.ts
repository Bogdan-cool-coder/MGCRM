/**
 * REGRESSION LOCK — Onboarding student learning loop (broken).
 *
 * Audit source: docs/audit/domains/onboarding.md — blocker #1 (onboarding#0):
 *   "Плеер урока студента рендерит ПУСТО (text/video/pdf) — `content` не отдаётся".
 *
 * Evidence (audit §6 #1 + §4):
 *   - Sole student lesson-data source is GET /api/onboarding/assignments/{assignment}
 *     → AssignmentDetailResource. Its lesson-map (src/.../AssignmentDetailResource.php:49-56)
 *     emits ONLY [id, title, kind, is_published, completed] — NO `content` / `duration_minutes`.
 *   - There is NO dedicated student per-lesson content route (routes/api.php:807-837 confirms it).
 *   - The Lesson model stores the body in the `content` JSONB column (Lesson.php:24-44 fillable
 *     + cast to array). For a text lesson the body lives at content.markdown; in the live DB
 *     lesson 1 holds {"markdown":"# Welcome to MACRO CRM..."}.
 *   - FE contract (front/src/entities/course.ts:53-62) declares lesson.content + duration_minutes,
 *     which the backend never sends → text/video/pdf lessons render an empty player.
 *
 * WHAT THIS LOCKS (test asserts the DESIRED post-fix behaviour):
 *   A student's lesson payload for a TEXT lesson MUST include a non-empty `content` body.
 *   The fix (per audit) is to add `content` (+ duration_minutes) to the lesson-map of
 *   AssignmentDetailResource (or a dedicated published-only GET /lessons/{lesson} route).
 *
 *   RED until fixed: the test is annotated test.fail(). When the serializer starts emitting a
 *   non-empty `content` for the text lesson, this test will PASS and Playwright will flag
 *   "expected to fail but passed" → that is the signal to delete the test.fail() line and
 *   lock the fix permanently.
 *
 * Runtime safety: READ-ONLY. Only login POST + HTTP GET. No business-data mutation.
 * Data-driven: the assignment + text lesson are discovered live across candidate users.
 */

import { test, expect, type APIRequestContext } from '@playwright/test'
import { apiContext, login, bearer, API_URL } from '../../lib/api'

/**
 * Candidate students to probe for an accessible assignment containing a text lesson.
 * manager1 owns ~0 records but the audit confirmed manager1@mgcrm.test owns assignment 1;
 * qa-test-user@mgcrm.test is a seeded manager. We try the broadest plausible set and use
 * the FIRST user that yields an assignment with a text/markdown-kind lesson.
 */
const CANDIDATES = [
  'admin@mgcrm.test',
  'manager1@mgcrm.test',
  'qa-test-user@mgcrm.test',
  'manager2@mgcrm.test',
  'manager3@mgcrm.test',
  'director@mgcrm.test',
] as const

/** Kinds whose body is plain text/markdown (the case the audit proves renders empty). */
const TEXT_KINDS = new Set(['text'])

type LessonNode = {
  id: number
  title?: string
  kind?: string
  is_published?: boolean
  completed?: boolean
  // The fields the fix must introduce — absent today:
  content?: unknown
  body?: unknown
  markdown?: unknown
}

type AssignmentDetail = {
  id?: number
  course?: { id?: number; title?: string; modules?: Array<{ lessons?: LessonNode[] | null }> }
}

/** Flatten all lesson nodes out of an assignment-detail payload. */
function lessonsOf(detail: AssignmentDetail): LessonNode[] {
  const out: LessonNode[] = []
  for (const m of detail.course?.modules ?? []) {
    for (const l of m.lessons ?? []) out.push(l)
  }
  return out
}

/** True when a lesson node carries a non-empty body in any plausible field name. */
function hasNonEmptyBody(lesson: LessonNode): boolean {
  // The resource field name confirmed from code is `content` (Lesson model JSONB, cast array).
  // We also tolerate `body`/`markdown` in case the fix lands under a different but equivalent key.
  const candidates: unknown[] = [lesson.content, lesson.body, lesson.markdown]
  for (const c of candidates) {
    if (c == null) continue
    if (typeof c === 'string') {
      if (c.trim().length > 0) return true
      continue
    }
    if (typeof c === 'object') {
      const obj = c as Record<string, unknown>
      // text lesson body lives at content.markdown; tolerate other inner keys too
      const inner = obj.markdown ?? obj.text ?? obj.html
      if (typeof inner === 'string' && inner.trim().length > 0) return true
      // a non-empty object that is not just {} also counts as "has content payload"
      if (typeof inner !== 'string' && Object.keys(obj).length > 0) return true
    }
  }
  return false
}

/**
 * Probe candidates: log in, list my-courses, then fetch each assignment's detail and look for
 * a published TEXT lesson. Returns the first match (with the user/token used) or null.
 */
async function findTextLesson(ctx: APIRequestContext): Promise<{
  email: string
  assignmentId: number
  lesson: LessonNode
} | null> {
  for (const email of CANDIDATES) {
    let token: string
    try {
      token = await login(ctx, email)
    } catch {
      // user not seeded in this environment — skip it
      continue
    }
    const h = bearer(token)

    const listRes = await ctx.get(`${API_URL}/api/onboarding/my-courses`, { headers: h })
    if (!listRes.ok()) continue
    const listBody = await listRes.json()
    const rows: Array<{ assignment_id?: number; id?: number }> = Array.isArray(listBody?.data)
      ? listBody.data
      : Array.isArray(listBody)
        ? listBody
        : []

    for (const row of rows) {
      const assignmentId = row.assignment_id ?? row.id
      if (assignmentId == null) continue

      const detRes = await ctx.get(`${API_URL}/api/onboarding/assignments/${assignmentId}`, {
        headers: h,
      })
      if (!detRes.ok()) continue
      const detBody = await detRes.json()
      const detail: AssignmentDetail = (detBody?.data ?? detBody) as AssignmentDetail

      const textLesson = lessonsOf(detail).find(
        (l) => typeof l.kind === 'string' && TEXT_KINDS.has(l.kind),
      )
      if (textLesson) {
        return { email, assignmentId, lesson: textLesson }
      }
    }
  }
  return null
}

test.describe('Onboarding — student learning loop (audit blocker #1 / onboarding#0)', () => {
  test('AUDIT onboarding#0 — student text-lesson payload must include a non-empty content body', async () => {
    test.fail(
      true,
      'AUDIT onboarding#0: AssignmentDetailResource omits lesson `content` → text/video/pdf lessons render an empty player (live + DB confirmed). RED until fixed — when this starts PASSING, remove the test.fail() line to lock the fix.',
    )

    const ctx = await apiContext()
    try {
      const found = await findTextLesson(ctx)

      if (!found) {
        test.skip(
          true,
          'No accessible assignment containing a text lesson found across candidate users — cannot probe the lesson body. (Expected the seeded course assignment with a published text lesson, e.g. manager1@mgcrm.test → assignment 1.)',
        )
        return
      }

      // DESIRED behaviour: the text lesson exposes a non-empty content/body field.
      // Today the serializer omits `content` entirely → this assertion fails (expected-fail).
      expect(
        hasNonEmptyBody(found.lesson),
        `Text lesson #${found.lesson.id} ("${found.lesson.title ?? '?'}") in assignment ${found.assignmentId} ` +
          `(probed as ${found.email}) must expose a non-empty content body, but the student payload ` +
          `carried no usable content. Keys present: ${JSON.stringify(Object.keys(found.lesson))}.`,
      ).toBe(true)
    } finally {
      await ctx.dispose()
    }
  })
})
