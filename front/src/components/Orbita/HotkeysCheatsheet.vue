<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="t('hotkeys.title')"
    :style="{ width: '480px', maxWidth: 'calc(100vw - 2rem)' }"
    class="hotkeys-cheatsheet"
  >
    <div class="hotkeys-cheatsheet__section">
      <h4 class="hotkeys-cheatsheet__section-title">{{ t('hotkeys.globalNav') }}</h4>

      <table class="hotkeys-cheatsheet__table" role="presentation">
        <tbody>
          <tr v-for="entry in navEntries" :key="entry.keys">
            <td class="hotkeys-cheatsheet__keys">
              <template v-for="(part, idx) in entry.keys.split(' ')" :key="idx">
                <kbd v-if="part !== '→'" class="hotkeys-cheatsheet__kbd">{{ part }}</kbd>
                <span v-else class="hotkeys-cheatsheet__arrow" aria-hidden="true">→</span>
              </template>
            </td>
            <td class="hotkeys-cheatsheet__desc">{{ t(entry.descKey) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="hotkeys-cheatsheet__section">
      <h4 class="hotkeys-cheatsheet__section-title">{{ t('hotkeys.other', 'Другое') }}</h4>
      <table class="hotkeys-cheatsheet__table" role="presentation">
        <tbody>
          <tr v-for="entry in otherEntries" :key="entry.keys">
            <td class="hotkeys-cheatsheet__keys">
              <template v-for="(part, idx) in entry.keys.split(' ')" :key="idx">
                <kbd v-if="part !== '→'" class="hotkeys-cheatsheet__kbd">{{ part }}</kbd>
                <span v-else class="hotkeys-cheatsheet__arrow" aria-hidden="true">→</span>
              </template>
            </td>
            <td class="hotkeys-cheatsheet__desc">{{ t(entry.descKey) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import { cheatsheetOpen, closeCheatsheet, NAV_HOTKEY_ENTRIES } from './composables/useNavHotkeys'

const { t } = useI18n()

const visible = computed({
  get: () => cheatsheetOpen.value,
  set: (v) => { if (!v) closeCheatsheet() },
})

const navEntries = computed(() =>
  NAV_HOTKEY_ENTRIES.filter((e) => e.route),
)
const otherEntries = computed(() =>
  NAV_HOTKEY_ENTRIES.filter((e) => !e.route),
)
</script>

<style lang="scss" scoped>
.hotkeys-cheatsheet {
  &__section {
    & + & {
      margin-top: $space-6;
    }
  }

  &__section-title {
    font-size: 12px;
    font-weight: $font-weight-semibold;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: $surface-400;
    margin-bottom: $space-3;
  }

  &__table {
    width: 100%;
    border-collapse: collapse;

    td {
      padding: $space-2 0;
      vertical-align: middle;
    }
  }

  &__keys {
    width: 140px;
    display: flex;
    align-items: center;
    gap: $space-1;
    flex-wrap: wrap;
  }

  &__kbd {
    display: inline-block;
    padding: 2px 6px;
    border: 1px solid $surface-300;
    border-radius: $radius-sm;
    background: $surface-50;
    font-family: ui-monospace, 'SF Mono', 'Cascadia Code', 'Fira Code', monospace;
    font-size: 12px;
    color: $surface-700;
    line-height: 1.5;
    box-shadow: 0 1px 0 $surface-300;
  }

  &__arrow {
    color: $surface-400;
    font-size: 12px;
  }

  &__desc {
    font-size: 14px;
    color: var(--p-text-color);
    padding-left: $space-3;
  }
}

:global(.app-dark) .hotkeys-cheatsheet__kbd {
  border-color: $surface-600;
  background: $surface-800;
  color: $surface-200;
  box-shadow: 0 1px 0 $surface-600;
}
</style>
