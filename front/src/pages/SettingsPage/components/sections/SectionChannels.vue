<template>
  <div class="section-channels">
    <div class="profile-section">
      <h3 class="profile-section__title">{{ t('settings.channels.sectionTitle') }}</h3>

      <div class="channel-list">
        <!-- Telegram -->
        <div class="channel-row">
          <div class="channel-row__icon">
            <i class="pi pi-telegram" />
          </div>
          <div class="channel-row__body">
            <p class="channel-row__name">{{ t('settings.channels.telegram.name') }}</p>
            <p class="channel-row__status">
              <template v-if="telegramLinked">
                <Tag severity="success" :value="t('settings.channels.telegram.connected')" />
                <span v-if="telegramUsername" class="channel-row__username">
                  @{{ telegramUsername }}
                </span>
              </template>
              <template v-else>
                {{ t('settings.channels.telegram.notConnected') }}
              </template>
            </p>
          </div>
          <div class="channel-row__action">
            <Button
              v-if="!telegramLinked"
              icon="pi pi-link"
              :label="t('settings.channels.telegram.connectBtn')"
              severity="primary"
              outlined
              :loading="telegramLinking"
              @click="onLinkTelegram"
            />
            <Button
              v-else
              icon="pi pi-unlink"
              :label="t('settings.channels.telegram.disconnectBtn')"
              severity="danger"
              outlined
              :loading="telegramUnlinking"
              @click="onUnlinkTelegram"
            />
          </div>
        </div>

        <!-- Email (coming soon) -->
        <div class="channel-row channel-row--disabled">
          <div class="channel-row__icon">
            <i class="pi pi-envelope" />
          </div>
          <div class="channel-row__body">
            <p class="channel-row__name">{{ t('settings.channels.email.name') }}</p>
            <p class="channel-row__status">—</p>
          </div>
          <div class="channel-row__action">
            <Tag :value="t('common.coming_soon')" severity="secondary" />
          </div>
        </div>

        <!-- WhatsApp (coming soon) -->
        <div class="channel-row channel-row--disabled">
          <div class="channel-row__icon">
            <i class="pi pi-whatsapp" />
          </div>
          <div class="channel-row__body">
            <p class="channel-row__name">{{ t('settings.channels.whatsapp.name') }}</p>
            <p class="channel-row__status">—</p>
          </div>
          <div class="channel-row__action">
            <Tag :value="t('common.coming_soon')" severity="secondary" />
          </div>
        </div>
      </div>
    </div>
  </div>

</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import type { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'

const { t } = useI18n()
const confirm = useConfirm()

type ProfilePageReturn = ReturnType<typeof useProfilePage>

const props = defineProps<{
  telegramLinked: boolean
  telegramUsername: string | null
  telegramLinking: boolean
  telegramUnlinking: boolean
  linkTelegram: ProfilePageReturn['linkTelegram']
  unlinkTelegram: ProfilePageReturn['unlinkTelegram']
}>()

function onLinkTelegram() {
  void props.linkTelegram()
}

function onUnlinkTelegram() {
  confirm.require({
    message: t('settings.channels.telegram.disconnectConfirm'),
    header: t('settings.channels.telegram.disconnectBtn'),
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    acceptLabel: t('settings.channels.telegram.disconnectBtn'),
    rejectLabel: t('common.cancel'),
    accept: () => {
      void props.unlinkTelegram()
    },
  })
}
</script>

<style lang="scss" scoped>
.section-channels {
  padding: $space-6;
}

.profile-section {
  margin-bottom: $space-6;
}

.profile-section__title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-4;
  padding-bottom: $space-2;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    color: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

.channel-list {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.channel-row {
  display: flex;
  align-items: center;
  gap: $space-4;
  padding: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    // BUG-2: surface-800 in dark = #F1F2F3; use surface-100
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }

  &--disabled {
    opacity: 0.55;
    pointer-events: none;
  }

  &__icon {
    width: 40px;
    height: 40px;
    border-radius: $radius-md;
    background: var(--p-primary-50);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: $font-size-xl;
    color: var(--p-primary-600);

    .app-dark & {
      background: rgba(23, 39, 71, 0.3);
      color: var(--p-primary-300);
    }
  }

  &__body {
    flex: 1;
    min-width: 0;
  }

  &__name {
    font-size: $font-size-base;
    font-weight: $font-weight-semibold;
    color: $surface-900;
    margin: 0 0 $space-1;

    .app-dark & {
      color: var(--p-surface-50);
    }
  }

  &__status {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
    display: flex;
    align-items: center;
    gap: $space-2;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }

  &__username {
    font-size: $font-size-sm;
    color: var(--p-primary-color);
  }

  &__action {
    flex-shrink: 0;
  }
}
</style>
