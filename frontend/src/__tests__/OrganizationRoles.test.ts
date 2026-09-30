import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, expect, it, vi } from 'vitest'
import { roleApi, type Role } from '../lib/roles'
import OrganizationRolesView from '../views/OrganizationRolesView.vue'

vi.mock('../lib/roles', () => ({
  roleApi: { list: vi.fn(), permissions: vi.fn(), save: vi.fn() },
}))

const catalog = [
  { key: 'organizations.update', label: 'Update organization details' },
  { key: 'roles.view', label: 'View roles and permissions' },
]
const editor: Role = {
  id: 'role-1',
  name: 'Editor',
  permissions: ['organizations.update'],
}

async function setup() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/app/organizations/:organizationId/roles',
        component: OrganizationRolesView,
      },
      {
        path: '/app/organizations/:organizationId',
        name: 'organization',
        component: { template: '<p>Workspace</p>' },
      },
    ],
  })
  await router.push('/app/organizations/alpha/roles')
  const wrapper = mount(OrganizationRolesView, {
    global: { plugins: [router] },
  })
  return { wrapper, router }
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(roleApi.list).mockResolvedValue({
    data: [],
    meta: { can_manage: true },
  })
  vi.mocked(roleApi.permissions).mockResolvedValue(catalog)
})

it('loads the organization role list and retains workspace context', async () => {
  let finish!: (value: Awaited<ReturnType<typeof roleApi.list>>) => void
  vi.mocked(roleApi.list).mockReturnValue(
    new Promise((resolve) => {
      finish = resolve
    }),
  )
  const { wrapper } = await setup()
  expect(wrapper.get('[role="status"]').text()).toBe('Loading roles…')
  finish({ data: [editor], meta: { can_manage: true } })
  await flushPromises()
  expect(roleApi.list).toHaveBeenCalledWith('alpha')
  expect(wrapper.get('ul[aria-label="Organization roles"]').text()).toContain(
    'Editor',
  )
  expect(wrapper.get('a').attributes('href')).toBe('/app/organizations/alpha')
  wrapper.unmount()
})

it('creates then edits a role and assigns its permission set', async () => {
  vi.mocked(roleApi.save)
    .mockResolvedValueOnce(editor)
    .mockResolvedValueOnce({
      ...editor,
      name: 'Reader',
      permissions: ['roles.view'],
    })
  const { wrapper } = await setup()
  await flushPromises()
  await wrapper.get('#role-name').setValue('Editor')
  await wrapper.get('input[value="organizations.update"]').setValue(true)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(roleApi.save).toHaveBeenCalledWith('alpha', null, {
    name: 'Editor',
    permissions: ['organizations.update'],
  })
  await wrapper.get('button[aria-label="Edit Editor"]').trigger('click')
  await wrapper.get('#role-name').setValue('Reader')
  await wrapper.get('input[value="organizations.update"]').setValue(false)
  await wrapper.get('input[value="roles.view"]').setValue(true)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(roleApi.save).toHaveBeenLastCalledWith('alpha', 'role-1', {
    name: 'Reader',
    permissions: ['roles.view'],
  })
  expect(wrapper.get('ul[aria-label="Organization roles"]').text()).toContain(
    'Reader',
  )
  expect(wrapper.text()).toContain('Role saved.')
  wrapper.unmount()
})

it('shows server validation errors and preserves editable input', async () => {
  vi.mocked(roleApi.save).mockRejectedValue({
    isAxiosError: true,
    response: {
      status: 422,
      data: { errors: { name: ['Duplicate role name.'] } },
    },
  })
  const { wrapper } = await setup()
  await flushPromises()
  await wrapper.get('#role-name').setValue('Duplicate')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.text()).toContain('Duplicate role name.')
  expect((wrapper.get('#role-name').element as HTMLInputElement).value).toBe(
    'Duplicate',
  )
  wrapper.unmount()
})

it.each([403, 404, 500])(
  'handles loading failure %s without showing administration controls',
  async (status) => {
    vi.mocked(roleApi.list).mockRejectedValue({
      isAxiosError: true,
      response: { status },
    })
    const { wrapper } = await setup()
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toContain(
      status === 403
        ? 'permission'
        : status === 404
          ? 'found or accessed'
          : 'could not be completed',
    )
    wrapper.unmount()
  },
)

it('presents a reader with roles and catalog but no mutation controls', async () => {
  vi.mocked(roleApi.list).mockResolvedValue({
    data: [editor],
    meta: { can_manage: false },
  })
  const { wrapper } = await setup()
  await flushPromises()
  expect(wrapper.text()).toContain('Read-only access')
  expect(wrapper.text()).toContain('Update organization details')
  expect(wrapper.find('form').exists()).toBe(false)
  expect(wrapper.find('button[aria-label="Edit Editor"]').exists()).toBe(false)
  wrapper.unmount()
})

it('handles server-side denial even after management controls were shown', async () => {
  vi.mocked(roleApi.save).mockRejectedValue({
    isAxiosError: true,
    response: { status: 403 },
  })
  const { wrapper } = await setup()
  await flushPromises()
  await wrapper.get('#role-name').setValue('Rejected')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.get('[role="alert"]').text()).toContain('permission')
  expect(wrapper.find('form').exists()).toBe(false)
  wrapper.unmount()
})

it('clears old context and ignores a late response when the organization route changes', async () => {
  let finish!: (value: Awaited<ReturnType<typeof roleApi.list>>) => void
  vi.mocked(roleApi.list)
    .mockReturnValueOnce(
      new Promise((resolve) => {
        finish = resolve
      }),
    )
    .mockResolvedValueOnce({
      data: [{ id: 'beta-role', name: 'Beta role', permissions: [] }],
      meta: { can_manage: false },
    })
  const { wrapper, router } = await setup()
  await router.push('/app/organizations/beta/roles')
  await flushPromises()
  finish({ data: [editor], meta: { can_manage: true } })
  await flushPromises()
  expect(roleApi.list).toHaveBeenLastCalledWith('beta')
  expect(wrapper.text()).toContain('Beta role')
  expect(wrapper.text()).not.toContain('Editor')
  expect(wrapper.find('form').exists()).toBe(false)
  expect(wrapper.get('a').attributes('href')).toBe('/app/organizations/beta')
  wrapper.unmount()
})
