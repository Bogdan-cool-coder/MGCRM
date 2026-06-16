<template>
  <div class="user-profile-btn">
    <button
      v-tooltip="tooltipOptions(label)"
      class="user-profile-btn__trigger"
      :aria-label="label"
      @click="handleClick"
      @keydown.esc.stop="accountMenuRef?.hide()"
    >
      <img
        v-if="userStore.getAvatarPath"
        class="user-profile-btn__avatar"
        :src="userStore.getAvatarPath"
        :alt="userStore.getUserName"
      />
      <span v-else class="user-profile-btn__initials">{{ initials }}</span>
    </button>

    <AccountMenu ref="accountMenuRef" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tooltip from 'primevue/tooltip'
import AccountMenu from '@/components/AppShell/AccountMenu.vue'
import { useUserStore } from '@/stores/user'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOverlayControl } from './types'

interface Props {
  tooltipOptions: (value: string) => OrbitaTooltipOptions
}

defineProps<Props>()

const { t } = useI18n()
const vTooltip = Tooltip
const userStore = useUserStore()
const accountMenuRef = ref<InstanceType<typeof AccountMenu> | null>(null)

const label = computed(() => userStore.getUserName || t('orbita.profile'))

const initials = computed(() => {
  const name = userStore.getUserName
  if (!name) return '?'
  return name
    .split(' ')
    .slice(0, 2)
    .map((n: string) => n.charAt(0).toUpperCase())
    .join('')
})

function handleClick(event: MouseEvent) {
  accountMenuRef.value?.toggle(event)
}

// ─── OrbitaOverlayControl interface ──────────────────────────────────────────
// Exposed so parent (Orbita.vue) can wire useOrbitaOverlays for mutual exclusion.
function syncPopover(open: boolean, event?: MouseEvent | null) {
  if (!accountMenuRef.value) return
  if (open && event) {
    accountMenuRef.value.show(event)
  } else if (!open) {
    accountMenuRef.value.hide()
  }
}

function realign() {
  // AccountMenu (Popover) re-aligns on its own; no-op fallback
}

defineExpose<OrbitaOverlayControl>({ syncPopover, realign })
</script>

<style lang="scss" scoped>
.user-profile-btn {
  position: relative;
  display: inline-flex;
}

.user-profile-btn__trigger {
  width: 2.75rem;
  height: 2.75rem;
  border: 1px solid transparent;
  border-radius: $radius-md;
  background: transparent;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition:
    background-color $transition-fast,
    border-color $transition-fast;

  &:hover {
    background: $surface-100;
    border-color: rgba($surface-900, 0.08);
  }

  &:focus-visible {
    outline: 2px solid $primary;
    outline-offset: 2px;
  }
}

.user-profile-btn__avatar {
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  object-fit: cover;
}

.user-profile-btn__initials {
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  background: rgba($primary, 0.15);
  color: $primary;
  font-size: 11px;
  font-weight: $font-weight-bold;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}
</style>
