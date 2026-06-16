<template>
  <!-- Popover provides portal + auto-flip + viewport clamp + Esc + focus return out of the box -->
  <Popover ref="popoverRef" append-to="body" :pt="{ root: { style: 'z-index: 9999' } }" @hide="emit('hide')" @show="emit('show')">
    <div class="account-menu">
      <!-- Identity header -->
      <div class="account-menu__identity">
        <div class="account-menu__avatar">
          <img
            v-if="userStore.getAvatarPath"
            :src="userStore.getAvatarPath"
            :alt="userStore.getUserName"
          />
          <span v-else class="account-menu__avatar-initials">{{ initials }}</span>
        </div>
        <div class="account-menu__identity-info">
          <span class="account-menu__name">{{ userStore.getUserName }}</span>
          <span class="account-menu__role">{{ roleLabel }}</span>
        </div>
      </div>

      <Divider />

      <!-- Theme section -->
      <div class="account-menu__section">
        <span class="account-menu__section-label">{{ t('account.theme') }}</span>
        <SelectButton
          v-model="currentTheme"
          :options="themeOptions"
          option-label="label"
          option-value="value"
          class="account-menu__select-btn"
        />
      </div>

      <!-- Language section -->
      <div class="account-menu__section">
        <span class="account-menu__section-label">{{ t('account.language') }}</span>
        <SelectButton
          v-model="currentLocale"
          :options="localeOptions"
          option-label="label"
          option-value="value"
          class="account-menu__select-btn"
        />
      </div>

      <Divider />

      <!-- Profile settings -->
      <Button
        text
        class="account-menu__action"
        icon="pi pi-user"
        :label="t('account.profileSettings')"
        @click="goToProfile"
      />

      <Divider />

      <!-- Logout -->
      <Button
        text
        severity="danger"
        class="account-menu__action account-menu__action--danger"
        icon="pi pi-sign-out"
        :label="t('account.logout')"
        :loading="isLoggingOut"
        @click="handleLogout"
      />
    </div>
  </Popover>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'

const emit = defineEmits<{
  show: []
  hide: []
}>()
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import Divider from 'primevue/divider'
import { useUserStore } from '@/stores/user'
import { useThemeStore } from '@/stores/theme'
import { localeManager } from '@/application/locale'
import { getI18nLocale } from '@/plugins/i18n'
import type { AvailableLocales } from '@/plugins/i18n'
import { authApi } from '@/api/auth'

const { t } = useI18n()
const router = useRouter()
const userStore = useUserStore()
const themeStore = useThemeStore()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const isLoggingOut = ref(false)

// ─── Theme ────────────────────────────────────────────────────────────────────
// computed с get/set — v-model реактивен и всегда синхронен с themeStore
const currentTheme = computed<'light' | 'dark'>({
  get: () => themeStore.theme,
  set: (value) => themeStore.setTheme(value),
})

const themeOptions = computed(() => [
  { label: t('account.themeLight'), value: 'light' },
  { label: t('account.themeDark'), value: 'dark' },
])

// ─── Locale ───────────────────────────────────────────────────────────────────
// computed с get/set — v-model реактивен без ручной синхронизации
const currentLocale = computed<AvailableLocales>({
  get: () => getI18nLocale(),
  set: (value) => {
    localeManager.changeLocale(value)
  },
})

const localeOptions = [
  { label: 'RU', value: 'ru' },
  { label: 'EN', value: 'en' },
]

// ─── User info ────────────────────────────────────────────────────────────────
const initials = computed(() => {
  const name = userStore.getUserName
  if (!name) return '?'
  return name
    .split(' ')
    .slice(0, 2)
    .map((n) => n.charAt(0).toUpperCase())
    .join('')
})

const roleLabel = computed(() => {
  const role = userStore.getUserRole
  if (!role) return ''
  return t(`roles.${role}`, role)
})

// ─── Actions ──────────────────────────────────────────────────────────────────
function goToProfile() {
  popoverRef.value?.hide()
  void router.push('/profile')
}

async function handleLogout() {
  isLoggingOut.value = true
  try {
    await authApi.logout()
  } finally {
    userStore.clearAuthenticatedUserState()
    isLoggingOut.value = false
    popoverRef.value?.hide()
    void router.push('/login')
  }
}

// ─── Public API (for trigger components) ──────────────────────────────────────
function toggle(event: Event) {
  popoverRef.value?.toggle(event)
}

function hide() {
  popoverRef.value?.hide()
}

function show(event: Event) {
  popoverRef.value?.show(event)
}

defineExpose({ toggle, hide, show })
</script>

<style lang="scss" scoped>
.account-menu {
  min-width: 240px;
  padding: $space-1 0;
}

.account-menu__identity {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-3 $space-3;
}

.account-menu__avatar {
  width: 40px;
  height: 40px;
  border-radius: $radius-xl;
  background-color: rgba(23, 39, 71, 0.1);
  flex-shrink: 0;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  // Dark mode: slightly different placeholder bg
  :global(.app-dark) & {
    background-color: rgba(255, 255, 255, 0.15);
  }
}

.account-menu__avatar-initials {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: var(--p-primary-color);
  line-height: 1;
}

.account-menu__identity-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.account-menu__name {
  font-size: 14px;
  font-weight: $font-weight-medium;
  color: var(--p-text-color);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-menu__role {
  font-size: 12px;
  color: var(--p-text-muted-color);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-menu__section {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-2 $space-3;
}

.account-menu__section-label {
  font-size: 12px;
  font-weight: $font-weight-medium;
  color: var(--p-text-muted-color);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.account-menu__select-btn {
  width: 100%;

  :deep(.p-selectbutton) {
    width: 100%;
  }

  :deep(.p-togglebutton) {
    flex: 1;
    font-size: 13px;
  }
}

.account-menu__action {
  width: 100%;
  justify-content: flex-start;
  padding: $space-2 $space-3;
  font-size: 14px;
  border-radius: 0;

  :deep(.p-button-label) {
    text-align: left;
  }
}

.account-menu__action--danger {
  :deep(.p-button-label) {
    color: var(--p-red-500);
  }
}
</style>
