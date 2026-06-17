import type { AxiosError } from 'axios'

export function isAxiosError(error: unknown): error is AxiosError {
  return (
    typeof error === 'object' &&
    error !== null &&
    'isAxiosError' in error &&
    (error as AxiosError).isAxiosError === true
  )
}

export function getApiErrorStatus(error: unknown): number | null {
  if (isAxiosError(error) && error.response) {
    return error.response.status
  }
  return null
}

export function isUnauthorizedStatus(status: number | null): boolean {
  return status === 401
}

export function isUnauthorizedError(error: unknown): boolean {
  return isUnauthorizedStatus(getApiErrorStatus(error))
}

export function getApiErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) return fallback

  const data = error.response?.data as
    | { message?: string; errors?: Record<string, string[]> }
    | undefined

  if (data?.message) return data.message

  if (data?.errors) {
    const firstField = Object.values(data.errors)[0]
    if (firstField?.[0]) return firstField[0]
  }

  return fallback
}

export function getValidationErrors(
  error: unknown,
): Record<string, string> | null {
  if (!isAxiosError(error)) return null

  const data = error.response?.data as
    | { errors?: Record<string, string[]> }
    | undefined

  if (!data?.errors) return null

  const flat: Record<string, string> = {}
  for (const [field, messages] of Object.entries(data.errors)) {
    const firstMsg = messages[0]
    if (firstMsg) flat[field] = firstMsg
  }
  return flat
}
