<template>
  <div class="org-node-wrap" :style="{ marginLeft: depth > 0 ? `${depth * 32}px` : undefined }">
    <!-- Card -->
    <div
      class="org-node"
      :class="{ 'org-node--deep': depth >= 3 }"
      role="button"
      tabindex="0"
      @click="$emit('select', node)"
      @keydown.enter="$emit('select', node)"
    >
      <div class="org-node__name">{{ node.label }}</div>
      <div v-if="node.data.manager_name" class="org-node__manager">
        <i class="pi pi-user" />
        {{ node.data.manager_name }}
      </div>
      <div class="org-node__members">
        <i class="pi pi-users" />
        {{ node.data.members_count }}
      </div>
    </div>

    <!-- Children (vertical list) -->
    <div v-if="node.children.length > 0" class="org-node__children">
      <OrgChartNode
        v-for="child in node.children"
        :key="child.key"
        :node="child"
        :depth="depth + 1"
        @select="$emit('select', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { DeptTreeNode } from '@/entities/accessControl'

defineProps<{
  node: DeptTreeNode
  depth: number
}>()

defineEmits<{
  (e: 'select', node: DeptTreeNode): void
}>()
</script>

<style scoped lang="scss">
.org-node-wrap {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.org-node {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  padding: $space-3 $space-4;
  background-color: var(--p-surface-card);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  cursor: pointer;
  transition: border-color var(--app-transition-fast), box-shadow var(--app-transition-fast);
  width: 200px;
  min-height: 72px;

  &:hover {
    border-color: var(--p-primary-300);
    box-shadow: $shadow-card-hover;
  }

  &:focus-visible {
    outline: 2px solid var(--p-primary-500);
    outline-offset: 2px;
  }
}

.org-node__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.org-node__manager,
.org-node__members {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-600;

  i {
    font-size: $font-size-xs;
  }
}

.org-node__children {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding-left: $space-8;
  border-left: 2px solid var(--p-surface-200);
  margin-left: $space-4;
}
</style>
