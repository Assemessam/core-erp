import { isAxiosError } from 'axios'

export interface FormFeedback {
  message: string
  fields: Record<string, string>
}

export function isUnauthenticated(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 401
}

export function formFeedback(error: unknown): FormFeedback {
  if (!isAxiosError(error)) {
    return {
      message: 'The request could not be completed. Please try again.',
      fields: {},
    }
  }

  const status = error.response?.status
  if (status === 419) {
    return {
      message: 'Your session expired. Please submit the form again.',
      fields: {},
    }
  }
  if (status === 429) {
    return {
      message: 'Too many attempts. Please wait before trying again.',
      fields: {},
    }
  }
  if (status === 401) {
    return { message: 'Your session ended. Please sign in again.', fields: {} }
  }
  if (status === 422) {
    const data = error.response?.data as {
      message?: string
      errors?: Record<string, string[]>
    }
    const fields = Object.fromEntries(
      Object.entries(data.errors ?? {}).map(([key, value]) => [
        key,
        value[0] ?? 'Invalid value.',
      ]),
    )
    return {
      message: data.message ?? 'Please correct the highlighted fields.',
      fields,
    }
  }
  return {
    message: 'The request could not be completed. Please try again.',
    fields: {},
  }
}
