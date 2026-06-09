import {
  notifyCompanySwitchError,
  resetAuthenticatedSessionState,
  resetSessionCoordinatorRuntime,
  clearSessionState,
} from './session'
import {
  notificationCenter,
  type NotificationMessage,
  type NotificationSeverity,
} from './notificationCenter'
import {
  changeLocale,
  configureLocaleCoordinator,
  localeManager,
  resolveInitialLocale,
  setLocale,
  startNewLocaleSession,
  syncOnce,
  type AvailableLocales,
} from './locale'
import { bootstrapApp } from './bootstrap'
import {
  APPLICATION_SERVICES_KEY,
  createApplicationServices,
  useApplicationServices,
  type ApplicationServices,
} from './applicationServices'
import {
  createSessionCoordinator,
  createUserSessionService,
  type SessionCoordinator,
  type UserSessionService,
} from './session'

export { bootstrapApp }
export { setBootstrapSessionPromise, waitForBootstrapSession } from './bootstrap'
export { notificationCenter }
export type { NotificationMessage, NotificationSeverity, AvailableLocales }
export {
  changeLocale,
  configureLocaleCoordinator,
  localeManager,
  resolveInitialLocale,
  setLocale,
  startNewLocaleSession,
  syncOnce,
}
export { APPLICATION_SERVICES_KEY, createApplicationServices, useApplicationServices }
export type { ApplicationServices }
export {
  createUserSessionService,
  createSessionCoordinator,
  notifyCompanySwitchError,
  resetAuthenticatedSessionState,
  resetSessionCoordinatorRuntime,
  clearSessionState,
  type SessionCoordinator,
  type UserSessionService,
}
export { createUnauthorizedHandler } from './auth'
