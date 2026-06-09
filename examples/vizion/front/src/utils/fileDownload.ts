/**
 * Triggers a browser file download from an in-memory Blob.
 *
 * Used by blob-typed API responses (e.g. generated document PDF / docx via
 * `apiClient.get(url, { responseType: 'blob' })`). Creates an object URL,
 * synthesises a click on a hidden `<a download>`, then revokes the URL on the
 * next macrotask so the navigation has started before the URL is freed.
 *
 * Accepts either a ready Blob or a `Promise<Blob>` (so callers can pass the
 * service call directly, e.g. `downloadBlob(documentService.downloadGenerated(id), name)`).
 */
export async function downloadBlob(
  source: Blob | Promise<Blob>,
  filename: string,
): Promise<void> {
  const blob = await source

  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  // `display:none` + appended to the DOM keeps the click reliable across
  // browsers (some ignore clicks on detached anchors).
  anchor.style.display = 'none'
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)

  // Defer revocation: revoking synchronously can cancel the download in some
  // browsers before it has begun. A 0ms timeout pushes it past the current
  // task once the navigation is in flight.
  window.setTimeout(() => URL.revokeObjectURL(url), 0)
}
