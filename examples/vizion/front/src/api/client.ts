import axios, { type AxiosInstance, type AxiosError } from 'axios'

// Callbacks for axios middleware
// These are set by the app during initialization to avoid circular dependencies
type TokenGetter = () => string | null
type OnUnauthorized = () => void

let getToken: TokenGetter = () => null
let onUnauthorized: OnUnauthorized = () => {}

/**
 * Configure axios middleware callbacks
 * Should be called once during app initialization
 */
export function configureAxiosMiddleware(callbacks: {
  getToken: TokenGetter
  onUnauthorized: OnUnauthorized
}): void {
  getToken = callbacks.getToken
  onUnauthorized = callbacks.onUnauthorized
}

/**
 * Create axios instance with authentication middleware
 */
export const createAxiosInstance = (): AxiosInstance => {
  const instance = axios.create({
    baseURL: '',
    headers: {
      'Content-Type': 'application/json',
    },
  })

  // Request interceptor: add auth token
  instance.interceptors.request.use((config) => {
    const token = getToken()
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  })

  // Response interceptor: handle 401 errors
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

/**
 * Default axios instance with middleware configured
 */
export const apiClient = createAxiosInstance()
