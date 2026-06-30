<template>
  <div class="section-profile">
    <!-- Avatar row -->
    <div class="profile-section">
      <div v-if="user" class="profile-avatar-row mb-4">
        <img
          v-if="avatarPath"
          :src="avatarPath"
          :alt="user.full_name"
          class="profile-avatar-row__img"
        />
        <CrmAvatar v-else :name="user.full_name" :size="72" />
        <div class="profile-avatar-row__actions">
          <!-- Скрытый file-input; открывается через avatarInput.click() -->
          <input
            ref="avatarInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="d-none"
            @change="onAvatarFileSelected"
          />
          <Button
            icon="pi pi-upload"
            :label="t('profile.avatar.upload')"
            severity="secondary"
            outlined
            size="small"
            :loading="avatarUploading"
            @click="avatarInput?.click()"
          />
          <Button
            v-if="avatarPath"
            icon="pi pi-trash"
            :label="t('profile.avatar.remove')"
            severity="danger"
            text
            size="small"
            :disabled="avatarUploading"
            @click="removeAvatar"
          />
        </div>
      </div>
    </div>

    <!-- Кроп-модал (монтируется в body через append-to) -->
    <AvatarCropModal
      v-model:visible="cropModalVisible"
      :image-src="cropImageSrc"
      :on-upload="props.uploadAvatar"
    />

    <!-- Fields -->
    <div v-if="user" class="row g-4">
      <div class="col-md-6">
        <div class="profile-field">
          <label class="profile-field__label">{{ t('profile.fields.full_name') }}</label>
          <InputText v-model="fullNameDraft" class="w-100 mt-1" />
        </div>
      </div>
      <div class="col-md-6">
        <div class="profile-field">
          <label class="profile-field__label">{{ t('profile.fields.email') }}</label>
          <InputText :model-value="user.email" disabled class="w-100 mt-1" />
        </div>
      </div>
      <div class="col-md-6">
        <div class="profile-field">
          <label class="profile-field__label">{{ t('profile.fields.role') }}</label>
          <InputText
            :model-value="t(`roles.${user.role}`, user.role)"
            disabled
            class="w-100 mt-1"
          />
        </div>
      </div>
    </div>

    <!-- Save bar -->
    <div v-if="fullNameDirty" class="settings-save-bar">
      <Button
        icon="pi pi-times"
        :label="t('settings.discard')"
        severity="secondary"
        text
        @click="discardProfile"
      />
      <Button
        icon="pi pi-check"
        :label="t('settings.save')"
        :loading="savingProfile"
        :disabled="!fullNameDirty"
        @click="onSaveProfile"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import CrmAvatar from '@/components/ui/CrmAvatar.vue'
import AvatarCropModal from './profile/AvatarCropModal.vue'
import { SETTINGS_MARK_DIRTY_KEY, SETTINGS_MARK_CLEAN_KEY } from '../../composables/useSettings'
import type { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'

const { t } = useI18n()
const toast = useToast()

// Injected dirty/clean callbacks from useSettings
const markDirty = inject<() => void>(SETTINGS_MARK_DIRTY_KEY, () => {})
const markClean = inject<() => void>(SETTINGS_MARK_CLEAN_KEY, () => {})

type ProfilePageReturn = ReturnType<typeof useProfilePage>

const props = defineProps<{
  user: ProfilePageReturn['user']['value']
  avatarPath: string | null
  avatarUploading: boolean
  savingProfile: boolean
  saveFullName: ProfilePageReturn['saveFullName']
  uploadAvatar: ProfilePageReturn['uploadAvatar']
  removeAvatar: ProfilePageReturn['removeAvatar']
}>()

const avatarInput = ref<HTMLInputElement | null>(null)

// ─── Аватар-кроп ─────────────────────────────────────────────────────────────
const cropModalVisible = ref(false)
const cropImageSrc = ref('')

// ─── Full name draft ─────────────────────────────────────────────────────────
const fullNameDraft = ref(props.user?.full_name ?? '')

watch(
  () => props.user?.full_name,
  (name) => {
    fullNameDraft.value = name ?? ''
  },
)

const fullNameDirty = computed(
  () =>
    fullNameDraft.value.trim() !== (props.user?.full_name ?? '').trim() &&
    !!fullNameDraft.value.trim(),
)

watch(fullNameDirty, (dirty) => {
  if (dirty) markDirty()
  else markClean()
})

function discardProfile() {
  fullNameDraft.value = props.user?.full_name ?? ''
  markClean()
}

async function onSaveProfile() {
  const success = await props.saveFullName(fullNameDraft.value)
  if (success) {
    markClean()
  }
}

// ─── Avatar ──────────────────────────────────────────────────────────────────
// При закрытии кроп-модала — очищаем cropImageSrc (objectURL уже ревокирован в модале)
watch(cropModalVisible, (visible) => {
  if (!visible) {
    cropImageSrc.value = ''
  }
})

/** Открывает кроп-модал после валидации типа/размера файла */
function onAvatarFileSelected(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = '' // сброс value — чтобы повторный выбор того же файла работал

  if (!file) return

  const ALLOWED = ['image/jpeg', 'image/png', 'image/webp']
  if (!ALLOWED.includes(file.type)) {
    toast.add({
      severity: 'error',
      summary: t('settings.profile.avatarCrop.invalidType'),
      life: 4000,
    })
    return
  }
  if (file.size > 20 * 1024 * 1024) {
    toast.add({
      severity: 'error',
      summary: t('settings.profile.avatarCrop.fileTooLarge'),
      life: 4000,
    })
    return
  }

  // Создаём objectURL и открываем кроп
  if (cropImageSrc.value) {
    URL.revokeObjectURL(cropImageSrc.value)
  }
  cropImageSrc.value = URL.createObjectURL(file)
  cropModalVisible.value = true
}

</script>

<style lang="scss" scoped>
.section-profile {
  padding: $space-6;
}

.profile-section {
  margin-bottom: $space-6;
}

.profile-avatar-row {
  display: flex;
  align-items: center;
  gap: $space-4;

  &__img {
    width: 72px;
    height: 72px;
    border-radius: $radius-circle;
    object-fit: cover;
    flex-shrink: 0;
    border: 1px solid $surface-200;

    .app-dark & {
      border-color: var(--p-surface-700);
    }
  }

  &__actions {
    display: flex;
    align-items: center;
    gap: $space-2;
    flex-wrap: wrap;
  }
}

.profile-field {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-700);
    }
  }
}

.settings-save-bar {
  display: flex;
  gap: $space-2;
  justify-content: flex-end;
  padding: $space-4 $space-6;
  border-top: 1px solid $surface-200;
  background: $surface-card;
  margin: $space-4 calc(-1 * $space-6) 0;
  position: sticky;
  bottom: 0;
  z-index: 1;

  .app-dark & {
    // BUG-2: surface-800 in dark = #F1F2F3 (light gray); use surface-100 for dark panel
    background: var(--p-surface-100);
    border-top-color: var(--p-surface-200);
  }
}
</style>
