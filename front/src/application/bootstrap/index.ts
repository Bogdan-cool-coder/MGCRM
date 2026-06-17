export { bootstrapApp } from './bootstrapApp'

// Bootstrap session promise — используется в router.beforeEach
let _bootstrapPromise: Promise<void> | null = null

export function setBootstrapSessionPromise(promise: Promise<void>): void {
  _bootstrapPromise = promise
}

export async function waitForBootstrapSession(): Promise<void> {
  if (_bootstrapPromise) {
    await _bootstrapPromise
  }
}
