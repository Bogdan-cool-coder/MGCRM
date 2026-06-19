/**
 * ContactsOverlayFilters type and default — exported from here so they can be
 * used in both ContactsFilterOverlay.vue (<script setup> cannot re-export) and
 * useContactsPageData.ts.
 */

export interface ContactsOverlayFilters {
  owner_ids: number[]
  author_ids: number[]
  tags: string[]
  sources: string[]
  engagement_tier: 'fresh' | 'cooling' | 'cold' | null
  company_type_ids: number[]
  categories: string[]
  country_code: string | null
  city: string
  open_deals_min: number | null
  open_deals_max: number | null
  created_range: Date[] | null
  last_touch_range: Date[] | null
  // presets
  only_mine: boolean
  only_active: boolean
  only_with_deals: boolean
  only_no_task: boolean
  only_duplicates: boolean
}

export const DEFAULT_OVERLAY_FILTERS: ContactsOverlayFilters = {
  owner_ids: [],
  author_ids: [],
  tags: [],
  sources: [],
  engagement_tier: null,
  company_type_ids: [],
  categories: [],
  country_code: null,
  city: '',
  open_deals_min: null,
  open_deals_max: null,
  created_range: null,
  last_touch_range: null,
  only_mine: false,
  only_active: false,
  only_with_deals: false,
  only_no_task: false,
  only_duplicates: false,
}
