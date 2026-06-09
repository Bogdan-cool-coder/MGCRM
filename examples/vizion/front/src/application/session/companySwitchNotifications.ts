import { notificationCenter } from '@/application/notificationCenter'
import { i18n } from '@/plugins/i18n'

/**
 * Translates the HTTP status returned by `POST /api/active-company/{id}` into
 * a user-facing toast. Lives in `application/session/` because company-switch
 * UX is a session-level concern — the `companies` store stays free of i18n
 * and notification side-effects (see `useCompaniesStore.switchActiveCompany`).
 *
 * Falls back to the raw error message when no localised key exists for the
 * given status.
 */
export const notifyCompanySwitchError = (
  status: number | null | undefined,
  fallbackMessage: string,
): void => {
  const messageKey =
    status === 403
      ? 'companies.switchForbidden'
      : status === 404
        ? 'companies.switchNotFound'
        : 'companies.switchFailed'

  const detail = i18n.global.te(messageKey)
    ? i18n.global.t(messageKey)
    : fallbackMessage

  notificationCenter.error(detail)
}
