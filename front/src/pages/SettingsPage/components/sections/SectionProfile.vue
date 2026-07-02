<template>
  <div class="section-profile">
    <!-- Personal data card (hi-fi TZ §3) -->
    <div class="profile-card">
      <div class="profile-card__head">
        <h3 class="profile-card__title">{{ t('settings.profile.personalData.title') }}</h3>
        <p class="profile-card__desc">{{ t('settings.profile.personalData.desc') }}</p>
      </div>

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
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import { SETTINGS_MARK_DIRTY_KEY, SETTINGS_MARK_CLEAN_KEY } from '../../composables/useSettings'
import type { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'

const { t } = useI18n()

// Injected dirty/clean callbacks from useSettings
const markDirty = inject<() => void>(SETTINGS_MARK_DIRTY_KEY, () => {})
const markClean = inject<() => void>(SETTINGS_MARK_CLEAN_KEY, () => {})

type ProfilePageReturn = ReturnType<typeof useProfilePage>

// Avatar management now lives in ProfileHeroCard (hi-fi TZ §3); this section only
// edits the full-name field. Avatar props are still accepted for parent-prop
// compatibility but consumed by the hero, not here.
const props = defineProps<{
  user: ProfilePageReturn['user']['value']
  savingProfile: boolean
  saveFullName: ProfilePageReturn['saveFullName']
}>()

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
</script>

<style lang="scss" scoped>
// Padding/centering handled by SectionProfileTabs container.
.profile-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  padding: $space-5 $space-6;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.profile-card__head {
  margin-bottom: $space-4;
}

.profile-card__title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.profile-card__desc {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;

  // Inverted dark scale: muted text needs surface-600 to read on dark card.
  .app-dark & {
    color: var(--p-surface-600);
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
  padding-top: $space-4;
  margin-top: $space-4;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}
</style>
