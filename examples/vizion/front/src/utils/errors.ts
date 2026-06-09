import axios from 'axios'

type ApiErrorData = {
  message?: string | Record<string, unknown> | unknown[]
  detail?: string | Record<string, unknown> | unknown[]
  error?: string | Record<string, unknown> | unknown[]
  errors?: Record<string, string[]>
  [key: string]: unknown
}

const extractMessageText = (value: unknown): string | null => {
  if (typeof value === 'string') {
    const normalized = value.trim()
    return normalized || null
  }

  if (Array.isArray(value)) {
    const nestedMessages = value
      .map(extractMessageText)
      .filter((item): item is string => Boolean(item))

    return nestedMessages.length > 0 ? nestedMessages.join(', ') : null
  }

  if (value && typeof value === 'object') {
    const objectValue = value as Record<string, unknown>
    const prioritizedKeys = ['message', 'detail', 'error', 'title']

    for (const key of prioritizedKeys) {
      const nestedMessage = extractMessageText(objectValue[key])
      if (nestedMessage) {
        return nestedMessage
      }
    }

    const nestedValues = Object.values(objectValue)
      .map(extractMessageText)
      .filter((item): item is string => Boolean(item))

    return nestedValues.length > 0 ? nestedValues.join(', ') : null
  }

  return null
}

export const isUnauthorizedStatus = (status: number | undefined): boolean => {
  return status === 401 || status === 419
}

export const getApiErrorStatus = (error: unknown): number | undefined => {
  if (!axios.isAxiosError(error)) return undefined
  return error.response?.status
}

export const isUnauthorizedError = (error: unknown): boolean => {
  return isUnauthorizedStatus(getApiErrorStatus(error))
}

export const getApiErrorMessage = (error: unknown, fallback: string): string => {
  if (axios.isAxiosError<ApiErrorData>(error)) {
    const responseData = error.response?.data
    const extractedMessage =
      extractMessageText(responseData?.message) ??
      extractMessageText(responseData?.detail) ??
      extractMessageText(responseData?.error) ??
      extractMessageText(responseData)

    return extractedMessage ?? error.message ?? fallback
  }

  if (error instanceof Error && error.message) {
    return error.message
  }

  if (typeof error === 'string' && error.trim()) {
    return error
  }

  return fallback
}

export const getApiValidationErrors = (
  error: unknown,
): Record<string, string[]> | undefined => {
  if (!axios.isAxiosError<ApiErrorData>(error)) return undefined
  return error.response?.data?.errors
}

export interface NormalizedApiError {
  message: string
  status?: number
  validationErrors?: Record<string, string[]>
  isAxiosError: boolean
  isUnauthorized: boolean
}

export const normalizeApiError = (
  error: unknown,
  fallback: string,
): NormalizedApiError => {
  const status = getApiErrorStatus(error)

  return {
    message: getApiErrorMessage(error, fallback),
    status,
    validationErrors: getApiValidationErrors(error),
    isAxiosError: axios.isAxiosError(error),
    isUnauthorized: isUnauthorizedStatus(status),
  }
}
