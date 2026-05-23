import { ApiError } from './api'

const GENERIC_SERVER_MESSAGES = new Set([
  'Server Error',
  'Internal Server Error',
  'Ошибка запроса',
  'Неизвестная ошибка',
])

export function parseApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status >= 500 && GENERIC_SERVER_MESSAGES.has(error.message)) {
    return fallback
  }
  if (error instanceof Error) {
    return error.message || fallback
  }
  return fallback
}
