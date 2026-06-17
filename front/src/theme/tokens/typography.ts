// Типографика MG CRM
// Бренд-шрифт: SF UI Display → web-fallback: Inter (Google Fonts)
// Base-size в CRM: 14px (плотный интерфейс)
export const typography = {
  fontFamily: {
    sans: "Inter, -apple-system, BlinkMacSystemFont, 'SF UI Display', 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif",
  },
  size: {
    base: '14px',
    xs: '0.75rem',   // 12px — caption, timestamp
    sm: '0.875rem',  // 14px — body small, label
    md: '1rem',      // 16px — h6 / subsection
    lg: '1.125rem',  // 18px
    xl: '1.25rem',   // 20px — section title
    '2xl': '1.5rem', // 24px — page title (h1 в CRM)
    '3xl': '1.75rem',
    '4xl': '2.25rem',
    lead: '1.3125rem', // 21px — lead/subtitle
  },
  weight: {
    normal: '400',
    medium: '500',
    semibold: '600',
    bold: '700',
  },
  lineHeight: {
    tight: '1.25',
    normal: '1.5',
    relaxed: '1.625',
  },
} as const
