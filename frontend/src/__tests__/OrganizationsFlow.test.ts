import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { organizationApi } from '../lib/organizations'
import { routes } from '../router'
import { useOrganizationStore } from '../stores/organizations'
import OrganizationsView from '../views/OrganizationsView.vue'
import OrganizationWorkspaceView from '../views/OrganizationWorkspaceView.vue'

vi.mock('../lib/organizations', () => ({
  organizationApi: {
    list: vi.fn(),
    create: vi.fn(),
    get: vi.fn(),
    context: vi.fn(),
  },
}))

const acme = { id: '01abc23456789abc23456789ab', name: 'Acme' }

async function setup(path: string) {
  const pinia = createPinia()
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()
  const component =
    path === '/app/organizations'
      ? OrganizationsView
      : OrganizationWorkspaceView
  const wrapper = mount(component, { global: { plugins: [pinia, router] } })
  return { wrapper, router, store: useOrganizationStore(pinia) }
}

beforeEach(() => vi.resetAllMocks())

describe('organization onboarding and context', () => {
  it('shows loading, then zero-organization onboarding', async () => {
    let finish!: (value: (typeof acme)[]) => void
    vi.mocked(organizationApi.list).mockReturnValue(
      new Promise((resolve) => {
        finish = resolve
      }),
    )
    const { wrapper, store } = await setup('/app/organizations')
    expect(wrapper.get('[role="status"]').text()).toContain(
      'Loading organizations',
    )
    finish([])
    await flushPromises()
    expect(wrapper.get('h2').text()).toBe('Create your first organization')
    expect(store.organizations).toEqual([])
    wrapper.unmount()
  })

  it('creates and enters the organization returned by the API', async () => {
    vi.mocked(organizationApi.list).mockResolvedValue([])
    vi.mocked(organizationApi.create).mockResolvedValue(acme)
    const { wrapper, router } = await setup('/app/organizations')
    await flushPromises()
    await wrapper.get('input').setValue('Acme')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(organizationApi.create).toHaveBeenCalledWith('Acme')
    expect(router.currentRoute.value.fullPath).toBe(
      `/app/organizations/${acme.id}`,
    )
    wrapper.unmount()
  })

  it('lists memberships and links selection to the route', async () => {
    vi.mocked(organizationApi.list).mockResolvedValue([acme])
    const { wrapper, store } = await setup('/app/organizations')
    await flushPromises()
    expect(store.organizations).toEqual([acme])
    expect(
      wrapper
        .get('a[href="/app/organizations/01abc23456789abc23456789ab"]')
        .text(),
    ).toBe('Acme')
    wrapper.unmount()
  })

  it('loads workspace context from the route and handles an inaccessible ID', async () => {
    vi.mocked(organizationApi.context)
      .mockResolvedValueOnce({ data: acme, meta: { can_view_audit: true } })
      .mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 404 },
      })
    const { wrapper, router } = await setup(`/app/organizations/${acme.id}`)
    await flushPromises()
    expect(organizationApi.context).toHaveBeenCalledWith(acme.id)
    expect(wrapper.get('h1').text()).toBe('Acme')
    expect(wrapper.get('a[href$="/audit"]').text()).toBe('Audit Trail')
    await router.push('/app/organizations/missing')
    await flushPromises()
    expect(wrapper.find('a[href$="/audit"]').exists()).toBe(false)
    expect(wrapper.get('h1').text()).toBe('Organization unavailable')
    wrapper.unmount()
  })

  it('renders list and validation failures', async () => {
    vi.mocked(organizationApi.list)
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce([])
    vi.mocked(organizationApi.create).mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          message: 'Invalid input',
          errors: { name: ['Name is invalid.'] },
        },
      },
    })
    const first = await setup('/app/organizations')
    await flushPromises()
    expect(first.wrapper.get('[role="alert"]').text()).toContain(
      'could not be completed',
    )
    first.wrapper.unmount()

    const second = await setup('/app/organizations')
    await flushPromises()
    await second.wrapper.get('input').setValue('Bad')
    await second.wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(second.wrapper.text()).toContain('Name is invalid.')
    second.wrapper.unmount()
  })
})

it('hides Audit navigation from an ordinary active member', async () => {
  vi.mocked(organizationApi.context).mockResolvedValue({
    data: acme,
    meta: { can_view_audit: false },
  })
  const { wrapper } = await setup(`/app/organizations/${acme.id}`)
  await flushPromises()
  expect(wrapper.get('h1').text()).toBe('Acme')
  expect(wrapper.find('a[href$="/audit"]').exists()).toBe(false)
  wrapper.unmount()
})

it('ignores stale workspace context and capability after switching tenants', async () => {
  let finish!: (
    value: Awaited<ReturnType<typeof organizationApi.context>>,
  ) => void
  vi.mocked(organizationApi.context)
    .mockReturnValueOnce(
      new Promise((resolve) => {
        finish = resolve
      }),
    )
    .mockResolvedValueOnce({
      data: { id: 'beta', name: 'Beta' },
      meta: { can_view_audit: false },
    })
  const { wrapper, router } = await setup(`/app/organizations/${acme.id}`)
  await router.push('/app/organizations/beta')
  await flushPromises()
  finish({ data: acme, meta: { can_view_audit: true } })
  await flushPromises()
  expect(wrapper.get('h1').text()).toBe('Beta')
  expect(wrapper.find('a[href$="/audit"]').exists()).toBe(false)
  wrapper.unmount()
})
