<template>
  <div class="dept-tree">
    <Tree
      v-if="nodes.length > 0"
      :value="nodes"
      selection-mode="single"
      :loading="loading"
      class="dept-tree__tree"
      @node-select="onSelect"
    >
      <template #default="{ node }">
        <div class="dept-tree__node">
          <span class="dept-tree__node-label">{{ node.label }}</span>
          <span class="dept-tree__node-meta">
            <i class="pi pi-users" />
            {{ node.data.members_count }}
          </span>
          <span class="dept-tree__node-actions">
            <Button
              icon="pi pi-pencil"
              text
              severity="secondary"
              size="small"
              :title="t('common.edit')"
              @click.stop="$emit('edit', node.data)"
            />
            <Button
              icon="pi pi-trash"
              text
              severity="danger"
              size="small"
              :title="t('common.delete')"
              @click.stop="$emit('delete', node.data)"
            />
          </span>
        </div>
      </template>
    </Tree>

    <!-- Loading skeleton -->
    <div v-else-if="loading" class="dept-tree__skeleton">
      <Skeleton height="20px" class="dept-tree__skeleton-row" />
      <Skeleton height="20px" class="dept-tree__skeleton-row dept-tree__skeleton-row--indent1" />
      <Skeleton height="20px" class="dept-tree__skeleton-row dept-tree__skeleton-row--indent2" />
    </div>

    <!-- Empty state -->
    <div v-else class="dept-tree__empty">
      <i class="pi pi-building dept-tree__empty-icon" />
      <span class="dept-tree__empty-title">{{ t('accessControl.departments.empty') }}</span>
      <span class="dept-tree__empty-hint">{{ t('accessControl.departments.emptyHint') }}</span>
      <Button
        icon="pi pi-plus"
        :label="t('accessControl.departments.addDepartment')"
        size="small"
        outlined
        @click="$emit('addDept')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tree from 'primevue/tree'
import type { TreeNode } from 'primevue/treenode'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import type { DepartmentDto, DeptTreeNode } from '@/entities/accessControl'

defineProps<{
  nodes: DeptTreeNode[]
  loading: boolean
}>()

const emit = defineEmits<{
  (e: 'select', dept: DepartmentDto): void
  (e: 'edit', dept: DepartmentDto): void
  (e: 'delete', dept: DepartmentDto): void
  (e: 'addDept'): void
}>()

const { t } = useI18n()

function onSelect(node: TreeNode) {
  const deptNode = node as DeptTreeNode
  if (deptNode.data) {
    emit('select', deptNode.data)
  }
}
</script>

<style scoped lang="scss">
.dept-tree {
  height: 100%;
  min-height: 200px;
}

.dept-tree__tree {
  width: 100%;
}

.dept-tree__node {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-1 0;
}

.dept-tree__node-label {
  flex: 1;
  font-size: $font-size-sm;
  color: var(--p-text-color);
}

.dept-tree__node-meta {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);

  i {
    font-size: $font-size-xs;
  }
}

.dept-tree__node-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  opacity: 0;
  transition: opacity var(--app-transition-fast);

  .dept-tree__node:hover & {
    opacity: 1;
  }
}

// ─── Skeleton ────────────────────────────────────────────────────────────────
.dept-tree__skeleton {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-3;
}

.dept-tree__skeleton-row {
  width: 80%;
}

.dept-tree__skeleton-row--indent1 {
  margin-left: $space-4;
  width: 60%;
}

.dept-tree__skeleton-row--indent2 {
  margin-left: $space-8;
  width: 50%;
}

// ─── Empty ───────────────────────────────────────────────────────────────────
.dept-tree__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  padding: $space-8;
  text-align: center;
}

.dept-tree__empty-icon {
  font-size: $font-size-3xl;
  opacity: 0.3;
  color: var(--p-text-muted-color);
}

.dept-tree__empty-title {
  font-size: $font-size-base;
  font-weight: $font-weight-medium;
  color: var(--p-text-color);
}

.dept-tree__empty-hint {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  max-width: 260px;
}
</style>
