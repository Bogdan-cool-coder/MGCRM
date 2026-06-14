<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    :style="{ width: '640px' }"
    :modal="true"
    :dismissable="true"
    @hide="onHide"
  >
    <template #header>
      <div class="lesson-drawer-header d-flex align-items-center gap-3 w-100">
        <span class="flex-grow-1 fw-semibold">{{ t('onboarding.builder.lesson.editTitle') }}</span>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          outlined
          size="small"
          @click="visible = false"
        />
        <Button
          :label="t('common.save')"
          icon="pi pi-check"
          size="small"
          :loading="saving"
          @click="submit"
        />
        <Button
          icon="pi pi-times"
          severity="secondary"
          text
          size="small"
          :aria-label="t('common.close')"
          @click="visible = false"
        />
      </div>
    </template>

    <div v-if="form" class="lesson-drawer-body">
      <!-- Title -->
      <div class="mb-3">
        <label class="form-label required">{{ t('onboarding.builder.lesson.name') }}</label>
        <InputText v-model="form.title" class="w-100" />
      </div>

      <!-- Kind (readonly if edit) -->
      <div class="mb-3">
        <label class="form-label required">{{ t('onboarding.builder.lesson.kind') }}</label>
        <div class="mt-1">
          <SelectButton
            v-model="form.kind"
            :options="kindOptions"
            option-label="label"
            option-value="value"
            :disabled="isEdit"
          />
        </div>
      </div>

      <!-- Duration -->
      <div class="mb-3">
        <label class="form-label">{{ t('onboarding.builder.lesson.duration') }}</label>
        <InputNumber v-model="form.duration_minutes" :min="1" class="w-100" />
      </div>

      <!-- TEXT content -->
      <template v-if="form.kind === 'text'">
        <div class="mb-2">
          <label class="form-label required">{{ t('onboarding.builder.lesson.content') }}</label>
          <small class="d-block text-muted mb-1">{{ t('onboarding.builder.lesson.contentHint') }}</small>
          <Textarea
            v-model="form.text_markdown"
            :auto-resize="false"
            rows="12"
            class="w-100 lesson-md-textarea"
          />
        </div>
        <!-- Preview -->
        <div class="mb-3">
          <Button
            :label="previewVisible ? t('common.hidePreview') : t('common.preview')"
            text
            size="small"
            severity="secondary"
            icon="pi pi-eye"
            @click="previewVisible = !previewVisible"
          />
          <div
            v-if="previewVisible"
            class="lesson-text-content mt-2 p-3 border rounded"
            v-html="renderedMarkdown"
          />
        </div>
      </template>

      <!-- VIDEO content -->
      <template v-else-if="form.kind === 'video'">
        <div class="mb-3">
          <label class="form-label required">{{ t('onboarding.builder.lesson.videoUrl') }}</label>
          <small class="d-block text-muted mb-1">{{ t('onboarding.builder.lesson.videoHint') }}</small>
          <InputText v-model="form.video_url" class="w-100" placeholder="https://youtube.com/..." />
        </div>
        <div v-if="videoEmbedSrc" class="video-preview mb-3">
          <div class="ratio ratio-16x9">
            <iframe
              :src="videoEmbedSrc"
              allowfullscreen
              title="Video preview"
            />
          </div>
        </div>
      </template>

      <!-- PDF content -->
      <template v-else-if="form.kind === 'pdf'">
        <div class="mb-3">
          <div class="d-flex gap-4 mb-3">
            <div class="d-flex align-items-center gap-2">
              <RadioButton v-model="pdfMode" value="upload" input-id="pdf-upload" />
              <label for="pdf-upload">{{ t('onboarding.builder.lesson.pdfUpload') }}</label>
            </div>
            <div class="d-flex align-items-center gap-2">
              <RadioButton v-model="pdfMode" value="link" input-id="pdf-link" />
              <label for="pdf-link">{{ t('onboarding.builder.lesson.pdfLink') }}</label>
            </div>
          </div>

          <div v-if="pdfMode === 'upload'">
            <FileUpload
              mode="basic"
              accept=".pdf"
              :max-file-size="20971520"
              :label="t('onboarding.builder.lesson.pdfUpload')"
              choose-label="Выбрать PDF"
              :disabled="!isEdit || uploading"
              @select="onPdfSelect"
            />
            <small v-if="!isEdit" class="text-muted">{{ t('common.saveFirst') }}</small>
          </div>
          <div v-else>
            <InputText v-model="form.pdf_path" class="w-100" placeholder="https://..." />
          </div>
        </div>
      </template>

      <!-- QUIZ content -->
      <template v-else-if="form.kind === 'quiz'">
        <div class="mb-3">
          <label class="form-label">{{ t('onboarding.builder.lesson.quizSelect') }}</label>
          <Select
            v-model="form.quiz_id"
            :options="quizOptions"
            option-label="title"
            option-value="id"
            :placeholder="t('onboarding.builder.lesson.quizSelect')"
            class="w-100 mb-2"
          />
          <Button
            :label="t('onboarding.builder.lesson.quizCreate')"
            icon="pi pi-plus"
            text
            severity="secondary"
            @click="openQuizBuilder"
          />
        </div>
      </template>

      <!-- Publish actions (edit only) -->
      <div v-if="isEdit && lesson" class="mt-4 pt-3 border-top d-flex gap-2">
        <Button
          v-if="!lesson.is_published"
          :label="t('onboarding.builder.lesson.publish')"
          icon="pi pi-send"
          severity="success"
          outlined
          size="small"
          @click="emit('publish')"
        />
        <Button
          v-else
          :label="t('onboarding.builder.lesson.unpublish')"
          icon="pi pi-eye-slash"
          severity="warn"
          outlined
          size="small"
          @click="emit('unpublish')"
        />
      </div>
    </div>

    <!-- QuizBuilderDrawer (nested) -->
    <QuizBuilderDrawer
      v-model:visible="quizBuilderVisible"
      :quiz="quizBuilderQuiz"
      :lesson-id="lesson?.id ?? null"
      :lesson-kind="lesson?.kind ?? null"
      @saved="onQuizSaved"
    />
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import InputNumber from 'primevue/inputnumber'
import SelectButton from 'primevue/selectbutton'
import Select from 'primevue/select'
import RadioButton from 'primevue/radiobutton'
import FileUpload from 'primevue/fileupload'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import QuizBuilderDrawer from './QuizBuilderDrawer.vue'
import type { Lesson, LessonCreatePayload, LessonPatchPayload, LessonKind } from '@/entities/course'
import type { Quiz } from '@/entities/quiz'

const props = defineProps<{
  lesson: Lesson | null
  defaultKind: LessonKind
}>()

const emit = defineEmits<{
  save: [payload: LessonCreatePayload | LessonPatchPayload]
  upload: [file: File]
  publish: []
  unpublish: []
}>()

const { t } = useI18n()
const visible = defineModel<boolean>('visible', { default: false })

const saving = ref(false)
const uploading = ref(false)
const previewVisible = ref(false)
const pdfMode = ref<'upload' | 'link'>('link')

const quizBuilderVisible = ref(false)
const quizBuilderQuiz = ref<Quiz | null>(null)
const quizOptions = ref<Quiz[]>([])

interface LessonForm {
  title: string
  kind: LessonKind
  duration_minutes: number | null
  // content fields per kind
  text_markdown: string
  video_url: string
  video_provider: 'youtube' | 'loom' | 'vimeo' | null
  pdf_path: string
  quiz_id: number | null
}

const form = ref<LessonForm>({
  title: '',
  kind: 'text',
  duration_minutes: null,
  text_markdown: '',
  video_url: '',
  video_provider: null,
  pdf_path: '',
  quiz_id: null,
})

const isEdit = computed(() => !!props.lesson)

const kindOptions = computed(() => [
  { label: t('onboarding.builder.lessonKinds.text'), value: 'text' },
  { label: t('onboarding.builder.lessonKinds.video'), value: 'video' },
  { label: t('onboarding.builder.lessonKinds.pdf'), value: 'pdf' },
  { label: t('onboarding.builder.lessonKinds.quiz'), value: 'quiz' },
])

// Markdown render — normalize double-escaped newlines, guard sync result type
const renderedMarkdown = computed(() => {
  const normalized = (form.value.text_markdown || '').replace(/\\n/g, '\n')
  const result = marked.parse(normalized, { async: false })
  const html = typeof result === 'string' ? result : ''
  return DOMPurify.sanitize(html)
})

// Detect video provider from URL
function detectProvider(url: string): 'youtube' | 'loom' | 'vimeo' | null {
  if (/youtube\.com|youtu\.be/.test(url)) return 'youtube'
  if (/vimeo\.com/.test(url)) return 'vimeo'
  if (/loom\.com/.test(url)) return 'loom'
  return null
}

// Video embed detection
const videoEmbedSrc = computed(() => {
  const url = form.value.video_url
  if (!url) return null
  const ytMatch = url.match(/youtube\.com\/watch\?v=(\w+)/) ?? url.match(/youtu\.be\/(\w+)/)
  if (ytMatch) return `https://www.youtube.com/embed/${ytMatch[1]}`
  const vimeoMatch = url.match(/vimeo\.com\/(\d+)/)
  if (vimeoMatch) return `https://player.vimeo.com/video/${vimeoMatch[1]}`
  const loomMatch = url.match(/loom\.com\/share\/(\w+)/)
  if (loomMatch) return `https://www.loom.com/embed/${loomMatch[1]}`
  return null
})

watch(
  () => props.lesson,
  (l) => {
    if (l) {
      const c = l.content as Record<string, unknown> | null
      form.value = {
        title: l.title,
        kind: l.kind,
        duration_minutes: l.duration_minutes,
        text_markdown: (c && 'markdown' in c ? (c.markdown as string) : null) ?? '',
        video_url: (c && 'url' in c ? (c.url as string) : null) ?? '',
        video_provider: (c && 'provider' in c ? (c.provider as 'youtube' | 'loom' | 'vimeo') : null) ?? null,
        pdf_path: (c && 'path' in c ? (c.path as string) : null) ?? '',
        quiz_id: (c && 'quiz_id' in c ? (c.quiz_id as number) : null) ?? null,
      }
    } else {
      form.value = {
        title: '',
        kind: props.defaultKind,
        duration_minutes: null,
        text_markdown: '',
        video_url: '',
        video_provider: null,
        pdf_path: '',
        quiz_id: null,
      }
    }
    previewVisible.value = false
    pdfMode.value = 'link'
  },
  { immediate: true },
)

watch(
  () => visible.value,
  async (v) => {
    if (v && form.value.kind === 'quiz') {
      await loadQuizzes()
    }
  },
)

async function loadQuizzes(): Promise<void> {
  try {
    quizOptions.value = await onboardingAdminApi.getQuizzes()
  } catch {
    // ignore
  }
}

function onPdfSelect(event: { files: File[] }): void {
  const file = event.files[0]
  if (file) emit('upload', file)
}

function openQuizBuilder(): void {
  quizBuilderQuiz.value = null
  quizBuilderVisible.value = true
}

function onQuizSaved(quiz: Quiz): void {
  quizOptions.value.push(quiz)
  form.value.quiz_id = quiz.id
}

function buildContent(): LessonCreatePayload['content'] {
  switch (form.value.kind) {
    case 'text':
      return { markdown: form.value.text_markdown || null }
    case 'video':
      return {
        url: form.value.video_url || null,
        provider: detectProvider(form.value.video_url),
      }
    case 'pdf':
      return { path: pdfMode.value === 'link' ? (form.value.pdf_path || null) : null }
    case 'quiz':
      return { quiz_id: form.value.quiz_id }
    default:
      return null
  }
}

function submit(): void {
  saving.value = true
  const payload: LessonCreatePayload | LessonPatchPayload = {
    title: form.value.title,
    duration_minutes: form.value.duration_minutes,
    content: buildContent(),
    ...(!isEdit.value && { kind: form.value.kind }),
  }
  emit('save', payload)
  saving.value = false
}

function onHide(): void {
  previewVisible.value = false
}
</script>

<style lang="scss" scoped>
.lesson-drawer-body {
  padding: $space-2;
}

.form-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  margin-bottom: $space-1;
  display: block;

  &.required::after {
    content: ' *';
    color: var(--p-red-500);
  }
}

.lesson-md-textarea {
  font-family: 'Courier New', monospace;
  font-size: $font-size-sm;
}

.lesson-text-content {
  font-size: $font-size-sm;
  line-height: 1.7;
  background: var(--p-surface-50);

  :deep(blockquote) {
    border-left: 4px solid var(--p-primary-color);
    background: var(--p-surface-100);
    padding: 0.75rem 1rem;
    margin: 1rem 0;
    border-radius: 4px;
  }
}

.video-preview {
  border-radius: $radius-md;
  overflow: hidden;
}

/* close button is rendered in custom #header slot — native one not shown with custom slot */
</style>
