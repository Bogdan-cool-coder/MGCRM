export interface OrbitaTooltipOptions {
  value: string
  position: 'top'
  showDelay: number
  hideDelay: number
}

export function useOrbitaTooltip() {
  const tooltipOptions = (value: string): OrbitaTooltipOptions => ({
    value,
    position: 'top',
    showDelay: 150,
    hideDelay: 0,
  })

  return {
    tooltipOptions,
  }
}
