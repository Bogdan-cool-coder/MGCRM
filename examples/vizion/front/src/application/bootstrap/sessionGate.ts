let bootstrapSessionPromise: Promise<void> | null = null

export const setBootstrapSessionPromise = (promise: Promise<void>) => {
  bootstrapSessionPromise = promise
}

export const waitForBootstrapSession = async (): Promise<void> => {
  if (!bootstrapSessionPromise) {
    return
  }

  await bootstrapSessionPromise
}
