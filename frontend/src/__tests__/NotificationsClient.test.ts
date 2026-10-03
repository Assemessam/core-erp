import { beforeEach, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import {
  notificationApi,
  notificationDestination,
  notificationTime,
  type NotificationItem,
} from '../lib/notifications'

vi.mock('../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }))
beforeEach(() => vi.resetAllMocks())

it('uses only fixed list/count/read endpoints with encoded identifiers and opaque cursor', async () => {
  const signal = new AbortController().signal
  vi.mocked(api.get)
    .mockResolvedValueOnce({ data: { data: [], meta: {} } })
    .mockResolvedValueOnce({ data: { data: { unread_count: 3 } } })
  await notificationApi.list('a/b', 'cursor+/=', signal)
  expect(api.get).toHaveBeenNthCalledWith(
    1,
    '/organizations/a%2Fb/notifications',
    { params: { per_page: 25, cursor: 'cursor+/=' }, signal },
  )
  expect(await notificationApi.unreadCount('a/b', signal)).toBe(3)
  expect(api.get).toHaveBeenNthCalledWith(
    2,
    '/organizations/a%2Fb/notifications/unread-count',
    { signal },
  )
  await notificationApi.markRead('a/b', 'id/#')
  await notificationApi.markAllRead('a/b')
  expect(vi.mocked(api.post).mock.calls).toEqual([
    ['/organizations/a%2Fb/notifications/id%2F%23/read'],
    ['/organizations/a%2Fb/notifications/read-all'],
  ])
})

it('sends a bounded first page without cursor or invented filters', async () => {
  vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })
  await notificationApi.list('alpha')
  expect(api.get).toHaveBeenCalledWith('/organizations/alpha/notifications', {
    params: { per_page: 25 },
    signal: undefined,
  })
})

it('maps only the current known format and exact users target and handles UTC time', () => {
  const row = {
    type: 'organization.invitation_accepted',
    payload_version: 1,
    target: { type: 'organization.users', id: null },
  } as NotificationItem
  expect(notificationDestination(row, 'alpha')).toEqual({
    name: 'organization-users',
    params: { organizationId: 'alpha' },
  })
  for (const target of [
    null,
    'https://evil.test',
    {},
    { type: 'organization.users' },
    { type: 'organization.users', id: '1' },
    { type: 'organization.users', id: null, url: '/evil' },
    { type: 'https://evil.test', id: null },
  ]) {
    expect(
      notificationDestination({ ...row, target } as NotificationItem, 'alpha'),
    ).toBeNull()
  }
  expect(
    notificationDestination({ ...row, payload_version: 2 }, 'alpha'),
  ).toBeNull()
  expect(
    notificationDestination({ ...row, type: 'future.notice' }, 'alpha'),
  ).toBeNull()
  expect(notificationTime('2026-10-03T15:00:00.123456Z')).toBe(
    notificationTime('2026-10-03T18:00:00.123456+03:00'),
  )
  expect(notificationTime('2026-10-03T15:00:00.123456Z')).toContain('UTC')
  expect(notificationTime('invalid')).toBe('Time unavailable')
})
