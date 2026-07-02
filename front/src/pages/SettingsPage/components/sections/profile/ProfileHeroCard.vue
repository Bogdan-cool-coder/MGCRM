<template>
  <div class="profile-hero">
    <!-- Navy gradient top -->
    <div class="profile-hero__top">
      <img
        v-if="avatarPath"
        :src="avatarPath"
        :alt="user?.full_name ?? ''"
        class="profile-hero__avatar-img"
      />
      <EntityAvatar
        v-else
        :name="user?.full_name ?? '?'"
        :pixel-size="72"
        on-brand
        class="profile-hero__avatar"
      />

      <div class="profile-hero__ident">
        <div class="profile-hero__name-row">
          <span class="profile-hero__name">{{ user?.full_name }}</span>
          <span v-if="user?.role" class="profile-hero__role">
            {{ t(`roles.${user.role}`, user.role) }}
          </span>
        </div>
        <div class="profile-hero__email">{{ user?.email }}</div>
      </div>

      <input
        ref="avatarInput"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        class="d-none"
        @change="onAvatarFileSelected"
      />
      <Button
        icon="pi pi-camera"
        :label="t('profile.avatar.change')"
        outlined
        size="small"
        class="profile-hero__photo-btn"
        :loading="avatarUploading"
        @click="avatarInput?.click()"
      />
    </div>

    <!-- Meta strip -->
    <div class="profile-hero__meta">
      <span class="profile-hero__meta-item profile-hero__meta-item--2fa" :class="{ 'profile-hero__meta-item--muted': !twoFaOn }">
        <i class="pi pi-verified" aria-hidden="true" />
        {{ twoFaOn ? t('settings.profile.hero.twoFaOn') : t('settings.profile.hero.twoFaOff') }}
      </span>
      <span class="profile-hero__meta-item">
        <i class="pi pi-globe" aria-hidden="true" />
        {{ localeLabel }}
      </span>
    </div>

    <!-- Crop modal (mounts to body) -->
    <AvatarCropModal
      v-model:visible="cropModalVisible"
      :image-src="cropImageSrc"
      :on-upload="uploadAvatar"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import AvatarCropModal from './AvatarCropModal.vue'
import type { User } from '@/entities/user'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{
  user: User | null
  avatarPath: string | null
  avatarUploading: boolean
  uploadAvatar: (file: File) => Promise<void>
}>()

const twoFaOn = computed(() => !!props.user?.totp_enabled)

const localeLabel = computed(() => {
  const loc = props.user?.locale
  if (loc === 'en') return 'English'
  // ru and null/unknown default to Russian (app default locale)
  return 'Русский'
})

// ─── Avatar crop ─────────────────────────────────────────────────────────────
const avatarInput = ref<HTMLInputElement | null>(null)
const cropModalVisible = ref(false)
const cropImageSrc = ref('')

watch(cropModalVisible, (visible) => {
  if (!visible) cropImageSrc.value = ''
})

function onAvatarFileSelected(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''

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

  if (cropImageSrc.value) URL.revokeObjectURL(cropImageSrc.value)
  cropImageSrc.value = URL.createObjectURL(file)
  cropModalVisible.value = true
}
</script>

<style lang="scss" scoped>
.profile-hero {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  overflow: hidden;
  margin-bottom: $space-4;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

// ─── Gradient top (brand invariant — navy, from $primary tokens) ──────────────
/* stylelint-disable-next-line scale-unlimited/declaration-strict-value */
.profile-hero__top {
  display: flex;
  align-items: center;
  gap: $space-4;
  padding: $space-5 $space-6;
  // brand hero gradient — navy invariant from $primary tokens (ТЗ §3)
  background: linear-gradient(100deg, $primary-900, $primary-800);
}

.profile-hero__avatar,
.profile-hero__avatar-img {
  flex-shrink: 0;
}

.profile-hero__avatar-img {
  width: 72px;
  height: 72px;
  border-radius: $radius-circle;
  object-fit: cover;
  // brand invariant — white ring on navy
  /* stylelint-disable-next-line scale-unlimited/declaration-strict-value */
  border: 3px solid rgba(255, 255, 255, 0.5);
}

.profile-hero__ident {
  flex: 1;
  min-width: 0;
}

.profile-hero__name-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.profile-hero__name {
  font-size: $font-size-xl;
  font-weight: $font-weight-semibold;
  // brand invariant — white on navy
  /* stylelint-disable-next-line scale-unlimited/declaration-strict-value */
  color: #fff;
}

.profile-hero__role {
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  padding: 3px $space-2;
  border-radius: $radius-pill;
  /* stylelint-disable scale-unlimited/declaration-strict-value */
  // brand invariant — semi-transparent white pill on navy
  background: rgba(255, 255, 255, 0.18);
  color: #fff;
  /* stylelint-enable scale-unlimited/declaration-strict-value */
  white-space: nowrap;
}

.profile-hero__email {
  font-size: $font-size-sm;
  margin-top: 4px;
  // brand invariant — muted white on navy
  /* stylelint-disable-next-line scale-unlimited/declaration-strict-value */
  color: rgba(255, 255, 255, 0.82);
}

// «Изменить фото» — semi-transparent white outlined on navy (brand invariant).
// The class lands on the PrimeVue Button root (.p-button); style it directly.
/* stylelint-disable scale-unlimited/declaration-strict-value */
.profile-hero__photo-btn.p-button {
  flex-shrink: 0;
  color: #fff;
  border-color: rgba(255, 255, 255, 0.32);
  background: rgba(255, 255, 255, 0.12);

  &:hover {
    color: #fff;
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.2);
  }
}
/* stylelint-enable scale-unlimited/declaration-strict-value */

// ─── Meta strip ──────────────────────────────────────────────────────────────
.profile-hero__meta {
  display: flex;
  gap: $space-6;
  flex-wrap: wrap;
  padding: $space-3 $space-6;
}

.profile-hero__meta-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-xs;
  color: $surface-600;
  white-space: nowrap;

  i {
    font-size: $font-size-sm;
    color: $surface-400;
  }

  // Inverted dark scale: muted meta text + icons need surface-600 (#8593B0) to
  // read on the dark hero-meta background (surface-300/400/500 are too dark).
  .app-dark & {
    color: var(--p-surface-600);

    i {
      color: var(--p-surface-600);
    }
  }

  &--2fa {
    color: var(--p-green-600);

    i {
      color: var(--p-green-600);
    }

    .app-dark & {
      color: var(--p-green-400);

      i {
        color: var(--p-green-400);
      }
    }
  }

  &--muted {
    color: $surface-500;

    i {
      color: $surface-400;
    }

    .app-dark & {
      color: var(--p-surface-600);
    }
  }
}
</style>
