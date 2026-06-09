import { onBeforeUnmount, onMounted, ref } from 'vue'

const MOBILE_BREAKPOINT = 960

export const useResponsiveChatSidebar = () => {
  const isMobileSidebar = ref(false)
  const isSidebarOpen = ref(false)

  const syncSidebarMode = () => {
    const nextIsMobile = window.innerWidth <= MOBILE_BREAKPOINT

    if (nextIsMobile === isMobileSidebar.value) {
      if (!nextIsMobile) {
        isSidebarOpen.value = true
      }
      return
    }

    isMobileSidebar.value = nextIsMobile
    isSidebarOpen.value = !nextIsMobile
  }

  const openSidebar = () => {
    if (!isMobileSidebar.value) return
    isSidebarOpen.value = true
  }

  const closeSidebar = () => {
    if (!isMobileSidebar.value) return
    isSidebarOpen.value = false
  }

  const toggleSidebar = () => {
    if (!isMobileSidebar.value) return
    isSidebarOpen.value = !isSidebarOpen.value
  }

  onMounted(() => {
    syncSidebarMode()
    window.addEventListener('resize', syncSidebarMode)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', syncSidebarMode)
  })

  return {
    isMobileSidebar,
    isSidebarOpen,
    openSidebar,
    closeSidebar,
    toggleSidebar,
  }
}
