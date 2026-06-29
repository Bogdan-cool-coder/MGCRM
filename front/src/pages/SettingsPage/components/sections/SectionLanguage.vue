<template>
  <div class="section-language">
    <div class="profile-section">
      <p class="text-muted mb-4">{{ t('settings.language.hint') }}</p>

      <div class="locale-options">
        <button
          v-for="locale in LOCALES"
          :key="locale.value"
          type="button"
          class="locale-card"
          :class="{ 'locale-card--active': localeDraft === locale.value }"
          @click="onLocaleSelect(locale.value)"
        >
          <span class="locale-card__flag">{{ locale.flag }}</span>
          <span class="locale-card__label">{{ locale.label }}</span>
          <i v-if="localeDraft === locale.value" class="pi pi-check locale-card__check" />
        </button>
      </div>
    </div>

    <!-- Save bar -->
    <div v-if="isDirty" class="settings-save-bar">
      <Button
        icon="pi pi-times"
        :label="t('settings.discard')"
        severity="secondary"
        text
        @click="discard"
      />
      <Button
        icon="pi pi-check"
        :label="t('settings.save')"
        :loading="savingLocale"
        @click="save"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, inject, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import { localeManager } from '@/application/locale'
import { getI18nLocale } from '@/plugins/i18n'
import { SETTINGS_MARK_DIRTY_KEY, SETTINGS_MARK_CLEAN_KEY } from '../../composables/useSettings'
import type { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'
import type { AvailableLocales } from '@/plugins/i18n'

const { t } = useI18n()

const markDirty = inject<() => void>(SETTINGS_MARK_DIRTY_KEY, () => {})
const markClean = inject<() => void>(SETTINGS_MARK_CLEAN_KEY, () => {})

type ProfilePageReturn = ReturnType<typeof useProfilePage>

const props = defineProps<{
  savingLocale: boolean
  changeLocale: ProfilePageReturn['changeLocale']
}>()

const LOCALES: { value: AvailableLocales; label: string; flag: string }[] = [
  { value: 'ru', label: 'Русский', flag: 'RU' },
  { value: 'en', label: 'English', flag: 'EN' },
]

const localeDraft = ref<AvailableLocales>(getI18nLocale() as AvailableLocales)
const savedLocale = ref<AvailableLocales>(getI18nLocale() as AvailableLocales)

onMounted(() => {
  const current = getI18nLocale() as AvailableLocales
  localeDraft.value = current
  savedLocale.value = current
})

const isDirty = computed(() => localeDraft.value !== savedLocale.value)

watch(isDirty, (dirty) => {
  if (dirty) markDirty()
  else markClean()
})

function onLocaleSelect(locale: AvailableLocales) {
  localeDraft.value = locale
  // Preview immediately
  localeManager.changeLocale(locale)
}

async function save() {
  await props.changeLocale(localeDraft.value)
  savedLocale.value = localeDraft.value
  markClean()
}

function discard() {
  localeManager.changeLocale(savedLocale.value)
  localeDraft.value = savedLocale.value
  markClean()
}

defineExpose({ discard })
</script>

<style lang="scss" scoped>
.section-language {
  padding: $space-6;
}

.profile-section {
  margin-bottom: $space-6;
}

.text-muted {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.locale-options {
  display: flex;
  gap: $space-3;
  flex-wrap: wrap;
}

.locale-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  width: 160px;
  background: $surface-card;
  border: 2px solid $surface-200;
  border-radius: $radius-md;
  cursor: pointer;
  transition: border-color $transition-fast, background-color $transition-fast;

  .app-dark & {
    // BUG-2: surface-800 in dark = #F1F2F3; use surface-100
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }

  &:hover {
    border-color: $primary;
    background: rgba($primary, 0.03);
  }

  &--active {
    border-color: $primary;
    background: rgba($primary, 0.06);
  }

  &__flag {
    font-size: $font-size-xl;
    font-weight: $font-weight-bold;
    color: $primary;
    font-family: $font-family-mono;
    letter-spacing: 0.05em;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-50);
    }
  }

  &__check {
    position: absolute;
    top: $space-2;
    right: $space-2;
    font-size: $font-size-sm;
    color: $primary;
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
    // BUG-2: surface-800 in dark = #F1F2F3; use surface-100
    background: var(--p-surface-100);
    border-top-color: var(--p-surface-200);
  }
}
</style>
