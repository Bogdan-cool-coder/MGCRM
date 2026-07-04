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
              <span class="hotkeys-cheatsheet__keys-wrap">
                <template v-for="(part, idx) in entry.keys.split(' ')" :key="idx">
                  <kbd v-if="part !== '→'" class="hotkeys-cheatsheet__kbd">{{ part }}</kbd>
                  <span v-else class="hotkeys-cheatsheet__arrow" aria-hidden="true">→</span>
                </template>
              </span>
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
              <span class="hotkeys-cheatsheet__keys-wrap">
                <template v-for="(part, idx) in entry.keys.split(' ')" :key="idx">
                  <kbd v-if="part !== '→'" class="hotkeys-cheatsheet__kbd">{{ part }}</kbd>
                  <span v-else class="hotkeys-cheatsheet__arrow" aria-hidden="true">→</span>
                </template>
              </span>
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
    font-size: $font-size-xs; // 12px
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

  // Keep the cell as a real table-cell (width:140px, vertical-align:middle from td)
  // — the flex layout lives on an inner wrapper so wrapping kbd combos no longer
  // knock the description column off centre (audit L1).
  &__keys {
    width: 140px;
  }

  &__keys-wrap {
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
    font-family: $font-family-mono;
    font-size: $font-size-xs; // 12px
    color: $surface-700;
    line-height: 1.5;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: 0 1px 0 $surface-300; // custom divider shadow, uses surface tokens
  }

  &__arrow {
    color: $surface-400;
    font-size: $font-size-xs; // 12px
  }

  &__desc {
    font-size: $font-size-sm; // 14px
    color: var(--p-text-color);
    padding-left: $space-3;
  }
}

// Dark mode: __kbd base is theme-reactive ($surface-300 border / $surface-50 bg /
// $surface-700 text — all read correctly in navy dark). Removed dead
// `:global(.app-dark) .hotkeys-cheatsheet__kbd` rule (scoped-compiler drops the class,
// leaving a bare `.app-dark{}` rule that painted the whole dark root).
</style>
