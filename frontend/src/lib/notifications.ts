import { isAxiosError } from 'axios'
import { api } from './api'

export interface NotificationItem {
  id: string
  type: string
  payload_version: number
  title: string
  body: string
  target: { type: string; id: string | null } | null
  read_at: string | null
  created_at: string
}

export interface NotificationPage {
  data: NotificationItem[]
  meta: { next_cursor: string | null; has_more: boolean; per_page: number }
}

const collection = (id: string) =>
  `/organizations/${encodeURIComponent(id)}/notifications`

export const notificationApi = {
  async list(
    id: string,
    cursor?: string,
    signal?: AbortSignal,
  ): Promise<NotificationPage> {
    return (
      await api.get<NotificationPage>(collection(id), {
        params: { per_page: 25, ...(cursor ? { cursor } : {}) },
        signal,
      })
    ).data
  },
  async unreadCount(id: string, signal?: AbortSignal): Promise<number> {
    return (
      await api.get<{ data: { unread_count: number } }>(
        `${collection(id)}/unread-count`,
        { signal },
      )
    ).data.data.unread_count
  },
  async markRead(id: string, notificationId: string): Promise<void> {
    await api.post(
      `${collection(id)}/${encodeURIComponent(notificationId)}/read`,
    )
  },
  async markAllRead(id: string): Promise<void> {
    await api.post(`${collection(id)}/read-all`)
  },
}

export function notificationAccessLost(error: unknown): boolean {
  return (
    isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)
  )
}

export function supportedNotification(item: NotificationItem): boolean {
  return (
    item.type === 'organization.invitation_accepted' &&
    item.payload_version === 1
  )
}

export function notificationDestination(
  item: NotificationItem,
  organizationId: string,
) {
  // Never interpret a target string as a URL or route name, including malformed future data.
  const target = item.target
  if (
    !supportedNotification(item) ||
    !target ||
    typeof target !== 'object' ||
    target.type !== 'organization.users' ||
    target.id !== null ||
    Object.keys(target).length !== 2
  )
    return null
  return { name: 'organization-users', params: { organizationId } }
}

export function notificationTime(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Time unavailable'
    : new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: 'UTC',
      }).format(date) + ' UTC'
}
