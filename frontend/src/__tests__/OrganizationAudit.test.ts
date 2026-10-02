import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, expect, it, vi } from 'vitest'
import {
  auditApi,
  auditChanges,
  actorLabel,
  auditTime,
  type AuditEvent,
  type AuditPage,
} from '../lib/audit'
import { organizationApi } from '../lib/organizations'
import OrganizationAuditView from '../views/OrganizationAuditView.vue'

vi.mock('../lib/audit', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/audit')>()),
  auditApi: { list: vi.fn() },
}))
vi.mock('../lib/organizations', () => ({
  organizationApi: { context: vi.fn() },
}))
const event: AuditEvent = {
  id: 'event-1',
  action: 'organization.renamed',
  actor: { type: 'user', id: 12 },
  subject: { type: 'organization', id: 'alpha' },
  changes: { before: { name: 'Before' }, after: { name: 'After' } },
  payload_version: 1,
  created_at: '2026-10-01T12:34:56.123456Z',
}
const page = (
  events: AuditEvent[] = [event],
  cursor: string | null = null,
): AuditPage => ({
  data: events,
  meta: { next_cursor: cursor, has_more: cursor !== null, per_page: 25 },
})
async function setup() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/app/organizations/:organizationId/audit',
        component: OrganizationAuditView,
      },
      {
        path: '/app/organizations/:organizationId',
        name: 'organization',
        component: { template: '<p>Workspace</p>' },
      },
      {
        path: '/login',
        name: 'login',
        component: { template: '<p>Login</p>' },
      },
    ],
  })
  await router.push('/app/organizations/alpha/audit')
  const wrapper = mount(OrganizationAuditView, {
    global: { plugins: [router] },
  })
  return { wrapper, router }
}
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (value: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
const failure = (status: number) => ({
  isAxiosError: true,
  response: {
    status,
    data: { errors: { subject_id: ['Invalid subject ID.'] } },
  },
})
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(organizationApi.context).mockImplementation(async (id) => ({
    data: { id, name: `Organization ${id}` },
    meta: { can_view_audit: true },
  }))
  vi.mocked(auditApi.list).mockResolvedValue(page())
})

it('loads tenant context and renders stable IDs UTC time and before/after values', async () => {
  const pending = deferred<AuditPage>()
  vi.mocked(auditApi.list).mockReturnValueOnce(pending.promise)
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.get('[role="status"]').text()).toBe('Loading audit history…')
  pending.resolve(page())
  await flushPromises()
  expect(auditApi.list).toHaveBeenCalledWith('alpha', {}, undefined)
  expect(wrapper.get('a').attributes('href')).toBe('/app/organizations/alpha')
  expect(wrapper.get('ol').text()).toContain('Organization renamed')
  expect(wrapper.get('ol').text()).toContain('User #12')
  expect(wrapper.get('ol').text()).toContain('organization #alpha')
  expect(wrapper.get('time').attributes('datetime')).toBe(event.created_at)
  expect(wrapper.get('time').text()).toContain('UTC')
  expect(wrapper.get('table').text()).toContain('Before')
  expect(wrapper.get('table').text()).toContain('After')
  expect(wrapper.text()).toContain('End of audit history.')
  wrapper.unmount()
})

it('uses opaque cursors and appends pages without a count or page-number model', async () => {
  vi.mocked(auditApi.list)
    .mockResolvedValueOnce(page([event], 'opaque-A'))
    .mockResolvedValueOnce(page([{ ...event, id: 'event-2' }]))
  const { wrapper } = await setup()
  await flushPromises()
  await wrapper
    .findAll('button')
    .find((b) => b.text() === 'Load more')!
    .trigger('click')
  await flushPromises()
  expect(auditApi.list).toHaveBeenLastCalledWith('alpha', {}, 'opaque-A')
  expect(wrapper.findAll('ol > li')).toHaveLength(2)
  expect(wrapper.findAll('button').some((b) => b.text() === 'Load more')).toBe(
    false,
  )
  wrapper.unmount()
})

it('applies paired subject/action filters and refreshes from the first page', async () => {
  vi.mocked(auditApi.list).mockResolvedValue(page([event], 'old-cursor'))
  const { wrapper } = await setup()
  await flushPromises()
  const selects = wrapper.findAll('select')
  await selects[0]!.setValue('role.updated')
  await selects[1]!.setValue('role')
  await wrapper.get('input').setValue('01AAAAAAAAAAAAAAAAAAAAAAAA')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  const filters = {
    action: 'role.updated',
    subject_type: 'role',
    subject_id: '01AAAAAAAAAAAAAAAAAAAAAAAA',
  }
  expect(auditApi.list).toHaveBeenLastCalledWith('alpha', filters, undefined)
  await wrapper
    .findAll('button')
    .find((b) => b.text() === 'Refresh')!
    .trigger('click')
  await flushPromises()
  expect(auditApi.list).toHaveBeenLastCalledWith('alpha', filters, undefined)
  await wrapper
    .findAll('button')
    .find((b) => b.text() === 'Clear filters')!
    .trigger('click')
  await flushPromises()
  expect(auditApi.list).toHaveBeenLastCalledWith('alpha', {}, undefined)
  wrapper.unmount()
})

it('requires both subject inputs and renders authorized server validation errors', async () => {
  const { wrapper } = await setup()
  await flushPromises()
  await wrapper.findAll('select')[1]!.setValue('role')
  await wrapper.get('form').trigger('submit')
  expect(wrapper.text()).toContain('Choose a subject type and ID together.')
  expect(auditApi.list).toHaveBeenCalledTimes(1)
  await wrapper.get('input').setValue('invalid')
  vi.mocked(auditApi.list).mockRejectedValueOnce(failure(422))
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.text()).toContain('Invalid subject ID.')
  expect(wrapper.find('ol').exists()).toBe(false)
  wrapper.unmount()
})

it('shows an empty filtered history', async () => {
  vi.mocked(auditApi.list).mockResolvedValue(page([]))
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.text()).toContain('No audit events match these filters.')
  wrapper.unmount()
})

it('does not request history when current capability is absent', async () => {
  vi.mocked(organizationApi.context).mockResolvedValue({
    data: { id: 'alpha', name: 'Alpha' },
    meta: { can_view_audit: false },
  })
  const { wrapper } = await setup()
  await flushPromises()
  expect(auditApi.list).not.toHaveBeenCalled()
  expect(wrapper.text()).toContain('do not have permission')
  wrapper.unmount()
})

it.each([401, 403, 404])(
  'clears loaded history on server denial %s despite a previously allowed capability',
  async (status) => {
    vi.mocked(auditApi.list)
      .mockResolvedValueOnce(page([event], 'next'))
      .mockRejectedValueOnce(failure(status))
    const { wrapper } = await setup()
    await flushPromises()
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Load more')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('ol').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Before')
    expect(wrapper.text()).toContain(
      status === 401
        ? 'session ended'
        : status === 403
          ? 'do not have permission'
          : 'could not be found or accessed',
    )
    if (status === 401)
      expect(wrapper.get('a[href^="/login"]').text()).toBe('Sign in again')
    if (status === 404)
      expect(wrapper.text()).not.toContain('Organization alpha')
    wrapper.unmount()
  },
)

it('retains a failed continuation position for retry after a network error', async () => {
  vi.mocked(auditApi.list)
    .mockResolvedValueOnce(page([event], 'next'))
    .mockRejectedValueOnce(new Error('offline'))
    .mockResolvedValueOnce(page([{ ...event, id: 'retry-event' }]))
  const { wrapper } = await setup()
  await flushPromises()
  const more = () =>
    wrapper.findAll('button').find((b) => b.text() === 'Load more')!
  await more().trigger('click')
  await flushPromises()
  expect(wrapper.findAll('ol > li')).toHaveLength(1)
  expect(wrapper.get('[role="alert"]').text()).toContain(
    'could not be completed',
  )
  await more().trigger('click')
  await flushPromises()
  expect(auditApi.list).toHaveBeenLastCalledWith('alpha', {}, 'next')
  expect(wrapper.findAll('ol > li')).toHaveLength(2)
  wrapper.unmount()
})

it('clears context immediately and ignores previous-tenant responses', async () => {
  const late = deferred<AuditPage>()
  vi.mocked(auditApi.list)
    .mockReturnValueOnce(late.promise)
    .mockResolvedValueOnce(
      page([
        {
          ...event,
          id: 'beta-event',
          subject: { type: 'organization', id: 'beta' },
          changes: { before: null, after: { name: 'Beta detail' } },
        },
      ]),
    )
  const { wrapper, router } = await setup()
  await flushPromises()
  await router.push('/app/organizations/beta/audit')
  await flushPromises()
  late.resolve(page())
  await flushPromises()
  expect(auditApi.list).toHaveBeenLastCalledWith('beta', {}, undefined)
  expect(wrapper.text()).toContain('Beta detail')
  expect(wrapper.findAll('td').map((cell) => cell.text())).not.toContain(
    'Before',
  )
  expect(wrapper.find('[data-event-id="event-1"]').exists()).toBe(false)
  wrapper.unmount()
})

it('ignores a late continuation denial after switching tenants', async () => {
  const late = deferred<AuditPage>()
  vi.mocked(auditApi.list)
    .mockResolvedValueOnce(page([event], 'next'))
    .mockReturnValueOnce(late.promise)
    .mockResolvedValueOnce(page([]))
  const { wrapper, router } = await setup()
  await flushPromises()
  await wrapper
    .findAll('button')
    .find((b) => b.text() === 'Load more')!
    .trigger('click')
  // Route changes remain possible while form controls are disabled.
  await router.push('/app/organizations/beta/audit')
  await flushPromises()
  late.reject(failure(403))
  await flushPromises()
  expect(wrapper.text()).toContain('No audit events match these filters.')
  expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  wrapper.unmount()
})

it('renders safe text system attribution and fallback for unfamiliar payload versions', async () => {
  const script = '<img src=x onerror=alert(1)>'
  vi.mocked(auditApi.list).mockResolvedValue(
    page([
      {
        ...event,
        changes: {
          before: null,
          after: { name: script, token: 'never render arbitrary fields' },
        },
      },
      {
        ...event,
        id: 'future',
        actor: { type: 'system', id: null },
        payload_version: 2,
        changes: {
          before: null,
          after: { name: 'never interpret version two' },
        },
      },
    ]),
  )
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.text()).toContain(script)
  expect(wrapper.find('img').exists()).toBe(false)
  expect(wrapper.text()).not.toContain('never render arbitrary fields')
  expect(wrapper.text()).not.toContain('never interpret version two')
  expect(wrapper.text()).toContain('System')
  expect(wrapper.text()).toContain(
    'unavailable for this event format (version 2)',
  )
  expect(auditChanges({ ...event, payload_version: 2 })).toEqual([])
  expect(actorLabel({ ...event, actor: { type: 'other', id: null } })).toBe(
    'Unknown actor',
  )
  expect(auditTime('invalid')).toBe('Time unavailable')
  wrapper.unmount()
})
