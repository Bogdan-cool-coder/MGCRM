// Motion / анимация токены (из брендбука §4.4)
export const motion = {
  duration: {
    fast: '0.2s',
    normal: '0.3s',
    slow: '0.5s',
  },
  easing: {
    standard: 'ease-in-out',
    enter: 'ease-out',
    exit: 'ease-in',
  },
  transition: {
    fast: '0.2s ease-in-out',
    normal: '0.3s ease-in-out',
  },
} as const
