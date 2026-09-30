import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, expect, it, vi } from 'vitest'
import { authApi } from '../lib/auth'
import LoginView from '../views/LoginView.vue'
import { createAppRouter } from '../router'

vi.mock('../lib/auth', () => ({ authApi: { me: vi.fn(), login: vi.fn() } }))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

it('shows Fortify validation feedback without losing the form state', async () => {
  vi.mocked(authApi.me).mockRejectedValue({
    isAxiosError: true,
    response: { status: 401 },
  })
  vi.mocked(authApi.login).mockRejectedValue({
    isAxiosError: true,
    response: {
      status: 422,
      data: {
        message: 'The provided credentials are incorrect.',
        errors: { email: ['These credentials do not match our records.'] },
      },
    },
  })
  const router = createAppRouter(createMemoryHistory())
  await router.push('/login')
  const wrapper = mount(LoginView, { global: { plugins: [router] } })
  await wrapper.get('#login-email').setValue('ada@example.test')
  await wrapper.get('#login-password').setValue('wrong')
  await wrapper.get('form').trigger('submit')
  await flushPromises()

  expect(wrapper.get('[role="alert"]').text()).toContain(
    'The provided credentials are incorrect.',
  )
  expect(wrapper.text()).toContain(
    'These credentials do not match our records.',
  )
  expect((wrapper.get('#login-email').element as HTMLInputElement).value).toBe(
    'ada@example.test',
  )
  wrapper.unmount()
})
