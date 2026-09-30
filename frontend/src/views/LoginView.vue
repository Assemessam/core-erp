<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { formFeedback, type FormFeedback } from '../lib/httpError'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const email = ref('')
const password = ref('')
const busy = ref(false)
const feedback = ref<FormFeedback | null>(null)

async function submit() {
  busy.value = true
  feedback.value = null
  try {
    await auth.login(email.value, password.value)
    const candidate = route.query.redirect
    const destination =
      typeof candidate === 'string' &&
      candidate.startsWith('/') &&
      !candidate.startsWith('//')
        ? candidate
        : '/app'
    await router.replace(
      auth.user?.email_verified ? destination : '/verify-email',
    )
  } catch (error) {
    feedback.value = formFeedback(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md" aria-labelledby="login-title">
    <h1 id="login-title" class="text-3xl font-semibold">Sign in</h1>
    <p v-if="route.query.unavailable" role="alert" class="mt-4 text-amber-300">
      The API is unavailable. Try again shortly.
    </p>
    <form class="mt-8 space-y-5" @submit.prevent="submit">
      <div>
        <label for="login-email" class="block text-sm font-medium">Email</label>
        <input
          id="login-email"
          v-model="email"
          type="email"
          name="email"
          autocomplete="email"
          required
          class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
        />
        <p v-if="feedback?.fields.email" class="mt-1 text-sm text-rose-300">
          {{ feedback.fields.email }}
        </p>
      </div>
      <div>
        <label for="login-password" class="block text-sm font-medium"
          >Password</label
        >
        <input
          id="login-password"
          v-model="password"
          type="password"
          name="password"
          autocomplete="current-password"
          required
          class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
        />
        <p v-if="feedback?.fields.password" class="mt-1 text-sm text-rose-300">
          {{ feedback.fields.password }}
        </p>
      </div>
      <p v-if="feedback" role="alert" class="text-sm text-rose-300">
        {{ feedback.message }}
      </p>
      <button
        type="submit"
        :disabled="busy"
        class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
      >
        {{ busy ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
    <div class="mt-6 flex gap-6 text-sm text-teal-300">
      <RouterLink to="/forgot-password">Forgot password?</RouterLink>
      <RouterLink to="/register">Create account</RouterLink>
    </div>
  </section>
</template>
