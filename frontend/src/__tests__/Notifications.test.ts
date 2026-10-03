import { computed, defineComponent, provide } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, useRoute } from 'vue-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import {
  notificationApi,
  type NotificationItem,
  type NotificationPage,
} from '../lib/notifications'
import { organizationApi } from '../lib/organizations'
import {
  notificationScopeKey,
  useOrganizationNotifications,
} from '../composables/useOrganizationNotifications'
import NotificationBell from '../components/NotificationBell.vue'
import OrganizationNotificationsView from '../views/OrganizationNotificationsView.vue'

vi.mock('../lib/notifications', async (original) => ({
  ...(await original<typeof import('../lib/notifications')>()),
  notificationApi: {
    list: vi.fn(),
    unreadCount: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
  },
}))
vi.mock('../lib/organizations', () => ({ organizationApi: { get: vi.fn() } }))
const item: NotificationItem = {
  id: 'one',
  type: 'organization.invitation_accepted',
  payload_version: 1,
  title: 'Invitation accepted',
  body: 'User #29 accepted an invitation and joined the organization.',
  target: { type: 'organization.users', id: null },
  read_at: null,
  created_at: '2026-10-03T15:00:00.123456Z',
}
const page = (
  data = [item],
  cursor: string | null = null,
): NotificationPage => ({
  data,
  meta: { next_cursor: cursor, has_more: cursor !== null, per_page: 25 },
})
const failure = (status: number) => ({
  isAxiosError: true,
  response: { status },
})
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
const wrappers: VueWrapper[] = []
async function setup(path = '/app/organizations/alpha/notifications') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/app/organizations/:organizationId/notifications',
        name: 'organization-notifications',
        component: OrganizationNotificationsView,
      },
      {
        path: '/app/organizations/:organizationId/users',
        name: 'organization-users',
        component: { template: '<p>Users</p>' },
      },
      {
        path: '/app/organizations/:organizationId',
        name: 'organization',
        component: { template: '<p>Workspace</p>' },
      },
      {
        path: '/app/organizations',
        name: 'organizations',
        component: { template: '<p>Organizations</p>' },
      },
    ],
  })
  const host = defineComponent({
    components: { NotificationBell },
    setup() {
      const route = useRoute()
      provide(
        notificationScopeKey,
        useOrganizationNotifications(
          computed(() =>
            typeof route.params.organizationId === 'string'
              ? route.params.organizationId
              : null,
          ),
        ),
      )
    },
    template: '<NotificationBell /><RouterView />',
  })
  await router.push(path)
  const wrapper = mount(host, { global: { plugins: [router] } })
  wrappers.push(wrapper)
  return { wrapper, router }
}
const button = (wrapper: VueWrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)!
const bell = (wrapper: VueWrapper) =>
  wrapper.find('button[aria-label^="Notifications"]')

beforeEach(() => {
  vi.resetAllMocks()
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
  vi.mocked(notificationApi.list).mockResolvedValue(page())
  vi.mocked(notificationApi.unreadCount).mockResolvedValue(1)
  vi.mocked(notificationApi.markRead).mockResolvedValue()
  vi.mocked(notificationApi.markAllRead).mockResolvedValue()
  vi.mocked(organizationApi.get).mockImplementation(async (id) => ({
    id,
    name: `Organization ${id}`,
  }))
})
afterEach(() => {
  wrappers.splice(0).forEach((w) => w.unmount())
  vi.useRealTimers()
})

it('loads the first page without automatic marking and shows accessible unread state UTC and safe View', async () => {
  const pending = deferred<NotificationPage>()
  vi.mocked(notificationApi.list).mockReturnValueOnce(pending.promise)
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.text()).toContain('Loading notifications…')
  pending.resolve(page())
  await flushPromises()
  expect(wrapper.text()).toContain('Organization alpha')
  expect(wrapper.text()).toContain('Unread')
  expect(bell(wrapper).attributes('aria-label')).toBe('Notifications, 1 unread')
  expect(wrapper.get('time').attributes('datetime')).toBe(item.created_at)
  expect(wrapper.get('time').attributes('title')).toBe(item.created_at)
  expect(wrapper.get('time').text()).toContain('UTC')
  expect(
    wrapper.get('a[aria-label="View Invitation accepted"]').attributes('href'),
  ).toBe('/app/organizations/alpha/users')
  expect(wrapper.text()).toContain('End of notification history.')
  expect(notificationApi.markRead).not.toHaveBeenCalled()
  expect(notificationApi.markAllRead).not.toHaveBeenCalled()
})

it.each([0, 4, 120])(
  'shows count %s without a zero badge and with an exact accessible label',
  async (count) => {
    vi.mocked(notificationApi.unreadCount).mockResolvedValue(count)
    const { wrapper } = await setup('/app/organizations/alpha')
    await flushPromises()
    expect(bell(wrapper).attributes('aria-label')).toBe(
      `Notifications, ${count} unread`,
    )
    expect(bell(wrapper).text()).toBe(
      count === 0
        ? 'Notifications'
        : `Notifications${count > 99 ? '99+' : count}`,
    )
    expect(notificationApi.list).not.toHaveBeenCalled()
  },
)

it('opens the current organization center through the native bell button', async () => {
  const { wrapper, router } = await setup('/app/organizations/alpha')
  await flushPromises()
  expect(bell(wrapper).attributes('type')).toBe('button')
  await bell(wrapper).trigger('click')
  await flushPromises()
  expect(router.currentRoute.value.fullPath).toBe(
    '/app/organizations/alpha/notifications',
  )
  expect(notificationApi.list).toHaveBeenCalledWith(
    'alpha',
    undefined,
    expect.any(AbortSignal),
  )
})

it('shows empty and initial error states and Refresh retries the first page and count', async () => {
  vi.mocked(notificationApi.list)
    .mockRejectedValueOnce(new Error('offline'))
    .mockResolvedValueOnce(page([]))
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.get('[role="alert"]').text()).toContain(
    'could not be completed',
  )
  await button(wrapper, 'Refresh').trigger('click')
  await flushPromises()
  expect(wrapper.text()).toContain('No notifications yet.')
  expect(notificationApi.list).toHaveBeenLastCalledWith(
    'alpha',
    undefined,
    expect.any(AbortSignal),
  )
  expect(notificationApi.unreadCount).toHaveBeenCalledTimes(2)
})

it('marks one explicitly without fabricating time or reordering and authoritatively refreshes shared count', async () => {
  vi.mocked(notificationApi.list).mockResolvedValue(
    page([item, { ...item, id: 'two', read_at: '2026-10-03T15:01:00Z' }]),
  )
  const pending = deferred<void>()
  vi.mocked(notificationApi.markRead).mockReturnValueOnce(pending.promise)
  vi.mocked(notificationApi.unreadCount)
    .mockResolvedValueOnce(1)
    .mockResolvedValueOnce(0)
  const { wrapper } = await setup()
  await flushPromises()
  await button(wrapper, 'Mark as read').trigger('click')
  expect(
    button(wrapper, 'Marking as read…').attributes('disabled'),
  ).toBeDefined()
  pending.resolve()
  await flushPromises()
  expect(notificationApi.markRead).toHaveBeenCalledOnce()
  expect(notificationApi.markRead).toHaveBeenCalledWith('alpha', 'one')
  expect(
    wrapper
      .findAll('ol li')
      .map((row) => row.attributes('data-notification-id')),
  ).toEqual(['one', 'two'])
  expect(
    wrapper
      .findAll('ol article')
      .every(
        (row) => row.text().includes('Read') && !row.text().includes('Unread'),
      ),
  ).toBe(true)
  expect(bell(wrapper).attributes('aria-label')).toBe('Notifications, 0 unread')
  expect(notificationApi.list).toHaveBeenCalledTimes(1)
  expect(item.read_at).toBeNull()
})

it('mark-all refetches the newest page and count allowing a later committed message to remain unread', async () => {
  vi.mocked(notificationApi.list)
    .mockResolvedValueOnce(page([item], 'old'))
    .mockResolvedValueOnce(page([{ ...item, id: 'new' }]))
  vi.mocked(notificationApi.unreadCount)
    .mockResolvedValueOnce(2)
    .mockResolvedValueOnce(1)
  const { wrapper } = await setup()
  await flushPromises()
  await button(wrapper, 'Mark all as read').trigger('click')
  await flushPromises()
  expect(notificationApi.markAllRead).toHaveBeenCalledWith('alpha')
  expect(wrapper.find('[data-notification-id="one"]').exists()).toBe(false)
  expect(wrapper.get('[data-notification-id="new"]').text()).toContain('Unread')
  expect(bell(wrapper).attributes('aria-label')).toBe('Notifications, 1 unread')
  expect(button(wrapper, 'Load older')).toBeUndefined()
})

it('appends opaque continuation in order once despite double click and duplicates then resets on Refresh', async () => {
  const pending = deferred<NotificationPage>()
  vi.mocked(notificationApi.list)
    .mockResolvedValueOnce(page([item], 'opaque+/='))
    .mockReturnValueOnce(pending.promise)
    .mockResolvedValueOnce(page([]))
  const { wrapper } = await setup()
  await flushPromises()
  await button(wrapper, 'Load older').trigger('click')
  await button(wrapper, 'Load older').trigger('click')
  expect(notificationApi.list).toHaveBeenCalledTimes(2)
  expect(notificationApi.list).toHaveBeenLastCalledWith('alpha', 'opaque+/=')
  pending.resolve(page([item, { ...item, id: 'two' }]))
  await flushPromises()
  expect(
    wrapper
      .findAll('ol li')
      .map((row) => row.attributes('data-notification-id')),
  ).toEqual(['one', 'two'])
  expect(button(wrapper, 'Load older')).toBeUndefined()
  await button(wrapper, 'Refresh').trigger('click')
  await flushPromises()
  expect(wrapper.find('ol').exists()).toBe(false)
  expect(notificationApi.list).toHaveBeenLastCalledWith(
    'alpha',
    undefined,
    expect.any(AbortSignal),
  )
})

it('retains rows and continuation for a failed older-page retry and distinguishes mutation errors', async () => {
  vi.mocked(notificationApi.list)
    .mockResolvedValueOnce(page([item], 'retry'))
    .mockRejectedValueOnce(new Error('offline'))
    .mockResolvedValueOnce(page([{ ...item, id: 'two' }]))
  const { wrapper } = await setup()
  await flushPromises()
  await button(wrapper, 'Load older').trigger('click')
  await flushPromises()
  expect(wrapper.text()).toContain('Older notifications could not be loaded.')
  expect(wrapper.findAll('ol li')).toHaveLength(1)
  await button(wrapper, 'Load older').trigger('click')
  await flushPromises()
  vi.mocked(notificationApi.markRead).mockRejectedValueOnce(
    new Error('offline'),
  )
  await button(wrapper, 'Mark as read').trigger('click')
  await flushPromises()
  expect(wrapper.text()).toContain('Read state could not be updated.')
  expect(wrapper.findAll('ol li')).toHaveLength(2)
})

it.each([401, 403, 404])(
  'clears private rows count cursor and polling after current-scope denial %s',
  async (status) => {
    vi.useFakeTimers()
    vi.mocked(notificationApi.list).mockResolvedValue(page([item], 'older'))
    const { wrapper } = await setup()
    await flushPromises()
    vi.mocked(notificationApi.unreadCount).mockRejectedValueOnce(
      failure(status),
    )
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(wrapper.find('ol').exists()).toBe(false)
    expect(bell(wrapper).exists()).toBe(false)
    expect(button(wrapper, 'Load older')).toBeUndefined()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    const calls = vi.mocked(notificationApi.unreadCount).mock.calls.length
    await vi.advanceTimersByTimeAsync(120_000)
    expect(notificationApi.unreadCount).toHaveBeenCalledTimes(calls)
    await button(wrapper, 'Refresh').trigger('click')
    await flushPromises()
    expect(wrapper.find('ol').exists()).toBe(true)
  },
)

it('retains valid history and count on a generic background count failure', async () => {
  const { wrapper } = await setup()
  await flushPromises()
  vi.mocked(notificationApi.unreadCount).mockRejectedValueOnce(failure(503))
  window.dispatchEvent(new Event('focus'))
  await flushPromises()
  expect(wrapper.findAll('ol li')).toHaveLength(1)
  expect(bell(wrapper).attributes('aria-label')).toBe('Notifications, 1 unread')
  expect(wrapper.text()).toContain('Unread count could not be refreshed.')
})

it.each(['list', 'one', 'all'])(
  'clears rows and shared badge on current %s operation access loss',
  async (operation) => {
    for (const status of [401, 403, 404]) {
      vi.mocked(notificationApi.list).mockResolvedValue(page([item], 'older'))
      const { wrapper } = await setup()
      await flushPromises()
      if (operation === 'list') {
        vi.mocked(notificationApi.list).mockRejectedValueOnce(failure(status))
        await button(wrapper, 'Load older').trigger('click')
      } else if (operation === 'one') {
        vi.mocked(notificationApi.markRead).mockRejectedValueOnce(
          failure(status),
        )
        await button(wrapper, 'Mark as read').trigger('click')
      } else {
        vi.mocked(notificationApi.markAllRead).mockRejectedValueOnce(
          failure(status),
        )
        await button(wrapper, 'Mark all as read').trigger('click')
      }
      await flushPromises()
      expect(wrapper.find('ol').exists()).toBe(false)
      expect(bell(wrapper).exists()).toBe(false)
      expect(button(wrapper, 'Load older')).toBeUndefined()
      expect(wrapper.find('[role="alert"]').exists()).toBe(true)
      wrapper.unmount()
      wrappers.splice(wrappers.indexOf(wrapper), 1)
    }
  },
)

it.each(['success', 'failure'])(
  'ignores previous-tenant list/count %s and immediately clears rows cursors and errors',
  async (outcome) => {
    const late = deferred<NotificationPage>()
    const count = deferred<number>()
    vi.mocked(notificationApi.list)
      .mockReturnValueOnce(late.promise)
      .mockResolvedValueOnce(
        page([{ ...item, id: 'beta', title: 'Beta only' }]),
      )
    vi.mocked(notificationApi.unreadCount)
      .mockReturnValueOnce(count.promise)
      .mockResolvedValueOnce(4)
    const { wrapper, router } = await setup()
    await flushPromises()
    await router.push('/app/organizations/beta/notifications')
    await flushPromises()
    if (outcome === 'success') {
      late.resolve(page())
      count.resolve(99)
    } else {
      late.reject(failure(404))
      count.reject(failure(403))
    }
    await flushPromises()
    expect(wrapper.text()).toContain('Beta only')
    expect(wrapper.find('[data-notification-id="one"]').exists()).toBe(false)
    expect(bell(wrapper).attributes('aria-label')).toBe(
      'Notifications, 4 unread',
    )
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  },
)

it('ignores old refresh list and count even when they complete after the newer refresh', async () => {
  const oldList = deferred<NotificationPage>()
  const oldCount = deferred<number>()
  vi.mocked(notificationApi.list)
    .mockReturnValueOnce(oldList.promise)
    .mockResolvedValueOnce(page([{ ...item, id: 'newer' }]))
  vi.mocked(notificationApi.unreadCount)
    .mockReturnValueOnce(oldCount.promise)
    .mockResolvedValueOnce(5)
  const { wrapper } = await setup()
  await flushPromises()
  await button(wrapper, 'Refresh').trigger('click')
  await flushPromises()
  oldList.resolve(page())
  oldCount.resolve(90)
  await flushPromises()
  expect(wrapper.find('[data-notification-id="one"]').exists()).toBe(false)
  expect(wrapper.find('[data-notification-id="newer"]').exists()).toBe(true)
  expect(bell(wrapper).attributes('aria-label')).toBe('Notifications, 5 unread')
  expect(
    vi.mocked(notificationApi.unreadCount).mock.calls[0]![1]!.aborted,
  ).toBe(true)
})

it.each(['one', 'all', 'older'])(
  'ignores stale %s success and failure after tenant switch',
  async (operation) => {
    for (const reject of [false, true]) {
      const late = deferred<NotificationPage | void>()
      vi.mocked(notificationApi.list).mockResolvedValue(
        page([item], 'old-cursor'),
      )
      const { wrapper, router } = await setup()
      await flushPromises()
      if (operation === 'older')
        vi.mocked(notificationApi.list).mockReturnValueOnce(
          late.promise as Promise<NotificationPage>,
        )
      else if (operation === 'one')
        vi.mocked(notificationApi.markRead).mockReturnValueOnce(
          late.promise as Promise<void>,
        )
      else
        vi.mocked(notificationApi.markAllRead).mockReturnValueOnce(
          late.promise as Promise<void>,
        )
      await button(
        wrapper,
        operation === 'older'
          ? 'Load older'
          : operation === 'one'
            ? 'Mark as read'
            : 'Mark all as read',
      ).trigger('click')
      vi.mocked(notificationApi.list).mockResolvedValueOnce(
        page([{ ...item, id: 'beta' }]),
      )
      await router.push('/app/organizations/beta/notifications')
      await flushPromises()
      if (reject) late.reject(failure(404))
      else late.resolve(page())
      await flushPromises()
      expect(wrapper.find('[data-notification-id="beta"]').text()).toContain(
        'Unread',
      )
      expect(wrapper.find('[data-notification-id="one"]').exists()).toBe(false)
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
      wrapper.unmount()
      wrappers.splice(wrappers.indexOf(wrapper), 1)
    }
  },
)

it('escapes HTML-like snapshots and renders unknown formats without navigation', async () => {
  const html = '<script>alert(1)</script>'
  vi.mocked(notificationApi.list).mockResolvedValue(
    page([
      { ...item, title: html, body: '<img src=x onerror=alert(1)>' },
      {
        ...item,
        id: 'future',
        type: 'future.type',
        payload_version: 2,
        title: 'Future safe text',
      },
      { ...item, id: 'url', target: { type: 'https://evil.test', id: null } },
    ]),
  )
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.text()).toContain(html)
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.find('img').exists()).toBe(false)
  expect(wrapper.text()).toContain('Unsupported notification format')
  expect(wrapper.findAll('ol a')).toHaveLength(1)
  expect(wrapper.find('a[href^="https://evil"]').exists()).toBe(false)
})

it('polls only visible scoped workspaces prevents overlaps and cleans timers listeners on exit', async () => {
  vi.useFakeTimers()
  const { wrapper, router } = await setup('/app/organizations/alpha')
  await flushPromises()
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
  await vi.advanceTimersByTimeAsync(60_000)
  window.dispatchEvent(new Event('focus'))
  await flushPromises()
  expect(notificationApi.unreadCount).toHaveBeenCalledTimes(1)
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
  const pending = deferred<number>()
  vi.mocked(notificationApi.unreadCount).mockReturnValueOnce(pending.promise)
  document.dispatchEvent(new Event('visibilitychange'))
  await vi.advanceTimersByTimeAsync(120_000)
  window.dispatchEvent(new Event('focus'))
  expect(notificationApi.unreadCount).toHaveBeenCalledTimes(2)
  pending.resolve(2)
  await flushPromises()
  await vi.advanceTimersByTimeAsync(60_000)
  expect(notificationApi.unreadCount).toHaveBeenCalledTimes(3)
  await router.push('/app/organizations')
  await flushPromises()
  expect(bell(wrapper).exists()).toBe(false)
  await vi.advanceTimersByTimeAsync(120_000)
  window.dispatchEvent(new Event('focus'))
  expect(notificationApi.unreadCount).toHaveBeenCalledTimes(3)
  wrapper.unmount()
  wrappers.splice(wrappers.indexOf(wrapper), 1)
  await vi.advanceTimersByTimeAsync(60_000)
  expect(vi.getTimerCount()).toBe(0)
})

it('waits for visibility on background entry and starts no new history request during shell unmount', async () => {
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
  const { wrapper } = await setup()
  await flushPromises()
  expect(notificationApi.unreadCount).not.toHaveBeenCalled()
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
  document.dispatchEvent(new Event('visibilitychange'))
  await flushPromises()
  expect(notificationApi.unreadCount).toHaveBeenCalledOnce()
  expect(notificationApi.list).toHaveBeenCalledOnce()
  wrapper.unmount()
  wrappers.splice(wrappers.indexOf(wrapper), 1)
  await flushPromises()
  window.dispatchEvent(new Event('focus'))
  expect(notificationApi.list).toHaveBeenCalledOnce()
  expect(notificationApi.unreadCount).toHaveBeenCalledOnce()
})
