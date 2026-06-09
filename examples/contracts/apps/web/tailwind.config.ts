import type { Config } from "tailwindcss";

// Брендовая палитра MACRO Global Technologies (из брендбука)
// v2 (2026-06-04): добавлены семантические шкалы, elevation-тени, радиусы, motion-токены
const config: Config = {
  content: ["./src/**/*.{ts,tsx,js,jsx}"],
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: "#172747",
          dark: "#0E172B",
          light: "#2B4987",
        },
        secondary: {
          DEFAULT: "#6C757D",
          dark: "#54595E",
          light: "#ABB5BE",
        },
        gray: {
          50: "#F1F2F3",  // gray-100 в брендбуке (используем как 50 для tailwind стиля)
          100: "#F1F2F3",
          200: "#E3E4E6",
          300: "#D5D6D8",
          400: "#B8B9BB",
          500: "#9B9C9F",
          600: "#7E7F82",
          700: "#616263",
          800: "#444547",
          900: "#272829",
        },
        // Семантические цвета v2: шкалы 50/100/500/600/700 + DEFAULT для обратной совместимости.
        // DEFAULT совпадает с *-500 — это целевой «акцентный» тон.
        // Старые классы bg-success / text-danger / bg-warning и т.д. продолжают работать.
        success: {
          50: "#ECFDF3",
          100: "#D1FADF",
          500: "#12B76A",
          600: "#039855",
          700: "#027A48",
          DEFAULT: "#12B76A",
        },
        warning: {
          50: "#FFFAEB",
          100: "#FEF0C7",
          500: "#F79009",
          600: "#DC6803",
          700: "#B54708",
          DEFAULT: "#F79009",
        },
        danger: {
          50: "#FEF3F2",
          100: "#FEE4E2",
          500: "#F04438",
          600: "#D92D20",
          700: "#B42318",
          DEFAULT: "#F04438",
        },
        info: {
          50: "#EFF8FF",
          100: "#D1E9FF",
          500: "#2E90FA",
          600: "#1570EF",
          700: "#175CD3",
          DEFAULT: "#2E90FA",
        },
      },
      fontFamily: {
        // Подключается через next/font в layout.tsx, здесь только порядок fallback
        sans: ["var(--font-inter)", "Inter", "-apple-system", "BlinkMacSystemFont", "SF Pro Display", "Segoe UI", "Roboto", "sans-serif"],
        document: ["Roboto", "sans-serif"],
      },
      fontSize: {
        // h1–h6 из брендбука
        "h1": ["40px", { lineHeight: "1.2", fontWeight: "700" }],
        "h2": ["32px", { lineHeight: "1.25", fontWeight: "700" }],
        "h3": ["28px", { lineHeight: "1.3", fontWeight: "700" }],
        "h4": ["24px", { lineHeight: "1.35", fontWeight: "700" }],
        "h5": ["20px", { lineHeight: "1.4", fontWeight: "600" }],
        "h6": ["16px", { lineHeight: "1.45", fontWeight: "600" }],
        "lead": ["21px", { lineHeight: "1.5" }],
      },
      maxWidth: {
        container: "1320px",
      },
      borderRadius: {
        // v2: полная шкала радиусов. DEFAULT=8 сохранён для обратной совместимости.
        sm: "6px",
        DEFAULT: "8px",
        md: "10px",
        lg: "12px",
        xl: "14px",
        "2xl": "18px",
        "3xl": "24px",
        full: "9999px",
      },
      boxShadow: {
        // v2: elevation-система (5 уровней). В dark mode заменяется на border через CSS.
        "elev-1": "0 1px 2px rgba(16,24,40,.06)",
        "elev-2": "0 4px 10px -2px rgba(16,24,40,.10)",
        "elev-3": "0 14px 24px -8px rgba(16,24,40,.16)",
        "elev-4": "0 24px 48px -12px rgba(16,24,40,.30)",
      },
      transitionTimingFunction: {
        // v2: motion easing-токены
        "standard": "cubic-bezier(.2,.8,.2,1)",
        "emphasized": "cubic-bezier(.3,.7,0,1)",
      },
      transitionDuration: {
        // v2: motion duration-токены (именованные, не конфликтуют со стандартными ms-числами)
        fast: "120ms",
        base: "200ms",
        slow: "320ms",
      },
    },
  },
  plugins: [],
};
export default config;
