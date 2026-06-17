import axios, { type AxiosInstance, type AxiosError } from 'axios'

// Callbacks для axios middleware
// Устанавливаются при инициализации приложения (избегает circular deps)
type TokenGetter = () => string | null
type OnUnauthorized = () => void

let getToken: TokenGetter = () => null
let onUnauthorized: OnUnauthorized = () => {}

/**
 * Настройка axios middleware callbacks.
 * Вызывается один раз при инициализации в main.ts.
 */
export function configureAxiosMiddleware(callbacks: {
  getToken: TokenGetter
  onUnauthorized: OnUnauthorized
}): void {
  getToken = callbacks.getToken
  onUnauthorized = callbacks.onUnauthorized
}

/**
 * Создать axios instance с Bearer auth + 401 handler.
 */
export const createAxiosInstance = (): AxiosInstance => {
  const instance = axios.create({
    baseURL: '',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  })

  // Request interceptor: добавить Bearer токен
  instance.interceptors.request.use((config) => {
    const token = getToken()
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  })

  // Response interceptor: обработать 401
  instance.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
      if (error.response?.status === 401) {
        onUnauthorized()
      }
      return Promise.reject(error)
    },
  )

  return instance
}

export const apiClient = createAxiosInstance()
