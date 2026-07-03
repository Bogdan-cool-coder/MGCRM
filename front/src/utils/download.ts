/**
 * Blob-download helpers for authenticated file exports.
 *
 * The app is Bearer-only (no cookie/session), so a top-level `window.open` carries
 * no Authorization header and 500s on the auth middleware. Files must be fetched
 * through the authenticated axios client as a Blob and turned into a temporary
 * object-URL `<a download>` — this module centralises that + the filename parsing
 * (previously copy-pasted in useDashboardPage / DealsPage / companies export).
 */
import type { AxiosResponse } from 'axios'

/**
 * Parse the download filename from a `Content-Disposition` header, supporting both
 * `filename*=UTF-8''…` (RFC 5987, percent-encoded) and plain `filename="…"`.
 * Returns null when the header is absent or carries no filename.
 */
export const filenameFromDisposition = (
  disposition: string | null | undefined,
): string | null => {
  if (!disposition) return null
  // RFC 5987 extended form wins (carries the correct charset for non-ASCII names).
  const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(disposition)
  if (utf8?.[1]) {
    try {
      return decodeURIComponent(utf8[1].trim())
    } catch {
      // Fall through to the plain form on a malformed percent-encoding.
    }
  }
  const plain = /filename="?([^";]+)"?/i.exec(disposition)
  return plain?.[1]?.trim() ?? null
}

/**
 * Trigger a browser download for a Blob under `filename` via a transient object URL.
 * Cleans up the URL after the click (no leaked object URLs).
 */
export const triggerBlobDownload = (blob: Blob, filename: string): void => {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.style.display = 'none'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

/**
 * Download an axios blob response, using the server's `Content-Disposition`
 * filename when present, else the provided fallback.
 */
export const downloadBlobResponse = (
  response: AxiosResponse<Blob>,
  fallbackName: string,
): void => {
  const name =
    filenameFromDisposition(response.headers['content-disposition'] as string | undefined) ??
    fallbackName
  triggerBlobDownload(response.data, name)
}
