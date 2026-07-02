<template>
  <div
    class="entity-avatar"
    :class="[sizeClass, { 'entity-avatar--on-brand': onBrand, 'entity-avatar--square': square }]"
    :style="containerStyle"
    :aria-label="displayInitials"
  >
    <span class="entity-avatar__initials" :style="initialsStyle">{{ displayInitials }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

// Brand-invariant palette: deterministic background per entityId.
// Hex values are brand colour constants — not DS tokens — allowed per DS rules comment.
const AVATAR_PALETTE = [
  // stylelint-disable scale-unlimited/declaration-strict-value
  '#3B6CB7',
  '#2E8B57',
  '#D46A26',
  '#7B4EA0',
  '#C0392B',
  '#1A7A7A',
  '#B8860B',
  '#C2185B',
  // stylelint-enable scale-unlimited/declaration-strict-value
]

/** Named-size → px dimensions */
const NAMED_SIZE_PX: Record<'sm' | 'md' | 'lg', number> = {
  sm: 32,
  md: 56,
  lg: 72,
}

const props = withDefaults(
  defineProps<{
    /**
     * Entity full name — initials are auto-computed (first letter of each of
     * the first 3 words, up to 2 for single-word names). Ignored when
     * `initials` is supplied.
     */
    name?: string
    /**
     * Pre-computed initials string (1–3 chars). Takes priority over `name`.
     * Use this when the parent already holds formatted initials.
     */
    initials?: string
    /**
     * Named size variant. Prefer this for entity-card contexts.
     * When a numeric `pixelSize` is supplied, `size` is ignored.
     */
    size?: 'sm' | 'md' | 'lg'
    /**
     * Explicit pixel size. Overrides `size` when set. Useful for flexible
     * table/list contexts (e.g. 22px owner cell, 32px row cell, 72px profile).
     */
    pixelSize?: number
    /**
     * ID used to pick a deterministic background colour from the palette.
     * When omitted the component falls back to the primary-900 brand colour.
     */
    entityId?: number
    /**
     * Renders the brand-header (on-navy) variant: semi-transparent white bg +
     * white initials + ring border. Allowed brand invariant — rgba values here
     * are intentional overlay constants, not DS tokens.
     */
    onBrand?: boolean
    /**
     * Square (rounded-md) shape instead of circle. Used in company table
     * cells where a square avatar distinguishes companies from contacts.
     */
    square?: boolean
  }>(),
  {
    name: undefined,
    initials: undefined,
    size: 'md',
    pixelSize: undefined,
    entityId: undefined,
    onBrand: false,
    square: false,
  },
)

/** Resolved initials: pre-supplied → auto-computed from name → '?' */
const displayInitials = computed(() => {
  if (props.initials) return props.initials.slice(0, 3).toUpperCase()
  if (props.name) {
    const words = props.name.trim().split(/\s+/).filter(Boolean)
    if (words.length === 0) return '?'
    return words
      .slice(0, 3)
      .map((w) => w[0] ?? '')
      .join('')
      .toUpperCase() || '?'
  }
  return '?'
})

/** Background colour: onBrand → undefined (CSS handles it), entityId % palette, else primary-900 token via CSS var */
const bgColor = computed((): string | undefined => {
  if (props.onBrand) return undefined
  if (props.entityId !== undefined) {
    return AVATAR_PALETTE[props.entityId % AVATAR_PALETTE.length]
  }
  return undefined // CSS class handles $primary-900 default
})

/** Whether a numeric pixel size is active */
const hasPixelSize = computed(() => props.pixelSize !== undefined && props.pixelSize > 0)

/** BEM size modifier class — only when using named sizes */
const sizeClass = computed(() =>
  hasPixelSize.value ? undefined : `entity-avatar--${props.size}`,
)

/** Inline pixel size for the container when pixelSize prop is set */
const resolvedPx = computed(() =>
  hasPixelSize.value ? props.pixelSize! : NAMED_SIZE_PX[props.size],
)

/** Container style: merge bg colour + optional explicit pixel dimensions */
const containerStyle = computed(() => {
  const style: Record<string, string> = {}
  if (bgColor.value) style.background = bgColor.value
  if (hasPixelSize.value) {
    style.width = `${resolvedPx.value}px`
    style.height = `${resolvedPx.value}px`
  }
  return style
})

/** Font size for initials — proportional when using explicit pixel size */
const initialsStyle = computed(() => {
  if (!hasPixelSize.value) return undefined
  return { fontSize: `${Math.round(resolvedPx.value * 0.38)}px` }
})
</script>

<style lang="scss" scoped>
.entity-avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  // Default background when no entityId — primary-900 token
  background: $primary-900;
  border-radius: $radius-circle;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border: 2px solid rgba(255, 255, 255, 0.25); // brand invariant: avatar ring on navy panel
  flex-shrink: 0;

  // ── Square variant (company avatar in table rows) ────────────────────────
  &--square {
    border-radius: $radius-md;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    border-color: transparent; // no ring on square/non-brand avatars
  }

  // ── Named size variants ──────────────────────────────────────────────────
  &--sm {
    width: 32px;
    height: 32px;

    .entity-avatar__initials {
      font-size: $font-size-xs;
    }
  }

  &--md {
    width: 56px;
    height: 56px;

    .entity-avatar__initials {
      font-size: $font-size-lg;
    }
  }

  &--lg {
    width: 72px;
    height: 72px;

    .entity-avatar__initials {
      font-size: $font-size-icon-md;
    }
  }
}

.entity-avatar__initials {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: #fff; // brand invariant: initials always on dark bg (navy / palette / onBrand overlay) — $surface-0 inverts to black in Aura dark
  font-weight: $font-weight-semibold;
  line-height: 1;
  letter-spacing: 0.02em;
  font-family: $font-family-sans;
  user-select: none;
}

// ── Brand-header (on-navy) variant ────────────────────────────────────────────
// rgba constants are brand invariants for the navy panel overlay — allowed by DS rules.
// stylelint-disable-next-line scale-unlimited/declaration-strict-value
.entity-avatar--on-brand {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: rgba(255, 255, 255, 0.14); // brand invariant: avatar on navy panel

  .entity-avatar__initials {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: #fff; // brand invariant: white initials on navy panel
  }
}
</style>
