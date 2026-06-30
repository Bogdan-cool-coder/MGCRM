<template>
  <Dialog
    v-model:visible="localVisible"
    :header="t('settings.profile.avatarCrop.title')"
    :modal="true"
    :draggable="false"
    :closable="!uploading"
    :style="{ width: '520px', maxWidth: '95vw' }"
    append-to="body"
  >
    <div class="cropper-body">
      <Cropper
        ref="cropperRef"
        :src="imageSrc"
        :stencil-component="CircleStencil"
        :stencil-props="{ aspectRatio: 1 }"
        :resize-image="{ adjustStencil: false }"
        background-class="cropper-bg"
        class="cropper-inner"
      />
    </div>

    <p class="cropper-hint">{{ t('settings.profile.avatarCrop.hint') }}</p>

    <template #footer>
      <Button
        :label="t('settings.profile.avatarCrop.cancelBtn')"
        severity="secondary"
        text
        :disabled="uploading"
        @click="closeModal"
      />
      <Button
        icon="pi pi-check"
        :label="t('settings.profile.avatarCrop.saveBtn')"
        :loading="uploading"
        @click="cropAndUpload"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import { Cropper, CircleStencil } from 'vue-advanced-cropper'
import 'vue-advanced-cropper/dist/style.css'

const { t } = useI18n()

const props = defineProps<{
  visible: boolean
  /** objectURL созданный из выбранного файла */
  imageSrc: string
  /** Внешняя функция загрузки — родитель обновляет стор и управляет состоянием */
  onUpload: (file: File) => Promise<void>
}>()

const emit = defineEmits<{
  'update:visible': [v: boolean]
}>()

const localVisible = computed({
  get: () => props.visible,
  set: (v: boolean) => emit('update:visible', v),
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const cropperRef = ref<any>(null)
const uploading = ref(false)

function revokeImage() {
  if (props.imageSrc) {
    URL.revokeObjectURL(props.imageSrc)
  }
}

function closeModal() {
  revokeImage()
  emit('update:visible', false)
}

// Ревокация при закрытии диалога (в том числе через v-model извне)
watch(
  () => props.visible,
  (visible) => {
    if (!visible) {
      revokeImage()
    }
  },
)

onUnmounted(() => {
  // Страховка от утечек памяти — ревокация при демонтаже
  revokeImage()
})

/** Даунскейл canvas до целевого размера (≤1024px по большей стороне) */
function downscaleCanvas(
  source: HTMLCanvasElement,
  maxSide = 1024,
): HTMLCanvasElement {
  const { width, height } = source
  if (width <= maxSide && height <= maxSide) return source

  const ratio = Math.min(maxSide / width, maxSide / height)
  const targetW = Math.round(width * ratio)
  const targetH = Math.round(height * ratio)

  const target = document.createElement('canvas')
  target.width = targetW
  target.height = targetH
  const ctx = target.getContext('2d')
  if (ctx) {
    ctx.drawImage(source, 0, 0, targetW, targetH)
  }
  return target
}

/** canvas → Blob с автоматическим снижением quality если >2МБ */
function canvasToBlob(canvas: HTMLCanvasElement, quality = 0.85): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (!blob) {
          reject(new Error('Canvas toBlob failed'))
          return
        }
        if (blob.size > 2_000_000 && quality > 0.5) {
          // Повтор со сниженным качеством (0.7)
          canvasToBlob(canvas, 0.7).then(resolve).catch(reject)
        } else {
          resolve(blob)
        }
      },
      'image/jpeg',
      quality,
    )
  })
}

async function cropAndUpload() {
  if (!cropperRef.value) return

  const result = cropperRef.value.getResult()
  if (!result?.canvas) return

  uploading.value = true
  try {
    const scaled = downscaleCanvas(result.canvas as HTMLCanvasElement)
    const blob = await canvasToBlob(scaled)
    const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' })
    await props.onUpload(file)
    // Родитель (uploadAvatar) не выбрасывает при ошибке — показывает Toast.
    // Закрываем диалог и ревокируем URL всегда после попытки (успех ≡ отсутствие throw).
    closeModal()
  } catch {
    // uploadAvatar внутри обрабатывает ошибку (Toast), диалог остаётся открытым
  } finally {
    uploading.value = false
  }
}
</script>

<style lang="scss" scoped>
.cropper-body {
  height: 400px;
  background: $surface-900;
  overflow: hidden;
  border-radius: $radius-md;

  .app-dark & {
    // В dark surface-900 = #0a0a0a — очень тёмный фон кропа
    background: var(--p-surface-50);
  }
}

.cropper-inner {
  width: 100%;
  height: 100%;
}

.cropper-hint {
  margin-top: $space-2;
  font-size: $font-size-xs;
  color: $surface-500;
  text-align: center;

  .app-dark & {
    color: var(--p-surface-400);
  }
}
</style>
