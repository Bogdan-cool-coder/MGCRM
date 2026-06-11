// ==========================================
// MG CRM COLOR PALETTE — ЕДИНЫЙ ИСТОЧНИК ИСТИНЫ
// Источник: brand/MACRO-Global-Brandbook.pdf стр. 14–15
// ==========================================

export const commonColors = {
  // Фирменные цвета бренда MACRO Global
  primary: '#172747',
  primaryDark: '#0E172B',
  primaryLight: '#2B4987',

  secondary: '#6C757D',
  secondaryDark: '#54595E',
  secondaryLight: '#ABB5BE',

  // Статусные цвета (базовые solid — из брендбука)
  success: '#A7EFAA',
  danger: '#FF5A44',
  warning: '#FFB38A',
  info: '#8DD9FF',

  // Вспомогательные
  errorBg: '#fff5f4',
  errorBorder: '#ffd1cd',
  errorText: '#e61c14',

  textPrimary: '#272829',
  textSecondary: '#616263',
  textMuted: '#7E7F82',

  borderDefault: '#E3E4E6',
  borderLight: '#D5D6D8',
} as const

// Основная синяя палитра (#172747 — brand primary = 900)
export const primaryPalette = {
  50: '#f5f7fb',
  100: '#e3e8f3',
  200: '#c7d0e6',
  300: '#9fb0d4',
  400: '#6f87bc',
  500: '#4a67a3',
  600: '#334d8a',
  700: '#263a6e',
  800: '#1f2f5a',
  900: '#172747', // brand-primary
  950: '#0E172B', // brand-primary-dark
} as const

// Gray-шкала бренда (стр. 14 брендбука)
export const surfacePalette = {
  0: '#FFFFFF',
  50: '#F9FAFB',
  100: '#F1F2F3', // Gray-100
  200: '#E3E4E6', // Gray-200
  300: '#D5D6D8', // Gray-300
  400: '#B8B9BB', // Gray-400
  500: '#9B9C9F', // Gray-500
  600: '#7E7F82', // Gray-600
  700: '#616263', // Gray-700
  800: '#444547', // Gray-800
  900: '#272829', // Gray-900
  950: '#000000',
} as const

export const monochromePalette = {
  0: '#FFFFFF',
  50: '#fafafa',
  100: '#f5f5f5',
  200: '#e5e5e5',
  300: '#d4d4d4',
  400: '#a3a3a3',
  500: '#737373',
  600: '#525252',
  700: '#404040',
  800: '#262626',
  900: '#171717',
  950: '#0a0a0a',
  white: '#FFFFFF',
  black: '#000000',
} as const

// Зелёная палитра (success — из брендбука: solid #A7EFAA)
export const greenPalette = {
  50: '#f0fdf0',
  100: '#dcfadc',
  200: '#bff5c0',
  300: '#93eba4',
  400: '#64db7a',
  500: '#A7EFAA', // brand success solid
  600: '#45c25a',
  700: '#36a04b',
  800: '#308240',
  900: '#2b6b38',
  950: '#163d1d',
} as const

// Красная палитра (danger — из брендбука: solid #FF5A44)
export const redPalette = {
  50: '#fff5f4',
  100: '#ffe8e6',
  200: '#ffd1cd',
  300: '#ffaba5',
  400: '#ff7a72',
  500: '#FF5A44', // brand danger solid
  600: '#ff3a2a',
  700: '#e61c14',
  800: '#bd1814',
  900: '#9b1917',
  950: '#550707',
} as const

// Оранжевая палитра (warning — из брендбука: solid #FFB38A)
export const orangePalette = {
  50: '#fff9f5',
  100: '#fff0e8',
  200: '#ffe0d1',
  300: '#ffc8ad',
  400: '#ffa57f',
  500: '#FFB38A', // brand warning solid
  600: '#ff855a',
  700: '#e6643a',
  800: '#bd5030',
  900: '#9b4029',
  950: '#551d12',
} as const

// Синяя палитра (info — из брендбука: solid #8DD9FF)
export const bluePalette = {
  50: '#f5faff',
  100: '#e8f4ff',
  200: '#cde8ff',
  300: '#a3d5ff',
  400: '#6bbaff',
  500: '#8DD9FF', // brand info solid
  600: '#4a9fff',
  700: '#2a84e6',
  800: '#246bbd',
  900: '#22589b',
  950: '#123355',
} as const

export const statusColors = {
  success: commonColors.success,
  danger: commonColors.danger,
  warning: commonColors.warning,
  info: commonColors.info,
} as const

export const colors = {
  ...commonColors,
  primary: primaryPalette,
  surface: surfacePalette,
  red: redPalette,
  orange: orangePalette,
  blue: bluePalette,
  green: greenPalette,
  monochrome: monochromePalette,
} as const
