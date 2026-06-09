<template>
  <div class="company-branding-section">
    <div class="section-header">
      <div class="section-header-text">
        <h3 class="section-title">{{ t('branding.title') }}</h3>
        <p class="section-subtitle">{{ t('branding.subtitle') }}</p>
      </div>
      <div v-if="canEdit" class="section-header-actions">
        <Button
          icon="pi pi-save"
          :label="saving ? t('branding.saving') : t('branding.save')"
          :loading="saving"
          :disabled="!companyId || loading"
          @click="save"
        />
      </div>
    </div>

    <div v-if="loading && !branding" class="loading-state">
      <ProgressSpinner style="width: 32px; height: 32px" />
    </div>

    <div v-else class="branding-grid">
      <!-- Logo -->
      <div class="branding-block">
        <h4 class="block-title">{{ t('branding.logo.title') }}</h4>
        <div class="logo-row">
          <div class="logo-preview">
            <img v-if="logoUrl" :src="logoUrl" :alt="t('branding.logo.title')" />
            <span v-else class="logo-empty">{{ t('branding.logo.empty') }}</span>
          </div>
          <FileUpload
            v-if="canEdit"
            mode="basic"
            :auto="false"
            accept="image/*"
            :max-file-size="2_097_152"
            :choose-label="uploadingLogo ? t('branding.logo.uploading') : t('branding.logo.upload')"
            custom-upload
            :disabled="uploadingLogo || !companyId"
            @select="onLogoSelect"
          />
        </div>
      </div>

      <!-- Palette -->
      <div class="branding-block">
        <h4 class="block-title">{{ t('branding.colors.title') }}</h4>
        <div class="color-grid">
          <div v-for="key in colorKeys" :key="key" class="color-item">
            <label class="color-label">{{ t(`branding.colors.${key}`) }}</label>
            <div class="color-controls">
              <ColorPicker
                :model-value="stripHash(form.colors[key])"
                format="hex"
                :disabled="!canEdit"
                @update:model-value="onColorChange(key, $event)"
              />
              <InputText
                :model-value="form.colors[key]"
                :disabled="!canEdit"
                class="color-hex"
                @update:model-value="onColorTextChange(key, $event)"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Fonts -->
      <div class="branding-block">
        <h4 class="block-title">{{ t('branding.fonts.title') }}</h4>
        <div class="field-grid">
          <div class="field">
            <label class="field-label">{{ t('branding.fonts.heading') }}</label>
            <InputText v-model="form.fonts.heading" :disabled="!canEdit" class="w-full" />
          </div>
          <div class="field">
            <label class="field-label">{{ t('branding.fonts.body') }}</label>
            <InputText v-model="form.fonts.body" :disabled="!canEdit" class="w-full" />
          </div>
        </div>
      </div>

      <!-- Header / Footer (localized) -->
      <div class="branding-block">
        <h4 class="block-title">{{ t('branding.text.title') }}</h4>
        <div class="field-grid">
          <div class="field">
            <label class="field-label">{{ t('branding.text.headerRu') }}</label>
            <Textarea v-model="form.header.ru" :disabled="!canEdit" rows="2" class="w-full" />
          </div>
          <div class="field">
            <label class="field-label">{{ t('branding.text.headerEn') }}</label>
            <Textarea v-model="form.header.en" :disabled="!canEdit" rows="2" class="w-full" />
          </div>
          <div class="field">
            <label class="field-label">{{ t('branding.text.footerRu') }}</label>
            <Textarea v-model="form.footer.ru" :disabled="!canEdit" rows="2" class="w-full" />
          </div>
          <div class="field">
            <label class="field-label">{{ t('branding.text.footerEn') }}</label>
            <Textarea v-model="form.footer.en" :disabled="!canEdit" rows="2" class="w-full" />
          </div>
        </div>
      </div>

      <!-- Requisites -->
      <div class="branding-block">
        <h4 class="block-title">{{ t('branding.requisites.title') }}</h4>
        <div class="field">
          <label class="field-label">{{ t('branding.requisites.label') }}</label>
          <Textarea
            v-model="requisitesText"
            :disabled="!canEdit"
            rows="3"
            class="w-full"
            :placeholder="t('branding.requisites.placeholder')"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import ColorPicker from 'primevue/colorpicker'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import FileUpload, { type FileUploadSelectEvent } from 'primevue/fileupload'
import ProgressSpinner from 'primevue/progressspinner'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { useUserStore } from '@/stores/user'
import { canManageBranding } from '@/shared/auth/capabilities'
import type { Branding, BrandingColors, BrandingFonts } from '@/entities/branding'
import type { UpdateBrandingRequest } from '@/api/types/branding'
import type { LocalizedText } from '@/shared/types'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const props = defineProps<{
  companyId: number | null
}>()

const { t } = useLocalI18n({ en, ru })
const { notifyApiError, notifySuccess } = useNotifications()
const { brandingService } = useServices()
const userStore = useUserStore()

const canEdit = computed(() => canManageBranding(userStore.getUserRole))

const colorKeys: (keyof BrandingColors)[] = ['primary', 'secondary', 'accent', 'text', 'bg']

const branding = ref<Branding | null>(null)
const loading = ref(false)
const saving = ref(false)
const uploadingLogo = ref(false)
const logoUrl = ref<string | null>(null)

// Editable form state — hydrated from the loaded branding row.
const form = reactive<{
  colors: BrandingColors
  fonts: BrandingFonts
  header: { ru: string; en: string }
  footer: { ru: string; en: string }
}>({
  colors: { primary: '', secondary: '', accent: '', text: '', bg: '' },
  fonts: { heading: '', body: '' },
  header: { ru: '', en: '' },
  footer: { ru: '', en: '' },
})

// Requisites edited as a free-form multiline text; persisted under a single
// `text` key so the bag stays valid JSON without forcing a structured editor.
const requisitesText = ref('')

const stripHash = (value: string): string => (value ?? '').replace(/^#/, '')

// header / footer are LocalizedText (`string | { ru, en }`); read a locale safely.
const localeValue = (text: LocalizedText | null | undefined, key: 'ru' | 'en'): string => {
  if (text === null || text === undefined) return ''
  if (typeof text === 'string') return text
  return text[key] ?? ''
}

const hydrate = (b: Branding) => {
  branding.value = b
  logoUrl.value = b.logoUrl
  form.colors = { ...b.colors }
  form.fonts = { ...b.fonts }
  form.header = { ru: localeValue(b.header, 'ru'), en: localeValue(b.header, 'en') }
  form.footer = { ru: localeValue(b.footer, 'ru'), en: localeValue(b.footer, 'en') }
  const req = b.requisites
  requisitesText.value =
    req && typeof req === 'object' && typeof (req as Record<string, unknown>).text === 'string'
      ? ((req as Record<string, unknown>).text as string)
      : req
        ? JSON.stringify(req, null, 2)
        : ''
}

const load = async (companyId: number) => {
  loading.value = true
  try {
    const b = await brandingService.fetchBranding(companyId)
    hydrate(b)
  } catch (error: unknown) {
    notifyApiError(error, t('branding.errors.load'))
  } finally {
    loading.value = false
  }
}

const onColorChange = (key: keyof BrandingColors, value: unknown) => {
  // PrimeVue ColorPicker (format="hex") emits the hex without the leading `#`.
  const hex = typeof value === 'string' ? value : ''
  form.colors[key] = hex ? `#${hex.replace(/^#/, '')}` : ''
}

const onColorTextChange = (key: keyof BrandingColors, value: string | undefined) => {
  form.colors[key] = value ?? ''
}

const onLogoSelect = async (event: FileUploadSelectEvent) => {
  const files = Array.isArray(event.files) ? event.files : [event.files]
  const file = files[0] as File | undefined
  if (!file || props.companyId === null) return

  uploadingLogo.value = true
  try {
    // Upload returns only the fresh { logo_path, logo_url }; refresh the
    // preview URL and the cached branding's logo path in place.
    const uploaded = await brandingService.uploadLogo(props.companyId, file)
    logoUrl.value = uploaded.logo_url
    if (branding.value !== null) {
      branding.value = { ...branding.value, logoPath: uploaded.logo_path, logoUrl: uploaded.logo_url }
    }
    notifySuccess(t('branding.logo.uploaded'))
  } catch (error: unknown) {
    notifyApiError(error, t('branding.errors.logo'))
  } finally {
    uploadingLogo.value = false
  }
}

const buildPayload = (): UpdateBrandingRequest => {
  const requisites = parseRequisites(requisitesText.value)
  return {
    colors: { ...form.colors },
    fonts: { ...form.fonts },
    header: form.header.ru || form.header.en ? { ru: form.header.ru, en: form.header.en } : null,
    footer: form.footer.ru || form.footer.en ? { ru: form.footer.ru, en: form.footer.en } : null,
    requisites,
  }
}

const parseRequisites = (text: string): Record<string, unknown> | null => {
  const trimmed = text.trim()
  if (trimmed === '') return null
  // Accept either raw JSON or plain text — plain text lands under `text`.
  try {
    const parsed: unknown = JSON.parse(trimmed)
    if (parsed && typeof parsed === 'object') return parsed as Record<string, unknown>
  } catch {
    // fall through to plain-text wrapping
  }
  return { text: trimmed }
}

const save = async () => {
  if (props.companyId === null) return
  saving.value = true
  try {
    const updated = await brandingService.updateBranding(props.companyId, buildPayload())
    hydrate(updated)
    notifySuccess(t('branding.saved'))
  } catch (error: unknown) {
    notifyApiError(error, t('branding.errors.save'))
  } finally {
    saving.value = false
  }
}

watch(
  () => props.companyId,
  (id) => {
    if (id !== null && id > 0) {
      void load(id)
    }
  },
  { immediate: true },
)
</script>

<style lang="scss" scoped>
.company-branding-section {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;

  .section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;

    .section-title {
      margin: 0 0 0.25rem;
      font-size: $font-size-lg;
      font-weight: $font-weight-semibold;
      color: $surface-900;
    }

    .section-subtitle {
      margin: 0;
      font-size: $font-size-sm;
      color: $surface-600;
    }
  }

  .loading-state {
    display: flex;
    justify-content: center;
    padding: 2rem;
  }

  .branding-grid {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;

    .branding-block {
      .block-title {
        margin: 0 0 0.75rem;
        font-size: $font-size-md;
        font-weight: $font-weight-semibold;
        color: $surface-800;
      }
    }
  }

  .logo-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;

    .logo-preview {
      width: 120px;
      height: 80px;
      border: 1px dashed $surface-300;
      border-radius: $card-border-radius;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      background: $surface-50;

      img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
      }

      .logo-empty {
        font-size: $font-size-sm;
        color: $surface-400;
      }
    }
  }

  .color-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1rem;

    .color-item {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;

      .color-label {
        font-size: $font-size-sm;
        font-weight: $font-weight-medium;
        color: $surface-700;
      }

      .color-controls {
        display: flex;
        align-items: center;
        gap: 0.5rem;

        .color-hex {
          flex: 1;
          min-width: 0;
        }
      }
    }
  }

  .field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1rem;
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;

    .field-label {
      font-size: $font-size-sm;
      font-weight: $font-weight-medium;
      color: $surface-700;
    }
  }
}
</style>
