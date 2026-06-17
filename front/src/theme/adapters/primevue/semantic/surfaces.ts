// ВАЖНО: все значения внутри этого объекта обрабатываются PrimeVue через
// colorScheme.light/dark, поэтому {surface.X} здесь — это ДИНАМИЧЕСКИЕ ссылки,
// которые инвертируются в dark-режиме (surface.0 = #fff light / #000 dark → корректно).
// '{monochrome.white}' — СТАТИЧЕСКИЙ белый, не реагирует на тему → заменён на {surface.0}.
export const primeVueSurfaceSemantic = {
  content: {
    background: '{surface.100}',
    hoverBackground: '{surface.200}',
    borderColor: '{surface.200}',
    color: '{surface.900}',
  },
  card: {
    background: '{surface.0}',
    borderColor: '{surface.200}',
    color: '{surface.900}',
  },
  overlay: {
    background: '{surface.100}',
    borderColor: '{surface.200}',
    color: '{surface.900}',
  },
} as const
