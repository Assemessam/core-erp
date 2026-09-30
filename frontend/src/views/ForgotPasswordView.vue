<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { authApi } from '../lib/auth'
import { formFeedback, type FormFeedback } from '../lib/httpError'

const email = ref('')
const busy = ref(false)
const sent = ref(false)
const feedback = ref<FormFeedback | null>(null)

async function submit() {
  busy.value = true
  feedback.value = null
  try {
    await authApi.forgotPassword(email.value)
    sent.value = true
  } catch (error) {
    feedback.value = formFeedback(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md" aria-labelledby="forgot-title">
    <h1 id="forgot-title" class="text-3xl font-semibold">
      Reset your password
    </h1>
    <p class="mt-3 text-slate-400">
      Enter your email address. If an account exists, a reset link will be sent.
    </p>
    <p v-if="sent" role="status" class="mt-6 text-teal-300">
      If the address is registered, a reset link will be sent.
    </p>
    <form class="mt-8 space-y-5" @submit.prevent="submit">
      <div>
        <label for="forgot-email" class="block text-sm font-medium"
          >Email</label
        >
        <input
          id="forgot-email"
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
      <p v-if="feedback" role="alert" class="text-sm text-rose-300">
        {{ feedback.message }}
      </p>
      <button
        type="submit"
        :disabled="busy"
        class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
      >
        {{ busy ? 'Sending…' : 'Send reset link' }}
      </button>
    </form>
    <RouterLink to="/login" class="mt-6 inline-block text-sm text-teal-300"
      >Back to sign in</RouterLink
    >
  </section>
</template>
