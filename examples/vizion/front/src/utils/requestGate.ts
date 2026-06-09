const getNextTokenValue = (token: number): number =>
  token >= Number.MAX_SAFE_INTEGER - 1 ? 1 : token + 1

export interface RequestGate {
  next(): number
  invalidate(): number
  isCurrent(token: number): boolean
}

export const createRequestGate = (): RequestGate => {
  let currentToken = 0

  const next = (): number => {
    currentToken = getNextTokenValue(currentToken)
    return currentToken
  }

  const invalidate = (): number => {
    return next()
  }

  const isCurrent = (token: number): boolean => {
    return token === currentToken
  }

  return {
    next,
    invalidate,
    isCurrent,
  }
}
