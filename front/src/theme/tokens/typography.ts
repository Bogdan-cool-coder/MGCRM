// Типографика MG CRM
// Бренд-шрифт: SF UI Display → web-fallback: Inter (Google Fonts)
// Base-size в CRM: 14px (плотный интерфейс)
export const typography = {
  fontFamily: {
    sans: "Inter, -apple-system, BlinkMacSystemFont, 'SF UI Display', 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif",
  },
  // NB: root font-size = 14px (base.scss `html, body`), поэтому rem-значения
  // рендерятся в 14-шкале, а НЕ 16. Фактические px: xs≈10.5, sm≈12.25, md=14,
  // lg≈15.75, xl≈17.5, 2xl=21, lead≈18.4. Комментарии-px ниже — НОМИНАЛЬНЫЕ
  // имена (16-root), не фактический рендер. Спеки «12px/14px» = эти токены (xs/sm).
  size: {
    base: '14px',
    xs: '0.75rem',   // nominal 12px (renders ~10.5) — caption, timestamp, meta
    sm: '0.875rem',  // nominal 14px (renders ~12.25) — body small, label
    md: '1rem',      // 14px — h6 / subsection
    lg: '1.125rem',  // nominal 18px (renders ~15.75)
    xl: '1.25rem',   // nominal 20px (renders ~17.5) — section title
    '2xl': '1.5rem', // nominal 24px (renders 21) — page title (h1 в CRM)
    '3xl': '1.75rem',
    '4xl': '2.25rem',
    lead: '1.3125rem', // nominal 21px (renders ~18.4) — lead/subtitle
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
