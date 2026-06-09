const IFRAME_TOKEN_KEY = 'vizion:iframe_token'

export const iframeTokenStorage = {
  save(token: string): void {
    sessionStorage.setItem(IFRAME_TOKEN_KEY, token)
  },

  get(): string | null {
    return sessionStorage.getItem(IFRAME_TOKEN_KEY)
  },

  clear(): void {
    sessionStorage.removeItem(IFRAME_TOKEN_KEY)
  },
}
