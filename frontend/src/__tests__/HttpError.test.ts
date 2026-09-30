import { expect, it } from 'vitest'
import { formFeedback } from '../lib/httpError'

it('gives distinct, non-retrying guidance for expired CSRF and rate limits', () => {
  expect(
    formFeedback({ isAxiosError: true, response: { status: 419 } }).message,
  ).toContain('session expired')
  expect(
    formFeedback({ isAxiosError: true, response: { status: 429 } }).message,
  ).toContain('Too many attempts')
})
