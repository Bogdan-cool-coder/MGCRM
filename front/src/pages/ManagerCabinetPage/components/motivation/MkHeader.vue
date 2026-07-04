<template>
  <!--
    Compact МК header row (ManagerCabinet-v2 §3.1): avatar + name · pipeline +
    status pill. No card chrome — the period lives in the toolbar month dropdown.
  -->
  <div class="mk-header">
    <EntityAvatar :name="meta.user.full_name" :pixel-size="30" />
    <span class="mk-header__name">{{ meta.user.full_name }}</span>
    <span v-if="meta.pipeline" class="mk-header__pipeline">· {{ meta.pipeline.name }}</span>
    <Tag :severity="statusSeverity" :value="statusLabel" class="mk-header__status" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import type { MkCardMeta } from '@/entities/motivation'

const props = defineProps<{
  meta: MkCardMeta
}>()

const { t } = useI18n()

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

const statusLabel = computed<string>(() => t(`motivation.card.status_${props.meta.status}`))
</script>

<style lang="scss" scoped>
.mk-header {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.mk-header__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $surface-900;
}

.mk-header__pipeline {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-header__status {
  margin-left: $space-1;
}
</style>
