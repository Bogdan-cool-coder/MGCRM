<template>
  <!--
    standalone=true: renders tree content without InfoPanel wrapper (full Holding tab §10).
    standalone=false (default): wraps in InfoPanel (Overview panel §4).
  -->
  <InfoPanel
    v-if="!standalone"
    :title="t('crm.company.sections.holding')"
    icon="pi-sitemap"
    panel-key="company-holding"
    :count="childrenCount || undefined"
    :default-collapsed="true"
  >
    <!-- ── Tree content ── -->
    <div v-if="loading" class="holding-tree__skeleton">
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" />
    </div>
    <div v-else-if="!tree" class="holding-tree__empty">
      <i class="pi pi-sitemap holding-tree__empty-icon" />
      <p class="holding-tree__empty-text">{{ t('crm.company.holding.noHolding') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('crm.company.holding.addParent')"
        size="small"
        severity="secondary"
        outlined
        @click="$emit('attachParent')"
      />
    </div>
    <div v-else class="holding-tree__tree">
      <div
        v-for="(ancestor, idx) in tree.ancestors"
        :key="ancestor.id"
        class="holding-tree__node holding-tree__node--ancestor"
        :style="{ paddingLeft: `${idx * 16}px` }"
      >
        <RouterLink :to="`/companies/${ancestor.id}`" class="holding-tree__node-link">
          <i class="pi pi-building holding-tree__node-icon" />
          <span class="holding-tree__node-name">{{ ancestor.name }}</span>
        </RouterLink>
        <Tag
          v-if="ancestor.holding_role"
          :value="holdingRoleLabel(ancestor.holding_role)"
          severity="secondary"
          size="small"
          class="holding-tree__role-tag"
        />
      </div>
      <div
        class="holding-tree__node holding-tree__node--current"
        :style="{ paddingLeft: `${tree.ancestors.length * 16}px` }"
      >
        <i class="pi pi-map-marker holding-tree__you-icon" />
        <span class="holding-tree__you-name">{{ tree.company.name }}</span>
        <Tag
          :value="t('crm.company.holding.youAreHere')"
          severity="info"
          size="small"
          class="holding-tree__you-tag"
        />
      </div>
      <div
        v-for="child in tree.children"
        :key="child.id"
        class="holding-tree__node holding-tree__node--child"
        :style="{ paddingLeft: `${(tree.ancestors.length + 1) * 16}px` }"
      >
        <RouterLink :to="`/companies/${child.id}`" class="holding-tree__node-link">
          <i class="pi pi-building holding-tree__node-icon" />
          <span class="holding-tree__node-name">{{ child.name }}</span>
        </RouterLink>
        <Tag
          v-if="child.holding_role"
          :value="holdingRoleLabel(child.holding_role)"
          severity="secondary"
          size="small"
          class="holding-tree__role-tag"
        />
      </div>
      <div class="holding-tree__actions">
        <Button
          icon="pi pi-times"
          :label="t('crm.company.holding.detach')"
          size="small"
          severity="secondary"
          text
          @click="$emit('detachParent')"
        />
      </div>
    </div>
  </InfoPanel>

  <!-- standalone mode: raw tree content (full Holding tab §10) -->
  <template v-else>
    <div v-if="loading" class="holding-tree__skeleton">
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" />
    </div>
    <div v-else-if="!tree" class="holding-tree__empty">
      <i class="pi pi-sitemap holding-tree__empty-icon" />
      <p class="holding-tree__empty-text">{{ t('crm.company.holding.noHolding') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('crm.company.holding.addParent')"
        size="small"
        severity="secondary"
        outlined
        @click="$emit('attachParent')"
      />
    </div>
    <div v-else class="holding-tree__tree holding-tree__tree--pad">
      <div
        v-for="(ancestor, idx) in tree.ancestors"
        :key="ancestor.id"
        class="holding-tree__node holding-tree__node--ancestor"
        :style="{ paddingLeft: `${idx * 16}px` }"
      >
        <RouterLink :to="`/companies/${ancestor.id}`" class="holding-tree__node-link">
          <i class="pi pi-building holding-tree__node-icon" />
          <span class="holding-tree__node-name">{{ ancestor.name }}</span>
        </RouterLink>
        <Tag
          v-if="ancestor.holding_role"
          :value="holdingRoleLabel(ancestor.holding_role)"
          severity="secondary"
          size="small"
          class="holding-tree__role-tag"
        />
      </div>
      <div
        class="holding-tree__node holding-tree__node--current"
        :style="{ paddingLeft: `${tree.ancestors.length * 16}px` }"
      >
        <i class="pi pi-map-marker holding-tree__you-icon" />
        <span class="holding-tree__you-name">{{ tree.company.name }}</span>
        <Tag
          :value="t('crm.company.holding.youAreHere')"
          severity="info"
          size="small"
          class="holding-tree__you-tag"
        />
      </div>
      <div
        v-for="child in tree.children"
        :key="child.id"
        class="holding-tree__node holding-tree__node--child"
        :style="{ paddingLeft: `${(tree.ancestors.length + 1) * 16}px` }"
      >
        <RouterLink :to="`/companies/${child.id}`" class="holding-tree__node-link">
          <i class="pi pi-building holding-tree__node-icon" />
          <span class="holding-tree__node-name">{{ child.name }}</span>
        </RouterLink>
        <Tag
          v-if="child.holding_role"
          :value="holdingRoleLabel(child.holding_role)"
          severity="secondary"
          size="small"
          class="holding-tree__role-tag"
        />
      </div>
      <div class="holding-tree__actions">
        <Button
          icon="pi pi-times"
          :label="t('crm.company.holding.detach')"
          size="small"
          severity="secondary"
          text
          @click="$emit('detachParent')"
        />
      </div>
    </div>
  </template>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import type { HoldingTreeDto, HoldingRole, HoldingCompanyNode } from '@/entities/crm'

const props = defineProps<{
  tree: HoldingTreeDto | null
  loading: boolean
  /** When true, renders tree content without InfoPanel wrapper (full Holding tab §10). */
  standalone?: boolean
}>()

defineEmits<{
  attachParent: []
  detachParent: []
}>()

const { t } = useI18n()

/** Recursively count all descendant nodes in tree.children */
function countNodes(node: HoldingCompanyNode & { children?: HoldingCompanyNode[] }): number {
  return 1 + ((node as { children?: HoldingCompanyNode[] }).children ?? []).reduce(
    (s: number, c: HoldingCompanyNode) => s + countNodes(c as HoldingCompanyNode & { children?: HoldingCompanyNode[] }),
    0,
  )
}

const childrenCount = computed(() => {
  if (!props.tree) return 0
  return (props.tree.children ?? []).reduce(
    (s, c) => s + countNodes(c as HoldingCompanyNode & { children?: HoldingCompanyNode[] }),
    0,
  )
})

function holdingRoleLabel(role: HoldingRole | null): string {
  if (role === 'parent') return t('crm.company.holding.roleParent')
  if (role === 'child') return t('crm.company.holding.roleChild')
  return role ?? ''
}
</script>

<style lang="scss" scoped>
.holding-tree__skeleton {
  display: flex;
  flex-direction: column;
  padding: 0 $space-4 $space-3;
}

.holding-tree__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-6 $space-4;
  text-align: center;
}

.holding-tree__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

.holding-tree__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.holding-tree__tree {
  display: flex;
  flex-direction: column;

  &--pad {
    padding: $space-4;
    max-width: 600px;
  }
  gap: $space-1;
  padding: 0 0 $space-3;
}

.holding-tree__node {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-radius: $radius-sm;
  transition: background var(--app-transition-fast);

  &--ancestor {
    opacity: 0.8;
  }

  &--current {
    background: rgba(var(--p-primary-color-rgb, 23, 39, 71), 0.05);
    border-left: 3px solid var(--p-primary-color);

    .app-dark & {
      background: var(--p-primary-950, rgba(23, 39, 71, 0.3));
    }
  }

  &--child {
    opacity: 0.85;
  }
}

.holding-tree__node-link {
  display: flex;
  align-items: center;
  gap: $space-1;
  text-decoration: none;
  flex: 1;
  min-width: 0;

  &:hover .holding-tree__node-name {
    text-decoration: underline;
  }
}

.holding-tree__node-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.holding-tree__node-name {
  font-size: $font-size-sm;
  color: var(--p-primary-color);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.holding-tree__role-tag {
  flex-shrink: 0;
}

.holding-tree__you-icon {
  font-size: $font-size-sm; // snap from 13px
  color: var(--p-primary-color);
  flex-shrink: 0;
}

.holding-tree__you-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.holding-tree__you-tag {
  flex-shrink: 0;
}

.holding-tree__actions {
  display: flex;
  justify-content: flex-end;
  padding: $space-2 $space-4 0;
}
</style>
