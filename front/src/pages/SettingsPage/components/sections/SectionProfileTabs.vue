<template>
  <div class="profile-tabs-container">
    <!-- Line-underline tabs (Ф2-pattern из SectionDirectories) -->
    <div class="profile-tabs">
      <Tabs :value="activeTab" @update:value="onTabChange">
        <TabList>
          <Tab value="profile">{{ t('settings.profile.tabs.profile') }}</Tab>
          <Tab value="security">{{ t('settings.profile.tabs.security') }}</Tab>
          <Tab value="appearance">{{ t('settings.profile.tabs.appearance') }}</Tab>
          <Tab value="language">{{ t('settings.profile.tabs.language') }}</Tab>
        </TabList>
      </Tabs>
    </div>

    <!-- v-if (не TabPanels) — каждый дочерний компонент монтируется только при активном табе -->
    <div class="profile-tab-content">
      <SectionProfile
        v-if="activeTab === 'profile'"
        :user="user"
        :avatar-path="avatarPath"
        :avatar-uploading="avatarUploading"
        :saving-profile="savingProfile"
        :save-full-name="saveFullName"
        :upload-avatar="uploadAvatar"
        :remove-avatar="removeAvatar"
      />

      <SectionSecurity
        v-else-if="activeTab === 'security'"
        :profile="profileComposable"
      />

      <SectionAppearance
        v-else-if="activeTab === 'appearance'"
      />

      <SectionLanguage
        v-else-if="activeTab === 'language'"
        :saving-locale="savingLocale"
        :change-locale="changeLocale"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import SectionProfile from './SectionProfile.vue'
import SectionSecurity from './SectionSecurity.vue'
import SectionAppearance from './SectionAppearance.vue'
import SectionLanguage from './SectionLanguage.vue'
import type { ProfileTabKey } from '../../composables/useSettings'
import type { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'

const { t } = useI18n()

type ProfilePageReturn = ReturnType<typeof useProfilePage>

defineProps<{
  activeTab: ProfileTabKey
  // Данные для SectionProfile
  user: ProfilePageReturn['user']['value']
  avatarPath: string | null
  avatarUploading: boolean
  savingProfile: boolean
  saveFullName: ProfilePageReturn['saveFullName']
  uploadAvatar: ProfilePageReturn['uploadAvatar']
  removeAvatar: ProfilePageReturn['removeAvatar']
  // Данные для SectionSecurity — пробрасываем весь composable
  profileComposable: ProfilePageReturn
  // Данные для SectionLanguage
  savingLocale: boolean
  changeLocale: ProfilePageReturn['changeLocale']
}>()

const emit = defineEmits<{
  'tab-change': [key: ProfileTabKey]
}>()

function onTabChange(value: string | number | undefined) {
  if (value !== undefined) {
    emit('tab-change', value as ProfileTabKey)
  }
}
</script>

<style lang="scss" scoped>
.profile-tabs-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.profile-tabs {
  flex-shrink: 0;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  padding: 0 $space-6;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }

  // line-underline стиль (паттерн Ф2 из SectionDirectories)
  :deep(.p-tablist) {
    background: transparent;
    border: none;
    padding: 0;
  }

  :deep(.p-tab) {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    padding: $space-3 $space-4;
    color: $surface-600;
    border-bottom: 2px solid transparent;
    transition: color var(--app-transition-fast), border-color var(--app-transition-fast);
    cursor: pointer;
    white-space: nowrap;

    // $surface-600 / $surface-900 theme-reactive → muted + hover read in dark.
    // No dark branch: nested `.app-dark &` inside :deep() compiles dead.
    &:hover {
      color: $surface-900;
    }
  }

  // Active tab + underline bar: --p-primary-color = navy #172747 (light) /
  // accent #4C7DF0 (dark) — correct in both from one rule (no dead dark branch).
  :deep(.p-tab[aria-selected="true"]) {
    color: var(--p-primary-color);
    font-weight: $font-weight-semibold;
  }

  :deep(.p-tablist-active-bar) {
    background: var(--p-primary-color);
    height: 2px;
  }
}

.profile-tab-content {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}
</style>
