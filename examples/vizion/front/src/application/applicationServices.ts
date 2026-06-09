import { inject } from 'vue'
import type { Pinia } from 'pinia'
import type { Router } from 'vue-router'
import type { Services } from '@/services'
import { createUserSessionService, type UserSessionService } from './session'
import { createSessionCoordinator, type SessionCoordinator } from './session'
import { createUnauthorizedHandler } from './auth'

export type ApplicationServices = {
  userSessionService: UserSessionService
  sessionCoordinator: SessionCoordinator
  unauthorizedHandler: () => void
}

export const APPLICATION_SERVICES_KEY = Symbol('application-services')

export const createApplicationServices = (options: {
  pinia: Pinia
  router: Router
  services: Services
}): ApplicationServices => {
  const userSessionService = createUserSessionService({
    pinia: options.pinia,
    services: options.services,
  })

  return {
    userSessionService,
    sessionCoordinator: createSessionCoordinator({
      pinia: options.pinia,
      services: options.services,
      userSessionService,
    }),
    unauthorizedHandler: createUnauthorizedHandler({
      pinia: options.pinia,
      router: options.router,
    }),
  }
}

export const useApplicationServices = (): ApplicationServices => {
  const applicationServices = inject<ApplicationServices>(APPLICATION_SERVICES_KEY)

  if (!applicationServices) {
    throw new Error(
      'Application services not provided. Make sure to call app.provide(APPLICATION_SERVICES_KEY, applicationServices) in main.ts',
    )
  }

  return applicationServices
}

