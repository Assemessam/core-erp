import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, expect, it, vi } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import AcceptInvitationView from '../views/AcceptInvitationView.vue'
import { usersApi } from '../lib/organizationUsers'
import { authApi } from '../lib/auth'
import { pendingInvitation } from '../lib/pendingInvitation'
import { createAppRouter } from '../router'

vi.mock('../lib/auth', () => ({ authApi: { me: vi.fn(), logout: vi.fn() } }))
vi.mock('../lib/organizationUsers', () => ({ usersApi: { accept: vi.fn() } }))
const token = 'a'.repeat(64)
const user = {
  id: 1,
  name: 'Alice',
  email: 'alice@example.test',
  email_verified: true,
}
async function setup(hash = `#token=${token}`) {
  const pinia = createPinia()
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:pathMatch(.*)*', component: { template: '<p>Other</p>' } },
      {
        path: '/invitations/:invitationId/accept',
        component: AcceptInvitationView,
      },
      {
        path: '/app/organizations/:organizationId',
        name: 'organization',
        component: { template: '<p>Workspace</p>' },
      },
    ],
  })
  await router.push(`/invitations/invite-1/accept${hash}`)
  const wrapper = mount(AcceptInvitationView, {
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return { wrapper, router }
}
beforeEach(() => {
  vi.resetAllMocks()
  pendingInvitation.clear()
  vi.mocked(authApi.me).mockResolvedValue(user)
})
it('captures token only in memory cleans URL and accepts into workspace', async () => {
  vi.mocked(usersApi.accept).mockResolvedValue({ id: 'org-1', name: 'Org' })
  const { wrapper, router } = await setup()
  expect(router.currentRoute.value.hash).toBe('')
  expect(pendingInvitation.token('invite-1')).toBe(token)
  await wrapper.findAll('button')[0]!.trigger('click')
  await flushPromises()
  expect(usersApi.accept).toHaveBeenCalledWith('invite-1', token)
  expect(router.currentRoute.value.path).toBe('/app/organizations/org-1')
  expect(pendingInvitation.path()).toBeNull()
  wrapper.unmount()
})
it('offers sign in and registration to guests without submitting acceptance', async () => {
  vi.mocked(authApi.me).mockRejectedValue({
    isAxiosError: true,
    response: { status: 401 },
  })
  const { wrapper } = await setup()
  expect(wrapper.text()).toContain('Create account')
  expect(wrapper.text()).toContain('Sign in')
  expect(usersApi.accept).not.toHaveBeenCalled()
  wrapper.unmount()
})
it('requires verification and preserves a return path', async () => {
  vi.mocked(authApi.me).mockResolvedValue({ ...user, email_verified: false })
  const { wrapper } = await setup()
  expect(wrapper.text()).toContain('Verify your email')
  expect(pendingInvitation.path()).toBe('/invitations/invite-1/accept')
  wrapper.unmount()
})
it.each(['email_mismatch', 'expired', 'revoked', 'accepted', 'invalid'])(
  'renders %s safely',
  async (reason) => {
    const message = `Invitation ${reason}`
    vi.mocked(usersApi.accept).mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { reason, message } },
    })
    const { wrapper } = await setup()
    await wrapper.findAll('button')[0]!.trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe(message)
    expect(pendingInvitation.path() !== null).toBe(reason === 'email_mismatch')
    expect(wrapper.find('button').exists()).toBe(reason === 'email_mismatch')
    wrapper.unmount()
  },
)
it('explains recovery after a reload loses the in-memory token', async () => {
  const { wrapper } = await setup('')
  expect(wrapper.text()).toContain('Reopen the invitation link')
  expect(wrapper.find('button').exists()).toBe(false)
  wrapper.unmount()
})
it('returns verified users to pending acceptance without a redirect loop', async () => {
  setActivePinia(createPinia())
  pendingInvitation.capture('invite-1', `#token=${token}`)
  const router = createAppRouter(createMemoryHistory())
  await router.push('/app')
  expect(router.currentRoute.value.path).toBe('/invitations/invite-1/accept')
  await router.push('/invitations/invite-1/accept')
  expect(router.currentRoute.value.name).toBe('accept-invitation')
})
