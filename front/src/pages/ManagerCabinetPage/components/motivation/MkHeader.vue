<template>
  <div class="mk-header">
    <Avatar
      :label="initials"
      shape="circle"
      size="xlarge"
      class="mk-header__avatar"
    />

    <div class="mk-header__body">
      <div class="mk-header__title-row">
        <span class="mk-header__name">{{ meta.user.full_name }}</span>
        <Tag :severity="statusSeverity" :value="statusLabel" />
      </div>
      <div class="mk-header__meta">{{ roleLine }}</div>
      <div v-if="meta.supervisor" class="mk-header__meta">
        {{ t('motivation.card.supervisor') }}: {{ meta.supervisor.full_name }}
      </div>
    </div>

    <div class="mk-header__aside">
      <span v-if="meta.pipeline" class="mk-header__meta mk-header__meta--right">
        {{ meta.pipeline.name }}
      </span>
      <span class="mk-header__meta mk-header__meta--right">{{ meta.period.label }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Avatar from 'primevue/avatar'
import Tag from 'primevue/tag'
import type { MkCardMeta } from '@/entities/motivation'

const props = defineProps<{
  meta: MkCardMeta
}>()

const { t } = useI18n()

const initials = computed<string>(() => {
  const parts = props.meta.user.full_name.trim().split(/\s+/)
  const first = parts[0]?.[0] ?? ''
  const second = parts[1]?.[0] ?? ''
  return (first + second).toUpperCase() || '?'
})

const statusSeverity = computed(() => {
  switch (props.meta.status) {
    case 'paid':
      return 'success'
    case 'finalized':
      return 'info'
    default:
      return 'secondary'
  }
})

const statusLabel = computed<string>(() =>
  t(`motivation.card.status_${props.meta.status}`),
)

const roleLine = computed<string>(() => {
  const pipeline = props.meta.pipeline?.name
  const role = t('roles.manager')
  return pipeline ? `${role} · ${pipeline}` : role
})
</script>

<style lang="scss" scoped>
.mk-header {
  display: flex;
  align-items: flex-start;
  gap: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-5 $space-6;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.mk-header__avatar {
  flex-shrink: 0;
  background: var(--p-primary-100);
  color: $primary-900;
  font-weight: $font-weight-semibold;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-100);
  }
}

.mk-header__body {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  min-width: 0;
  flex: 1;
}

.mk-header__title-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.mk-header__name {
  font-size: var(--app-font-size-lg, 1.125rem);
  font-weight: $font-weight-semibold;
  color: $surface-900;
  line-height: $line-height-tight;
}

.mk-header__meta {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-header__aside {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: $space-1;
  flex-shrink: 0;
}

.mk-header__meta--right {
  text-align: right;
}

@media (max-width: 575px) {
  .mk-header {
    flex-wrap: wrap;
  }
  .mk-header__aside {
    align-items: flex-start;
    width: 100%;
  }
  .mk-header__meta--right {
    text-align: left;
  }
}
</style>
