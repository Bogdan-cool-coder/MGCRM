declare module 'msw' {
  export interface MockResolverArgs {
    request: Request
    params: Record<string, string>
  }

  export interface HttpResponseInit {
    status?: number
  }

  export class HttpResponse extends Response {
    static json<T>(body?: T, init?: HttpResponseInit): HttpResponse
  }

  export function delay(duration?: number): Promise<void>

  export const http: {
    get(path: string, resolver: (args: MockResolverArgs) => unknown): unknown
    post(path: string, resolver: (args: MockResolverArgs) => unknown): unknown
    put(path: string, resolver: (args: MockResolverArgs) => unknown): unknown
    delete(path: string, resolver: (args: MockResolverArgs) => unknown): unknown
  }
}

declare module 'msw/browser' {
  export interface SetupWorker {
    start(options?: { onUnhandledRequest?: 'bypass' | 'warn' | 'error' }): Promise<void>
  }

  export function setupWorker(...handlers: unknown[]): SetupWorker
}
