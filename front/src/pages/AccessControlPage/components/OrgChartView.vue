<template>
  <div class="org-chart">
    <div class="org-chart__roots">
      <OrgChartNode
        v-for="node in nodes"
        :key="node.key"
        :node="node"
        :edit-mode="editMode"
        :load-members="loadMembers"
        @edit="$emit('edit', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { DeptTreeNode, DepartmentMemberDto } from '@/entities/accessControl'
import OrgChartNode from './OrgChartNode.vue'

withDefaults(
  defineProps<{
    nodes: DeptTreeNode[]
    editMode?: boolean
    /** Lazy members loader passed down to each node for on-expand fetch */
    loadMembers: (deptId: number) => Promise<DepartmentMemberDto[]>
  }>(),
  { editMode: false },
)

defineEmits<{
  (e: 'edit', node: DeptTreeNode): void
}>()
</script>

<style scoped lang="scss">
.org-chart {
  padding: $space-8 $space-4;
  overflow-x: auto;
}

// Top-down chart: root departments laid out in a centred horizontal row.
.org-chart__roots {
  display: flex;
  gap: $space-8;
  justify-content: center;
  align-items: flex-start;
  min-width: min-content;
}
</style>
