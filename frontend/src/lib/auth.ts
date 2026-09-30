import { api, http } from './api'

export interface User {
  id: number
  name: string
  email: string
  email_verified: boolean
}

export interface RegistrationInput {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export interface ResetPasswordInput {
  token: string
  email: string
  password: string
  password_confirmation: string
}

async function csrf(): Promise<void> {
  await http.get('/sanctum/csrf-cookie')
}

export const authApi = {
  async me(): Promise<User> {
    const response = await api.get<{ data: User }>('/me')
    return response.data.data
  },

  async login(email: string, password: string): Promise<void> {
    await csrf()
    await http.post('/login', { email, password })
  },

  async register(input: RegistrationInput): Promise<void> {
    await csrf()
    await http.post('/register', input)
  },

  async logout(): Promise<void> {
    await csrf()
    await http.post('/logout')
  },

  async forgotPassword(email: string): Promise<void> {
    await csrf()
    await http.post('/forgot-password', { email })
  },

  async resetPassword(input: ResetPasswordInput): Promise<void> {
    await csrf()
    await http.post('/reset-password', input)
  },

  async resendVerification(): Promise<void> {
    await csrf()
    await http.post('/email/verification-notification')
  },
}
