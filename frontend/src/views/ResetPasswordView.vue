<script setup lang="ts">
import { reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { authApi } from '../lib/auth'
import { formFeedback, type FormFeedback } from '../lib/httpError'

const route = useRoute()
const input = reactive({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: '',
})
const busy = ref(false)
const completed = ref(false)
const feedback = ref<FormFeedback | null>(null)

async function submit() {
  busy.value = true
  feedback.value = null
  try {
    await authApi.resetPassword({ token: String(route.params.token), ...input })
    completed.value = true
    input.password = ''
    input.password_confirmation = ''
  } catch (error) {
    feedback.value = formFeedback(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md" aria-labelledby="reset-title">
    <h1 id="reset-title" class="text-3xl font-semibold">Set a new password</h1>
    <div v-if="completed" role="status" class="mt-6 text-teal-300">
      Your password was changed.
      <RouterLink to="/login" class="underline">Sign in</RouterLink> with the
      new password.
    </div>
    <form v-else class="mt-8 space-y-5" @submit.prevent="submit">
      <div>
        <label for="reset-email" class="block text-sm font-medium">Email</label>
        <input
          id="reset-email"
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
        <label for="reset-password" class="block text-sm font-medium"
          >New password</label
        >
        <input
          id="reset-password"
          v-model="input.password"
          type="password"
          name="password"
          autocomplete="new-password"
          required
          minlength="12"
          class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
        />
        <p v-if="feedback?.fields.password" class="mt-1 text-sm text-rose-300">
          {{ feedback.fields.password }}
        </p>
      </div>
      <div>
        <label for="reset-confirm" class="block text-sm font-medium"
          >Confirm new password</label
        >
        <input
          id="reset-confirm"
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
        {{ busy ? 'Changing…' : 'Change password' }}
      </button>
    </form>
  </section>
</template>
