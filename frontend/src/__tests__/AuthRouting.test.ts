import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { authApi, type User } from '../lib/auth'
import { createAppRouter } from '../router'

vi.mock('../lib/auth', () => ({ authApi: { me: vi.fn() } }))

const verified: User = {
  id: 1,
  name: 'Ada',
  email: 'ada@example.test',
  email_verified: true,
}
const unverified: User = { ...verified, email_verified: false }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

describe('authentication route guards', () => {
  it('resolves unknown state before sending guests to login', async () => {
    vi.mocked(authApi.me).mockRejectedValue({
      isAxiosError: true,
      response: { status: 401 },
    })
    const router = createAppRouter(createMemoryHistory())
    await router.push('/app')
    await router.isReady()
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/app/organizations')
    expect(authApi.me).toHaveBeenCalledTimes(2)
  })

  it('sends unverified users to verification and verified users to the app', async () => {
    vi.mocked(authApi.me).mockResolvedValue(unverified)
    const unverifiedRouter = createAppRouter(createMemoryHistory())
    await unverifiedRouter.push('/app')
    expect(unverifiedRouter.currentRoute.value.name).toBe('verify-email')

    setActivePinia(createPinia())
    vi.mocked(authApi.me).mockResolvedValue(verified)
    const verifiedRouter = createAppRouter(createMemoryHistory())
    await verifiedRouter.push('/verify-email')
    expect(verifiedRouter.currentRoute.value.name).toBe('organizations')
  })

  it('keeps authenticated users out of guest-only pages', async () => {
    vi.mocked(authApi.me).mockResolvedValue(verified)
    const router = createAppRouter(createMemoryHistory())
    await router.push('/login')
    expect(router.currentRoute.value.name).toBe('organizations')
  })

  it('rechecks a cached user before entering a protected route after session expiry', async () => {
    vi.mocked(authApi.me)
      .mockResolvedValueOnce(verified)
      .mockRejectedValue({
        isAxiosError: true,
        response: { status: 401 },
      })
    const router = createAppRouter(createMemoryHistory())
    await router.push('/app')
    expect(router.currentRoute.value.name).toBe('organizations')

    await router.push('/verify-email')
    expect(router.currentRoute.value.name).toBe('login')
    expect(authApi.me).toHaveBeenCalledTimes(3)
  })
})
