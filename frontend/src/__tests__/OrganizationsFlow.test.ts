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
  organizationApi: { list: vi.fn(), create: vi.fn(), get: vi.fn() },
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
    vi.mocked(organizationApi.get)
      .mockResolvedValueOnce(acme)
      .mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 404 },
      })
    const { wrapper, router, store } = await setup(
      `/app/organizations/${acme.id}`,
    )
    await flushPromises()
    expect(organizationApi.get).toHaveBeenCalledWith(acme.id)
    expect(wrapper.get('h1').text()).toBe('Acme')
    await router.push('/app/organizations/missing')
    await flushPromises()
    expect(store.current).toBeNull()
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
