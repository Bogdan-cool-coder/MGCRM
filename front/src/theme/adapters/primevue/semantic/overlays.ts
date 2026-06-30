// NOTE (2026-06-30): Aura Dialog does NOT use semantic.dialog.* tokens.
// It exclusively reads {overlay.modal.*} which are correctly set in
// foundation.ts colorScheme.dark → overlay.modal.background = {surface.100} = #444547.
// Defining dialog.* here adds no effect and can mislead developers.
// The original `dialog.contentBackground: '{monochrome.white}'` was a dead token
// that had no visual effect but created a false impression of a dark-mode gap.
export const primeVueOverlaySemantic = {
  mask: {
    background: 'rgba(0, 0, 0, 0.5)',
    color: '{surface.900}',
  },
  sidebar: {
    background: '{surface.100}',
    borderColor: '{surface.200}',
    color: '{surface.900}',
  },
} as const
