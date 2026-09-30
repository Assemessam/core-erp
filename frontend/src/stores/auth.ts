import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi, type RegistrationInput, type User } from '../lib/auth'
import { isUnauthenticated } from '../lib/httpError'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const initialized = ref(false)
  let pending: Promise<void> | null = null

  async function refresh(): Promise<void> {
    try {
      user.value = await authApi.me()
    } catch (error) {
      if (!isUnauthenticated(error)) throw error
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function initialize(): Promise<void> {
    if (initialized.value) return
    if (!pending)
      pending = refresh().finally(() => {
        pending = null
      })
    return pending
  }

  async function login(email: string, password: string): Promise<void> {
    await authApi.login(email, password)
    await refresh()
  }

  async function register(input: RegistrationInput): Promise<void> {
    await authApi.register(input)
    await refresh()
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
      user.value = null
      initialized.value = true
    } catch (error) {
      if (isUnauthenticated(error)) {
        user.value = null
        initialized.value = true
      }
      throw error
    }
  }

  return { user, initialized, initialize, refresh, login, register, logout }
})
