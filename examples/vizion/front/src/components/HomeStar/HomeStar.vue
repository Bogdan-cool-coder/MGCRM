<template>
  <div class="home-star" :class="{ 'home-star--compact': compact }">
    <Button
      v-tooltip="resolvedTooltipOptions"
      class="home-star__btn"
      :class="{ 'home-star__btn--compact': compact, 'is-home': isCurrentHome }"
      :icon="isCurrentHome ? 'pi pi-star-fill' : 'pi pi-star'"
      :aria-label="tooltipLabel"
      :aria-pressed="isCurrentHome"
      text
      :loading="isSaving"
      @click="handleClick"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Tooltip from 'primevue/tooltip'
import { useApplicationServices } from '@/application'
import { useUserStore } from '@/stores/user'
import { notificationCenter } from '@/application'
import { getApiErrorMessage } from '@/utils/errors'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const vTooltip = Tooltip

interface TooltipOptions {
  value: string
  showDelay?: number
  hideDelay?: number
}

interface Props {
  compact?: boolean
  tooltipOptions?: TooltipOptions | null
}

const props = withDefaults(defineProps<Props>(), {
  compact: false,
  tooltipOptions: null,
})

const { t } = useLocalI18n({ en, ru })
const route = useRoute()
const userStore = useUserStore()
const { userSessionService } = useApplicationServices()

const isSaving = ref(false)

// We compare against `route.path` (not `fullPath`) so that `/reports?x=1` and
// `/reports` are treated as the same home page — query params do not define a
// distinct home.
const isCurrentHome = computed(() => userStore.getHomePath === route.path)

const tooltipLabel = computed(() =>
  isCurrentHome.value ? t('homeStar.isHome') : t('homeStar.makeHome'),
)

const resolvedTooltipOptions = computed(
  () => props.tooltipOptions ?? { value: tooltipLabel.value },
)

const handleClick = async () => {
  // Clicking the star always sets the current page as the (single) home page.
  // When it is already the home page this is a no-op — skip the request.
  if (isSaving.value || isCurrentHome.value) {
    return
  }

  isSaving.value = true

  try {
    await userSessionService.setHomePath(route.path)
    notificationCenter.success(t('homeStar.saved'))
  } catch (error: unknown) {
    notificationCenter.error(getApiErrorMessage(error, t('homeStar.saveError')))
  } finally {
    isSaving.value = false
  }
}
</script>

<style lang="scss" scoped>
.home-star {
  display: inline-flex;
}

.home-star__btn {
  &.is-home :deep(.pi) {
    color: var(--p-yellow-400, #facc15);
  }
}
</style>
