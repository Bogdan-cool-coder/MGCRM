export const useReportLink = () => {
  const resolveLink = (
    template: string,
    row: Record<string, string | number | boolean | null>,
    crmUrl: string | null | undefined,
  ): string | null => {
    if (!template) return null

    const hasCrmUrlPlaceholder = template.includes('{crm_url}')
    if (hasCrmUrlPlaceholder && !crmUrl) return null

    const resolvedCrmUrl = crmUrl ? crmUrl.replace(/\/$/, '') : ''

    const placeholderPattern = /\{([^}]+)\}/g
    let result = template
    let match: RegExpExecArray | null

    while ((match = placeholderPattern.exec(template)) !== null) {
      const placeholder = match[0]
      const key = match[1] as string | undefined

      if (!key) continue

      if (key === 'crm_url') {
        result = result.replace(placeholder, resolvedCrmUrl)
        continue
      }

      const value = row[key]

      if (value === undefined || value === null) {
        return null
      }

      result = result.replace(placeholder, String(value))
    }

    return result
  }

  return { resolveLink }
}
