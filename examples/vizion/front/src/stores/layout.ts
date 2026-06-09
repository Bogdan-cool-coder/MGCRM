import { defineStore } from 'pinia'
import type { ToolboxPlacement, ToolboxPosition, ToolboxPositions } from '@/components/Toolbox/types'

export const useLayoutStore = defineStore('layout', {
  state: () => ({
    toolboxPlacement: 'top' as ToolboxPlacement,
    toolboxCollapsed: false,
    toolboxPositions: {} as ToolboxPositions,
  }),

  getters: {
    getToolboxPlacement(): ToolboxPlacement {
      return this.toolboxPlacement
    },
    getToolboxCollapsed(): boolean {
      return this.toolboxCollapsed
    },
    getToolboxPosition(): ToolboxPosition | null {
      return this.toolboxPositions[this.toolboxPlacement] ?? null
    },
    getToolboxPositions(): ToolboxPositions {
      return this.toolboxPositions
    },
  },

  actions: {
    setToolboxPlacement(value: ToolboxPlacement) {
      this.toolboxPlacement = value
    },
    setToolboxCollapsed(value: boolean) {
      this.toolboxCollapsed = value
    },
    setToolboxPosition(value: ToolboxPosition | null) {
      this.toolboxPositions[this.toolboxPlacement] = value
    },
    setToolboxPositionByPlacement(placement: ToolboxPlacement, value: ToolboxPosition | null) {
      this.toolboxPositions[placement] = value
    },
    toggleToolboxCollapsed() {
      this.toolboxCollapsed = !this.toolboxCollapsed
    },
  },

  persist: {
    paths: ['toolboxPlacement', 'toolboxCollapsed', 'toolboxPositions'],
  },
})
