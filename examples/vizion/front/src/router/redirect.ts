const REDIRECT_QUERY_KEY = 'redirect'

export const normalizePathname = (pathname: string) => {
  if (pathname === '/') return pathname
  const normalized = pathname.replace(/\/+$/, '')
  return normalized || '/'
}

export const parseRedirectTarget = (target: string | null) => {
  if (!target) return null

  if (!isSafeRedirect(target)) return null

  const baseUrl = window.location.origin
  const normalizedUrl = new URL(target, baseUrl)
  const normalizedPathname = normalizePathname(normalizedUrl.pathname)

  if (normalizedUrl.origin !== baseUrl) return null

  const iframeToken = normalizedUrl.searchParams.get('token')

  normalizedUrl.pathname = normalizedPathname
  normalizedUrl.searchParams.delete('token')

  const sanitizedSearch = normalizedUrl.searchParams.toString()
  const sanitizedTarget =
    normalizedPathname === '/login'
      ? null
      : normalizedUrl.pathname +
        (sanitizedSearch ? `?${sanitizedSearch}` : '') +
        normalizedUrl.hash

  return {
    iframeToken,
    sanitizedTarget,
  }
}

export const buildLoginRedirect = (fullPath: string) => {
  const redirectTarget = parseRedirectTarget(fullPath)?.sanitizedTarget ?? '/'

  return {
    path: '/login',
    query: {
      [REDIRECT_QUERY_KEY]: redirectTarget,
    },
  }
}

export const extractRedirect = (query: Record<string, unknown>): string | null => {
  const value = query[REDIRECT_QUERY_KEY]

  if (!value) return null

  const redirect = Array.isArray(value) ? value[0] : value

  if (typeof redirect !== 'string') return null

  return parseRedirectTarget(redirect)?.sanitizedTarget ?? null
}

const isSafeRedirect = (path: string) => {
  return path.startsWith('/') && !path.startsWith('//')
}
