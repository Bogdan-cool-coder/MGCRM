<template>
  <div class="lesson-view-video">
    <!-- Embed -->
    <div v-if="embed" class="lesson-view-video__player-wrap mb-4">
      <iframe
        :src="embed.src"
        class="lesson-view-video__iframe"
        frameborder="0"
        allowfullscreen
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
      />
    </div>

    <!-- Unsupported -->
    <Message v-else severity="warn" :closable="false" class="mb-4">
      {{ t('onboarding.coursePage.unsupportedVideo') }}
    </Message>

    <LessonCompleteButton
      :completed="completed"
      :loading="completing"
      :label="t('onboarding.coursePage.markViewed')"
      @complete="$emit('complete')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { useVideoEmbed } from '../composables/useVideoEmbed'
import LessonCompleteButton from './LessonCompleteButton.vue'

const props = defineProps<{
  videoUrl: string | null
  completed?: boolean
  completing?: boolean
}>()

defineEmits<{ complete: [] }>()

const { t } = useI18n()
const { embed } = useVideoEmbed(computed(() => props.videoUrl))
</script>

<style lang="scss" scoped>
.lesson-view-video {
  &__player-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border-radius: $radius-lg;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: #000; // video player always black
  }

  &__iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: none;
  }
}
</style>
