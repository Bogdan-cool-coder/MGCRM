<template>
  <div class="settings-page">
    <PageHeader
      icon="pi pi-cog"
      :title="t('settings.pageTitle')"
    />

    <!-- Единственный экземпляр диалога «Несохранённые изменения» -->
    <UnsavedChangesDialog
      v-model:visible="settings.dialogVisible.value"
      @leave="settings.onDialogLeave"
      @stay="settings.onDialogStay"
    />

    <!-- Mobile section select (<768px) -->
    <div class="settings-page__detail-mobile-select">
      <Select
        :model-value="settings.activeSection.value"
        :options="mobileSectionOptions"
        option-label="label"
        option-value="value"
        class="w-100"
        @update:model-value="settings.setSection($event as string)"
      />
    </div>

    <div class="settings-page__body">
      <!-- Sidebar (desktop ≥768px) -->
      <div class="settings-page__sidebar">
        <SettingsSidebar
          :active-section="settings.activeSection.value"
          @select="settings.setSection($event)"
        />
      </div>

      <!-- Detail panel -->
      <div class="settings-page__detail">
        <SectionProfile
          v-if="settings.activeSection.value === 'profile'"
          :user="profile.user.value"
          :avatar-path="profile.avatarPath.value"
          :avatar-uploading="profile.avatarUploading.value"
          :saving-profile="profile.savingProfile.value"
          :save-full-name="profile.saveFullName"
          :upload-avatar="profile.uploadAvatar"
          :remove-avatar="profile.removeAvatar"
        />

        <SectionSecurity
          v-else-if="settings.activeSection.value === 'security'"
          :profile="profile"
        />

        <SectionAppearance
          v-else-if="settings.activeSection.value === 'appearance'"
        />

        <SectionLanguage
          v-else-if="settings.activeSection.value === 'language'"
          :saving-locale="profile.savingLocale.value"
          :change-locale="profile.changeLocale"
        />

        <SectionChannels
          v-else-if="settings.activeSection.value === 'channels'"
          :telegram-linked="profile.telegramLinked.value"
          :telegram-username="profile.telegramUsername.value"
          :telegram-linking="profile.telegramLinking.value"
          :telegram-unlinking="profile.telegramUnlinking.value"
          :link-telegram="profile.linkTelegram"
          :unlink-telegram="profile.unlinkTelegram"
        />

        <SectionDirectories
          v-else-if="isDirectoriesSection(settings.activeSection.value)"
          :active-tab="settings.activeSection.value"
          @tab-change="settings.setSection($event)"
        />

        <SysTabUsers
          v-else-if="settings.activeSection.value === 'users'"
        />

        <SysTabAccessControl
          v-else-if="settings.activeSection.value === 'access-control'"
        />

        <SysTabAutomationRuns
          v-else-if="settings.activeSection.value === 'automation-runs'"
        />

        <SectionSystemReset
          v-else-if="settings.activeSection.value === 'system-reset'"
        />

        <SectionComingSoon v-else />
      </div>
    </div>
  </div>

</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import SettingsSidebar from './components/SettingsSidebar.vue'
import SectionProfile from './components/sections/SectionProfile.vue'
import SectionSecurity from './components/sections/SectionSecurity.vue'
import SectionAppearance from './components/sections/SectionAppearance.vue'
import SectionLanguage from './components/sections/SectionLanguage.vue'
import SectionChannels from './components/sections/SectionChannels.vue'
import SectionDirectories from './components/sections/SectionDirectories.vue'
import SectionSystemReset from './components/sections/SectionSystemReset.vue'
import SysTabUsers from './components/sections/system/SysTabUsers.vue'
import SysTabAccessControl from './components/sections/system/SysTabAccessControl.vue'
import SysTabAutomationRuns from './components/sections/system/SysTabAutomationRuns.vue'
import SectionComingSoon from './components/sections/SectionComingSoon.vue'
import UnsavedChangesDialog from './components/UnsavedChangesDialog.vue'
import { useSettings, DIRECTORIES_KEYS } from './composables/useSettings'
import { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'

const { t } = useI18n()

const settings = useSettings()
const profile = useProfilePage()

function isDirectoriesSection(key: string): boolean {
  return (DIRECTORIES_KEYS as readonly string[]).includes(key)
}

// Mobile dropdown — Ф1 + Ф2 directories sections (admin/director see directories)
const mobileSectionOptions = computed(() => {
  const base = [
    { value: 'profile',    label: t('settings.sections.profile.title') },
    { value: 'security',   label: t('settings.sections.security.title') },
    { value: 'appearance', label: t('settings.sections.appearance.title') },
    { value: 'language',   label: t('settings.sections.language.title') },
    { value: 'channels',   label: t('settings.sections.channels.title') },
  ]
  if (settings.isAdminOrDirector.value) {
    base.push(
      { value: 'countries',      label: t('settings.sections.countries.title') },
      { value: 'acq-channels',   label: t('settings.sections.acq-channels.title') },
      { value: 'disc-reasons',   label: t('settings.sections.disc-reasons.title') },
      { value: 'catalog',        label: t('settings.sections.catalog.title') },
      { value: 'exchange-rates', label: t('settings.sections.exchange-rates.title') },
      { value: 'users',          label: t('settings.sections.users.title') },
      { value: 'access-control', label: t('settings.sections.access-control.title') },
      { value: 'automation-runs',label: t('settings.sections.automation-runs.title') },
    )
    if (settings.isAdmin.value) {
      base.push({ value: 'system-reset', label: t('settings.sections.system-reset.title') })
    }
  }
  return base
})
</script>

<style lang="scss" scoped>
.settings-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  // Compensates AppShell outer padding (same as ProfilePage)
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.settings-page__body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.settings-page__sidebar {
  width: 240px;
  flex-shrink: 0;
  border-right: 1px solid $surface-200;
  overflow-y: auto;
  background: $surface-card;

  .app-dark & {
    background: var(--p-surface-100);
    border-right-color: var(--p-surface-200);
  }
}

.settings-page__detail {
  flex: 1;
  overflow-y: auto;
  background: $surface-50;

  .app-dark & {
    background: var(--p-surface-50);
  }
}

.settings-page__detail-mobile-select {
  display: none;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }
}

@media (max-width: 767px) {
  .settings-page__sidebar {
    display: none;
  }

  .settings-page__detail-mobile-select {
    display: block;
  }
}
</style>
