export interface ToolboxTooltipOptions {
  value: string
  position: 'top'
  showDelay: number
  hideDelay: number
}

export function useToolboxTooltip() {
  const tooltipOptions = (value: string): ToolboxTooltipOptions => ({
    value,
    position: 'top',
    showDelay: 150,
    hideDelay: 0,
  })

  return {
    tooltipOptions,
  }
}
