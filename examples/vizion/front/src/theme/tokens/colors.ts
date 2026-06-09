// ==========================================
// VIZION COLOR PALETTE - ЕДИНЫЙ ИСТОЧНИК ИСТИНЫ
// ==========================================

export const commonColors = {
  // Основные цвета
  primary: '#172747',
  primaryDark: '#0E172B',
  primaryLight: '#2B4987',

  secondary: '#6C757D',
  secondaryDark: '#54595E',
  secondaryLight: '#ABB5BE',

  // Цвета статусов (базовые)
  success: '#A7EFAA',
  danger: '#FF5A44',
  warning: '#FFB38A',
  info: '#8DD9FF',

  // Вспомогательные цвета
  errorBg: '#fef2f2',
  errorBorder: '#fecaca',
  errorText: '#dc2626',

  textPrimary: '#111827',
  textSecondary: '#6b7280',
  textMuted: '#9ca3af',

  borderDefault: '#e5e7eb',
  borderLight: '#d1d5db',
}

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
  900: commonColors.primary, // #172747
  950: commonColors.primaryDark, // #0E172B
}

export const surfacePalette = {
  0: '#FFFFFF', // #FFFFFF
  50: '#f9fafb',
  100: '#f1f2f3',
  200: '#E3E4E6',
  300: '#D5D6D8',
  400: '#B8B9BB',
  500: '#9B9C9F',
  600: '#7E7F82',
  700: '#616263',
  800: '#444547',
  900: '#272829',
  950: '#000000',
}

// Монохромная палитра - используется для чистого белого и черного
export const monochromePalette = {
  0: '#FFFFFF', // Чистый белый
  50: '#fafafa',
  100: '#f5f5f5',
  200: '#e5e5e5',
  300: '#d4d4d4',
  400: '#a3a3a3',
  500: '#737373', // Средний серый
  600: '#525252',
  700: '#404040',
  800: '#262626',
  900: '#171717',
  950: '#0a0a0a', // Чистый черный
  white: '#FFFFFF', // Алиас для белого
  black: '#000000', // Алиас для черного
}

export const greenPalette = {
  50: '#f0fdf0',
  100: '#dcfadc',
  200: '#bff5c0',
  300: '#93eba4',
  400: '#64db7a',
  500: commonColors.success, // #A7EFAA
  600: '#45c25a',
  700: '#36a04b',
  800: '#308240',
  900: '#2b6b38',
  950: '#163d1d',
}

export const redPalette = {
  50: '#fff5f4',
  100: '#ffe8e6',
  200: '#ffd1cd',
  300: '#ffaba5',
  400: '#ff7a72',
  500: commonColors.danger, // #FF5A44
  600: '#ff3a2a',
  700: '#e61c14',
  800: '#bd1814',
  900: '#9b1917',
  950: '#550707',
}

export const orangePalette = {
  50: '#fff9f5',
  100: '#fff0e8',
  200: '#ffe0d1',
  300: '#ffc8ad',
  400: '#ffa57f',
  500: commonColors.warning, // #FFB38A
  600: '#ff855a',
  700: '#e6643a',
  800: '#bd5030',
  900: '#9b4029',
  950: '#551d12',
}

export const bluePalette = {
  50: '#f5faff',
  100: '#e8f4ff',
  200: '#cde8ff',
  300: '#a3d5ff',
  400: '#6bbaff',
  500: commonColors.info, // #8DD9FF
  600: '#4a9fff',
  700: '#2a84e6',
  800: '#246bbd',
  900: '#22589b',
  950: '#123355',
}

export const statusColors = {
  success: commonColors.success,
  danger: commonColors.danger,
  warning: commonColors.warning,
  info: commonColors.info,
}

export const colors = {
  ...commonColors,
  primary: primaryPalette,
  surface: surfacePalette,
  red: redPalette,
  orange: orangePalette,
  blue: bluePalette,
  green: greenPalette,
  monochrome: monochromePalette,
}
