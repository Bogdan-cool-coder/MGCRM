import { computed, type Ref } from 'vue'

export type EmbedProvider = 'youtube' | 'vimeo' | 'loom' | null

export interface VideoEmbed {
  src: string
  provider: EmbedProvider
}

function extractYoutubeId(url: string): string | null {
  const m1 = url.match(/youtube\.com\/watch\?v=([\w-]+)/)
  if (m1?.[1]) return m1[1]
  const m2 = url.match(/youtu\.be\/([\w-]+)/)
  if (m2?.[1]) return m2[1]
  return null
}

function extractVimeoId(url: string): string | null {
  const m = url.match(/vimeo\.com\/(\d+)/)
  return m?.[1] ?? null
}

function extractLoomId(url: string): string | null {
  const m = url.match(/loom\.com\/share\/([\w-]+)/)
  return m?.[1] ?? null
}

export function useVideoEmbed(videoUrl: Ref<string | null>) {
  const embed = computed<VideoEmbed | null>(() => {
    const url = videoUrl.value
    if (!url) return null

    const ytId = extractYoutubeId(url)
    if (ytId) {
      return { src: `https://www.youtube.com/embed/${ytId}`, provider: 'youtube' }
    }

    const vimeoId = extractVimeoId(url)
    if (vimeoId) {
      return { src: `https://player.vimeo.com/video/${vimeoId}`, provider: 'vimeo' }
    }

    const loomId = extractLoomId(url)
    if (loomId) {
      return { src: `https://www.loom.com/embed/${loomId}`, provider: 'loom' }
    }

    return null
  })

  return { embed }
}
