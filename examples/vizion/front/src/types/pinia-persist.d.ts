import 'pinia'

declare module 'pinia' {
  export interface PersistOptions<S> {
    key?: string
    storage?: Storage
    paths?: Array<keyof S & string>
    serialize?: (state: Partial<S>) => string
    deserialize?: (value: string) => unknown
  }

  export interface DefineStoreOptionsBase<S, Store> {
    persist?: boolean | PersistOptions<S>
  }

  export interface DefineSetupStoreOptions<Id, S, G, A> {
    persist?: boolean | PersistOptions<S>
  }
}

export {}
