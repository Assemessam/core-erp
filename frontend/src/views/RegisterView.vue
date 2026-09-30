<script setup lang="ts">
import { reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { formFeedback, type FormFeedback } from '../lib/httpError'

const router = useRouter()
const auth = useAuthStore()
const input = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const busy = ref(false)
const feedback = ref<FormFeedback | null>(null)

async function submit() {
  busy.value = true
  feedback.value = null
  try {
    await auth.register({ ...input })
    await router.replace('/verify-email')
  } catch (error) {
    feedback.value = formFeedback(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md" aria-labelledby="register-title">
    <h1 id="register-title" class="text-3xl font-semibold">Create account</h1>
    <p class="mt-3 text-slate-400">
      Registration creates a user account. Check your inbox to verify your
      email.
    </p>
    <form class="mt-8 space-y-5" @submit.prevent="submit">
      <div>
        <label for="register-name" class="block text-sm font-medium"
          >Name</label
        >
        <input
          id="register-name"
          v-model="input.name"
          name="name"
          autocomplete="name"
          required
          class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
        />
        <p v-if="feedback?.fields.name" class="mt-1 text-sm text-rose-300">
          {{ feedback.fields.name }}
        </p>
      </div>
      <div>
        <label for="register-email" class="block text-sm font-medium"
          >Email</label
        >
        <input
          id="register-email"
          v-model="input.email"
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
        <label for="register-password" class="block text-sm font-medium"
          >Password</label
        >
        <input
          id="register-password"
          v-model="input.password"
          type="password"
          name="password"
          autocomplete="new-password"
          required
          minlength="12"
          class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
        />
        <p class="mt-1 text-xs text-slate-400">
          At least 12 characters, including letters and numbers.
        </p>
        <p v-if="feedback?.fields.password" class="mt-1 text-sm text-rose-300">
          {{ feedback.fields.password }}
        </p>
      </div>
      <div>
        <label for="register-confirm" class="block text-sm font-medium"
          >Confirm password</label
        >
        <input
          id="register-confirm"
          v-model="input.password_confirmation"
          type="password"
          name="password_confirmation"
          autocomplete="new-password"
          required
          class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
        />
      </div>
      <p v-if="feedback" role="alert" class="text-sm text-rose-300">
        {{ feedback.message }}
      </p>
      <button
        type="submit"
        :disabled="busy"
        class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
      >
        {{ busy ? 'Creating…' : 'Create account' }}
      </button>
    </form>
    <RouterLink to="/login" class="mt-6 inline-block text-sm text-teal-300"
      >Already have an account? Sign in</RouterLink
    >
  </section>
</template>
