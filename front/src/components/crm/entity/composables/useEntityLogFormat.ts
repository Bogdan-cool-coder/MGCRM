/**
 * useEntityLogFormat — shared icon/label/date helpers for EntityLogTab and EntityMiniTimeline.
 * Extracted so both components stay in sync without duplication.
 */
import { useI18n } from 'vue-i18n'
import type { EntityLogEntry, EntityLogEventType, EntityLogMeta } from '@/entities/crm'

export const EVENT_ICONS: Partial<Record<EntityLogEventType, string>> & Record<string, string> = {
  created: 'pi-plus-circle',
  updated: 'pi-pencil',
  stage_changed: 'pi-arrow-right-arrow-left',
  contact_added: 'pi-user-plus',
  contact_removed: 'pi-user-minus',
  task_completed: 'pi-check-circle',
  meeting_held: 'pi-video',
  note_added: 'pi-comment',
  document_created: 'pi-file',
  document_signed: 'pi-file-check',
  finance_added: 'pi-wallet',
  status_changed: 'pi-flag',
  employee_added: 'pi-user-plus',
  employee_removed: 'pi-user-minus',
  relation_added: 'pi-share-alt',
  relation_removed: 'pi-minus-circle',
  custom_field_changed: 'pi-sliders-h',
  // MGCRM-native backend actions
  kp_sent: 'pi-send',
  contract_sent: 'pi-file-export',
  data_changed: 'pi-pencil',
  contract_event: 'pi-file',
  finance_event: 'pi-wallet',
}

export function useEntityLogFormat() {
  const { t } = useI18n()

  function eventIcon(type: EntityLogEventType | string | null | undefined): string {
    if (!type) return 'pi-info-circle'
    return EVENT_ICONS[type] ?? 'pi-info-circle'
  }

  function eventLabel(type: EntityLogEventType | string | null | undefined): string {
    if (!type) return t('crm.log.events.unknown')
    const key = `crm.log.events.${type}`
    const label = t(key)
    // vue-i18n returns the key itself when missing; treat that as unknown
    return label === key ? t('crm.log.events.unknown') : label
  }

  /** Translate a raw column name (data_changed diff) to a human label, or echo it. */
  function fieldLabel(field: string): string {
    const key = `crm.log.fields.${field}`
    const label = t(key)
    return label === key ? field : label
  }

  /** Render a scalar diff value, with an em-dash placeholder for null/empty. */
  function diffValue(v: string | number | boolean | null | undefined): string {
    if (v === null || v === undefined || v === '') return '—'
    if (typeof v === 'boolean') return v ? t('common.yes', 'да') : t('common.no', 'нет')
    return String(v)
  }

  /**
   * Build a human-readable detail string from the row's `meta`, per action.
   * Renderers append this after the event label. Returns '' when there is no
   * meaningful detail (the row then shows just "actor — event").
   */
  function detailText(entry: Pick<EntityLogEntry, 'action' | 'meta'>): string {
    const meta: EntityLogMeta = entry.meta ?? {}

    switch (entry.action) {
      case 'stage_changed': {
        const from = meta.from_stage_name ?? null
        const to = meta.to_stage_name ?? null
        if (from && to) return `${from} → ${to}`
        if (to) return to
        return ''
      }
      case 'created':
        return meta.title ? `«${meta.title}»` : ''
      case 'contact_added':
        return meta.contact_name ? `«${meta.contact_name}»` : ''
      case 'data_changed': {
        const changes = Array.isArray(meta.changes) ? meta.changes : []
        if (changes.length === 0) return ''
        return changes
          .map((c) => `${fieldLabel(c.field)}: ${diffValue(c.old)} → ${diffValue(c.new)}`)
          .join('; ')
      }
      default: {
        // Fall back to a title if the action carries one.
        return typeof meta.title === 'string' && meta.title ? `«${meta.title}»` : ''
      }
    }
  }

  function formatDate(iso: string): string {
    try {
      return new Intl.DateTimeFormat(undefined, {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
      }).format(new Date(iso))
    } catch {
      return iso
    }
  }

  function relativeDate(iso: string): string {
    try {
      const now = Date.now()
      const then = new Date(iso).getTime()
      const diffMs = now - then
      const diffDays = Math.floor(diffMs / 86_400_000)
      if (diffDays === 0) return t('common.today', 'сегодня')
      if (diffDays === 1) return t('common.yesterday', 'вчера')
      return t('crm.entity.miniTimeline.daysAgo', { n: diffDays }, `${diffDays}д назад`)
    } catch {
      return iso
    }
  }

  return { eventIcon, eventLabel, fieldLabel, diffValue, detailText, formatDate, relativeDate }
}
