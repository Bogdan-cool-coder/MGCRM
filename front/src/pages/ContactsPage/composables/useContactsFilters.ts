/**
 * ContactsOverlayFilters type and default — exported from here so they can be
 * used in both ContactsFilterOverlay.vue (<script setup> cannot re-export) and
 * useContactsPageData.ts.
 *
 * Notes on supported backend params:
 * - Contacts: owner_ids[], author_ids[], sources[], tags[], position,
 *             created_from/to, last_touch_from/to, open_deals_min/max,
 *             only_mine/only_active/only_with_deals/only_no_task
 * - Contacts NOT supported: city (no city column on crm_contacts),
 *             only_duplicates (no dedup hash)
 * - Companies: owner_ids[], company_type_ids[], category_codes[], sources[],
 *              tags[], country_code, city, created_from/to, last_touch_from/to,
 *              only_mine/only_active/only_with_deals/only_no_task
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
  /** City — only sent for companies, not contacts */
  city: string
  /** Position — contacts only */
  position: string
  open_deals_min: number | null
  open_deals_max: number | null
  created_range: Date[] | null
  last_touch_range: Date[] | null
  // presets
  only_mine: boolean
  only_active: boolean
  only_with_deals: boolean
  only_no_task: boolean
  // NOTE: only_duplicates intentionally removed — backend has no dedup-hash column
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
  position: '',
  open_deals_min: null,
  open_deals_max: null,
  created_range: null,
  last_touch_range: null,
  only_mine: false,
  only_active: false,
  only_with_deals: false,
  only_no_task: false,
}
