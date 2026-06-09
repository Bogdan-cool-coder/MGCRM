<template>
  <div class="layout container-fluid d-flex flex-column p-0">
    <Toolbox v-if="showLayout" />
    <div class="layout-body d-flex flex-grow-1" v-if="showLayout">
      <main class="main flex-grow-1 d-flex flex-column">
        <router-view />
      </main>
    </div>
    <router-view v-else />
    <ReportGenerationModal v-if="showLayout" />
    <WidgetGenerationModal v-if="showLayout" />
    <DocumentGenerationModal v-if="showLayout && documentsEnabled" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import Toolbox from '@/components/Toolbox'
import {
  ReportGenerationModal,
  WidgetGenerationModal,
  DocumentGenerationModal,
} from '@/components/chat'
import { canUseDocuments } from '@/shared/auth/capabilities'

const route = useRoute()
const showLayout = computed(() => route.name !== 'Login')
// When the Documents feature is OFF the document-generation modal is never
// mounted, so it cannot be popped open by any stray store call.
const documentsEnabled = canUseDocuments()
</script>

<style lang="scss" scoped>
.layout {
  width: 100%;
  height: 100%;
}
.layout-body {
  min-height: 0;
  overflow: visible;
}

.main {
  width: 100%;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}

@media (min-width: 1800px) {
  .main {
    max-width: 1680px;
    margin: 0 auto;
  }
}
</style>
