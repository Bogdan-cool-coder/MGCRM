<template>
  <!--
    Mood widget (ManagerCabinet-v2 §2.7): inline-SVG face whose expression + word
    reflect the score band, plus a phrase that rotates every 4.2s. Inline SVG is
    sanctioned by the spec for this decorative primitive; all colours are repo
    tokens via the `moodColor` computed.
  -->
  <div class="mood-head">
    <svg
      width="54"
      height="54"
      viewBox="0 0 52 52"
      class="mood-head__face"
      aria-hidden="true"
    >
      <circle
        cx="26"
        cy="26"
        r="23"
        class="mood-head__face-bg"
        :stroke="moodColor"
        stroke-width="2"
      />
      <circle cx="19" cy="22" r="2.4" :fill="moodColor" />
      <circle cx="33" cy="22" r="2.4" :fill="moodColor" />
      <path
        :d="mouthPath"
        fill="none"
        :stroke="moodColor"
        stroke-width="2.6"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </svg>

    <div class="mood-head__text">
      <div class="mood-head__word" :style="{ color: moodColor }">{{ moodWord }}</div>
      <button
        type="button"
        class="mood-head__phrase"
        :title="t('managerCabinet.mood.nextPhrase')"
        @click="nextPhrase"
      >
        {{ currentPhrase }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

const PHRASE_INTERVAL_MS = 4200

type Mood = 'happy' | 'ok' | 'low'

const props = defineProps<{
  score: number
}>()

const { t, tm, rt } = useI18n()

const mood = computed<Mood>(() => {
  if (props.score >= 100) return 'happy'
  if (props.score >= 80) return 'ok'
  return 'low'
})

const moodColor = computed<string>(() => {
  switch (mood.value) {
    case 'happy':
      return 'var(--p-green-500)'
    case 'ok':
      // Accent — self-lightens in dark, stays readable on navy.
      return 'var(--p-primary-color)'
    default:
      return 'var(--p-orange-500)'
  }
})

const moodWord = computed<string>(() => t(`managerCabinet.mood.word_${mood.value}`))

const mouthPath = computed<string>(() => {
  switch (mood.value) {
    case 'happy':
      return 'M17 29 Q26 39 35 29'
    case 'low':
      return 'M17 34 Q26 25 35 34'
    default:
      return 'M18 31 L34 31'
  }
})

// ─── Rotating phrases (tm → message-array per mood) ──────────────────────────
const phrases = computed<string[]>(() => {
  const raw = tm(`managerCabinet.mood.${mood.value}`) as unknown[]
  if (!Array.isArray(raw)) return []
  return raw.map((m) => rt(m as never))
})

const phraseIndex = ref<number>(0)

const currentPhrase = computed<string>(() => phrases.value[phraseIndex.value] ?? '')

const nextPhrase = (): void => {
  const len = phrases.value.length
  if (len === 0) return
  phraseIndex.value = (phraseIndex.value + 1) % len
}

// Reset to the first phrase whenever the mood changes (score/user/month switch).
watch(mood, () => {
  phraseIndex.value = 0
})

let timer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  timer = setInterval(nextPhrase, PHRASE_INTERVAL_MS)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<style lang="scss" scoped>
.mood-head {
  display: flex;
  align-items: center;
  gap: $space-3;
}

.mood-head__face {
  flex-shrink: 0;
}

.mood-head__face-bg {
  // Tinted disc behind the face: primary-100 in light, primary-900 in dark.
  fill: var(--p-primary-100);

  .app-dark & {
    fill: var(--p-primary-900);
  }
}

.mood-head__text {
  min-width: 0;
}

.mood-head__word {
  font-size: $font-size-md;
  font-weight: $font-weight-bold;
  line-height: $line-height-tight;
}

.mood-head__phrase {
  display: block;
  margin-top: $space-1;
  padding: 0;
  border: none;
  background: transparent;
  max-width: 176px;
  text-align: left;
  font-family: $font-family-sans;
  font-size: $font-size-xs;
  color: $surface-700;
  cursor: pointer;

  .app-dark & {
    color: var(--p-surface-700);
  }
}
</style>
