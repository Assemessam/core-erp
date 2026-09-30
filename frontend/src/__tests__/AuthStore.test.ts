import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { authApi, type User } from '../lib/auth'
import { useAuthStore } from '../stores/auth'

vi.mock('../lib/auth', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
  },
}))

const verified: User = {
  id: 7,
  name: 'Ada',
  email: 'ada@example.test',
  email_verified: true,
}
const unauthorized = { isAxiosError: true, response: { status: 401 } }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

describe('authentication state', () => {
  it('coalesces initialization requests and restores an existing session', async () => {
    vi.mocked(authApi.me).mockResolvedValue(verified)
    const auth = useAuthStore()

    await Promise.all([auth.initialize(), auth.initialize()])

    expect(authApi.me).toHaveBeenCalledTimes(1)
    expect(auth.user).toEqual(verified)
    expect(auth.initialized).toBe(true)
  })

  it('initializes guests without a user and later records successful login', async () => {
    vi.mocked(authApi.me)
      .mockRejectedValueOnce(unauthorized)
      .mockResolvedValueOnce(verified)
    const auth = useAuthStore()
    await auth.initialize()
    expect(auth.user).toBeNull()
    expect(auth.initialized).toBe(true)

    await auth.login('ada@example.test', 'password')
    expect(authApi.login).toHaveBeenCalledWith('ada@example.test', 'password')
    expect(auth.user).toEqual(verified)
    expect(Object.keys(auth.$state)).toEqual(['user', 'initialized'])
  })

  it('keeps the current user when logout fails with an expired CSRF token', async () => {
    vi.mocked(authApi.me).mockResolvedValue(verified)
    vi.mocked(authApi.logout).mockRejectedValue({
      isAxiosError: true,
      response: { status: 419 },
    })
    const auth = useAuthStore()
    await auth.initialize()

    await expect(auth.logout()).rejects.toBeDefined()
    expect(auth.user).toEqual(verified)
  })

  it('clears the user after a successful logout', async () => {
    vi.mocked(authApi.me).mockResolvedValue(verified)
    const auth = useAuthStore()
    await auth.initialize()
    await auth.logout()
    expect(auth.user).toBeNull()
  })

  it('drops an expired server session when the user is refreshed', async () => {
    vi.mocked(authApi.me)
      .mockResolvedValueOnce(verified)
      .mockRejectedValueOnce(unauthorized)
    const auth = useAuthStore()
    await auth.initialize()
    await auth.refresh()
    expect(auth.user).toBeNull()
  })
})
