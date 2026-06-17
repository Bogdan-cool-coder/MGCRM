import { computed, ref, onMounted, onUnmounted } from 'vue'

export function useBreakpoints() {
  const width = ref(typeof window !== 'undefined' ? window.innerWidth : 1280)

  const onResize = () => {
    width.value = window.innerWidth
  }

  onMounted(() => window.addEventListener('resize', onResize))
  onUnmounted(() => window.removeEventListener('resize', onResize))

  return {
    width,
    isDesktopWide: computed(() => width.value >= 1280),
    isDesktop: computed(() => width.value >= 1024),
    isTablet: computed(() => width.value >= 768 && width.value < 1024),
    isMobile: computed(() => width.value < 768),
  }
}
