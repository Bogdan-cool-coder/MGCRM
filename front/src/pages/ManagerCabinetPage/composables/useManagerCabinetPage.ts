/**
 * Manager Cabinet page composable.
 *
 * Handles: profile, KPI, activity feed loading.
 * Pattern: useAsyncResource (Vizion) — no raw fetch/axios in components.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useUserStore } from '@/stores/user'
import { getProfile, getKpiData, getActivityFeed } from '@/api/managerCabinet'
import { usersApi, type UserOptionDto } from '@/api/users'
import type {
  MeProfile,
  KpiResponse,
  KpiPeriod,
  ActivityFeedItem,
  ActivityFeedMeta,
} from '@/entities/managerCabinet'

export const useManagerCabinetPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()

  // ─── Viewed user ─────────────────────────────────────────────────────────
  const canViewOthers = computed<boolean>(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })

  const viewedUserId = computed<number | null>(() => {
    const qid = route.query.user_id
    if (!qid || !canViewOthers.value) return null
    const parsed = parseInt(String(qid), 10)
    return Number.isFinite(parsed) ? parsed : null
  })

  // Selectable members for the cross-user picker (privileged viewers only):
  // active managers/directors. Empty for non-privileged roles.
  const memberOptions = ref<UserOptionDto[]>([])

  const loadMemberOptions = async (): Promise<void> => {
    if (!canViewOthers.value) return
    try {
      const all = await usersApi.getUsers()
      memberOptions.value = all.filter((u) => u.role === 'manager' || u.role === 'director')
    } catch {
      memberOptions.value = []
    }
  }

  // Sets / clears ?user_id= in the URL; the viewedUserId computed reacts and the
  // watcher re-fetches profile/KPI/feed for the selected member.
  const setViewedUser = (id: number | null): void => {
    const query = { ...route.query }
    if (id == null) {
      delete query.user_id
    } else {
      query.user_id = String(id)
    }
    void router.replace({ query })
  }

  // ─── Period ───────────────────────────────────────────────────────────────
  const period = ref<KpiPeriod>('current_month')

  // ─── Feed filters ─────────────────────────────────────────────────────────
  const feedKind = ref<'all' | 'call' | 'meeting' | 'task' | 'note'>('all')
  const feedFtmOnly = ref<boolean>(false)
  const feedPage = ref<number>(1)

  // ─── Async resources ──────────────────────────────────────────────────────
  const profileResource = useAsyncResource<MeProfile | null>(() => null)
  const kpiResource = useAsyncResource<KpiResponse | null>(() => null)
  const feedItemsRef = ref<ActivityFeedItem[]>([])
  const feedMetaRef = ref<ActivityFeedMeta | null>(null)
  const feedLoadingRef = ref<boolean>(false)

  // ─── Loaders ──────────────────────────────────────────────────────────────
  const loadProfile = async (): Promise<void> => {
    try {
      await profileResource.run(() => getProfile(viewedUserId.value ?? undefined))
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    }
  }

  const loadKpi = async (): Promise<void> => {
    try {
      await kpiResource.run(() =>
        getKpiData({
          period: period.value,
          user_id: viewedUserId.value ?? undefined,
        }),
      )
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    }
  }

  const loadFeed = async (): Promise<void> => {
    feedLoadingRef.value = true
    try {
      const res = await getActivityFeed({
        period: period.value,
        kind: feedKind.value,
        ftm_only: feedFtmOnly.value || undefined,
        user_id: viewedUserId.value ?? undefined,
        page: feedPage.value,
      })
      feedItemsRef.value = res.data
      feedMetaRef.value = res.meta
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    } finally {
      feedLoadingRef.value = false
    }
  }

  // ─── Setters ──────────────────────────────────────────────────────────────
  const setPeriod = (p: KpiPeriod): void => {
    period.value = p
    feedPage.value = 1
    // watches will fire loadKpi + loadFeed
  }

  const setFeedKind = (k: 'all' | 'call' | 'meeting' | 'task' | 'note'): void => {
    feedKind.value = k
    feedPage.value = 1
  }

  const setFeedFtmOnly = (v: boolean): void => {
    feedFtmOnly.value = v
    feedPage.value = 1
  }

  const setFeedPage = (n: number): void => {
    feedPage.value = n
  }

  const resetFeedFilters = (): void => {
    feedKind.value = 'all'
    feedFtmOnly.value = false
    feedPage.value = 1
  }

  // ─── Watchers ─────────────────────────────────────────────────────────────
  watch(period, () => {
    void loadKpi()
    void loadFeed()
  })

  watch([feedKind, feedFtmOnly, feedPage], () => {
    void loadFeed()
  })

  // Re-load everything when a privileged viewer switches the inspected member.
  watch(viewedUserId, () => {
    feedPage.value = 1
    void loadProfile()
    void loadKpi()
    void loadFeed()
  })

  // ─── Mount ────────────────────────────────────────────────────────────────
  onMounted(async () => {
    await Promise.all([loadProfile(), loadKpi(), loadFeed(), loadMemberOptions()])
  })

  return {
    // Data
    profile: profileResource.data,
    profileLoading: profileResource.loading,
    kpi: kpiResource.data,
    kpiLoading: kpiResource.loading,
    feed: feedItemsRef,
    feedLoading: feedLoadingRef,
    feedMeta: feedMetaRef,
    // Filter state
    period,
    feedKind,
    feedFtmOnly,
    feedPage,
    viewedUserId,
    canViewOthers,
    memberOptions,
    // Actions
    setPeriod,
    setFeedKind,
    setFeedFtmOnly,
    setFeedPage,
    resetFeedFilters,
    setViewedUser,
  }
}
