<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { pendingInvitation } from '../lib/pendingInvitation'
import { authApi } from '../lib/auth'
import { useAuthStore } from '../stores/auth'
import { formFeedback } from '../lib/httpError'

const auth = useAuthStore()
const router = useRouter()
const busy = ref(false)
const status = ref('')
const errorMessage = ref('')

async function resend() {
  busy.value = true
  status.value = ''
  errorMessage.value = ''
  try {
    await authApi.resendVerification()
    status.value = 'A new verification email was sent.'
  } catch (error) {
    errorMessage.value = formFeedback(error).message
  } finally {
    busy.value = false
  }
}

async function check() {
  busy.value = true
  errorMessage.value = ''
  try {
    await auth.refresh()
    if (auth.user?.email_verified)
      await router.replace(pendingInvitation.path() ?? '/app')
    else status.value = 'Your email is not verified yet.'
  } catch (error) {
    errorMessage.value = formFeedback(error).message
  } finally {
    busy.value = false
  }
}

async function logout() {
  busy.value = true
  errorMessage.value = ''
  try {
    await auth.logout()
    await router.replace('/login')
  } catch (error) {
    errorMessage.value = formFeedback(error).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-lg" aria-labelledby="verify-title">
    <h1 id="verify-title" class="text-3xl font-semibold">Verify your email</h1>
    <p class="mt-4 text-slate-300">
      We sent a signed verification link to
      <strong>{{ auth.user?.email }}</strong
      >. Open the link, then return here.
    </p>
    <p v-if="status" role="status" class="mt-5 text-teal-300">{{ status }}</p>
    <p v-if="errorMessage" role="alert" class="mt-5 text-rose-300">
      {{ errorMessage }}
    </p>
    <div class="mt-8 flex flex-wrap gap-3">
      <button
        type="button"
        :disabled="busy"
        class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
        @click="check"
      >
        I've verified
      </button>
      <button
        type="button"
        :disabled="busy"
        class="rounded-lg border border-slate-600 px-4 py-2 disabled:opacity-50"
        @click="resend"
      >
        Resend email
      </button>
      <button
        type="button"
        :disabled="busy"
        class="rounded-lg border border-slate-600 px-4 py-2 disabled:opacity-50"
        @click="logout"
      >
        Sign out
      </button>
    </div>
  </section>
</template>
