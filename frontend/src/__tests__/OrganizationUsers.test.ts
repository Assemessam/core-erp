import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, expect, it, vi } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import OrganizationUsersView from '../views/OrganizationUsersView.vue'
import { usersApi, type Member } from '../lib/organizationUsers'
import { roleApi } from '../lib/roles'

vi.mock('../lib/organizationUsers', () => ({
  usersApi: {
    access: vi.fn(),
    members: vi.fn(),
    invitations: vi.fn(),
    invite: vi.fn(),
    revoke: vi.fn(),
    roles: vi.fn(),
    lifecycle: vi.fn(),
  },
}))
vi.mock('../lib/roles', () => ({ roleApi: { list: vi.fn() } }))
const role = { id: 'role-1', name: 'Reader', permissions: ['members.view'] }
const owner: Member = {
  id: 1,
  user_id: 1,
  name: 'Owner',
  email: 'owner@example.test',
  status: 'active',
  is_owner: true,
  roles: [],
}
const member: Member = {
  id: 2,
  user_id: 2,
  name: 'Alice',
  email: 'alice@example.test',
  status: 'active',
  is_owner: false,
  roles: [role],
}
async function setup() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/organizations/:organizationId/users',
        component: OrganizationUsersView,
      },
      {
        path: '/organizations/:organizationId',
        name: 'organization',
        component: { template: '<p>Workspace</p>' },
      },
    ],
  })
  await router.push('/organizations/alpha/users')
  const wrapper = mount(OrganizationUsersView, {
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(usersApi.access).mockResolvedValue({
    can_view: true,
    can_invite: true,
    can_manage: true,
  })
  vi.mocked(usersApi.members).mockResolvedValue([owner, member])
  vi.mocked(usersApi.invitations).mockResolvedValue([
    {
      id: 'invite-1',
      email: 'new@example.test',
      state: 'pending',
      expires_at: '2026-10-08T12:00:00Z',
      roles: [role],
    },
  ])
  vi.mocked(roleApi.list).mockResolvedValue({
    data: [role],
    meta: { can_manage: true },
  })
})
it('lists members roles status owner and pending invitations without owner lifecycle buttons', async () => {
  const { wrapper } = await setup()
  const rows = wrapper.findAll('ul[aria-label="Organization members"] li')
  expect(rows[0]!.text()).toContain('Owner')
  expect(rows[0]!.text()).not.toContain('Suspend')
  expect(rows[0]!.text()).not.toContain('Remove')
  expect(rows[1]!.text()).toContain('active · Reader')
  expect(wrapper.get('ul[aria-label="Pending invitations"]').text()).toContain(
    'new@example.test',
  )
  wrapper.unmount()
})
it('invites with selected roles and saves member role selection', async () => {
  const { wrapper } = await setup()
  await wrapper.get('#invite-email').setValue('new@example.test')
  await wrapper.get('fieldset input').setValue(true)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(usersApi.invite).toHaveBeenCalledWith('alpha', 'new@example.test', [
    'role-1',
  ])
  await wrapper
    .get('button[aria-label="Assign roles to alice@example.test"]')
    .trigger('click')
  await wrapper.get('form input[type="checkbox"]').setValue(false)
  await wrapper.findAll('form')[0]!.trigger('submit')
  await flushPromises()
  expect(usersApi.roles).toHaveBeenCalledWith('alpha', 2, [])
  wrapper.unmount()
})
it('suspends reactivates and removes a member through explicit commands', async () => {
  const { wrapper } = await setup()
  const click = async (name: string) => {
    await wrapper
      .findAll('button')
      .find((button) => button.text() === name)!
      .trigger('click')
    await flushPromises()
  }
  vi.mocked(usersApi.members).mockResolvedValue([
    owner,
    { ...member, status: 'suspended' },
  ])
  await click('Suspend')
  expect(usersApi.lifecycle).toHaveBeenLastCalledWith('alpha', 2, 'suspend')
  await click('Reactivate')
  expect(usersApi.lifecycle).toHaveBeenLastCalledWith('alpha', 2, 'activate')
  vi.spyOn(globalThis, 'confirm').mockReturnValue(true)
  await click('Remove')
  expect(usersApi.lifecycle).toHaveBeenLastCalledWith('alpha', 2, 'remove')
  wrapper.unmount()
})
it('revokes and reinvites the selected invitation', async () => {
  const { wrapper } = await setup()
  await wrapper
    .findAll('button')
    .find((button) => button.text() === 'Revoke')!
    .trigger('click')
  await flushPromises()
  expect(usersApi.revoke).toHaveBeenCalledWith('alpha', 'invite-1')
  await wrapper
    .findAll('button')
    .find((button) => button.text() === 'Reinvite')!
    .trigger('click')
  await flushPromises()
  expect(usersApi.invite).toHaveBeenCalledWith('alpha', 'new@example.test', [
    'role-1',
  ])
  wrapper.unmount()
})
it('limits delegated inviters to roleless invitations and hides owner controls', async () => {
  vi.mocked(usersApi.access).mockResolvedValue({
    can_view: false,
    can_invite: true,
    can_manage: false,
  })
  const { wrapper } = await setup()
  expect(usersApi.members).not.toHaveBeenCalled()
  expect(roleApi.list).not.toHaveBeenCalled()
  expect(wrapper.find('fieldset').exists()).toBe(false)
  expect(wrapper.text()).not.toContain('Revoke')
  expect(wrapper.text()).toContain('Invitations you send have no roles')
  wrapper.unmount()
})
it('renders server errors and disables management after authorization loss', async () => {
  vi.mocked(usersApi.invite).mockRejectedValue({
    isAxiosError: true,
    response: { status: 403 },
  })
  const { wrapper } = await setup()
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.get('[role="alert"]').text()).toContain('permission')
  expect(wrapper.find('form').exists()).toBe(false)
  wrapper.unmount()
})
it('ignores responses from a previous tenant', async () => {
  let finish!: (value: Member[]) => void
  vi.mocked(usersApi.members).mockReturnValueOnce(
    new Promise((resolve) => {
      finish = resolve
    }),
  )
  const { wrapper, router } = await setup()
  vi.mocked(usersApi.members).mockResolvedValue([{ ...member, name: 'Beta' }])
  await router.push('/organizations/beta/users')
  await flushPromises()
  finish([owner])
  await flushPromises()
  expect(wrapper.text()).toContain('Beta')
  expect(wrapper.text()).not.toContain('owner@example.test')
  wrapper.unmount()
})
