<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { formFeedback } from '../lib/httpError'

const auth = useAuthStore()
const router = useRouter()
const busy = ref(false)
const errorMessage = ref('')

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
  <section aria-labelledby="app-title">
    <h1 id="app-title" class="text-3xl font-semibold">Application shell</h1>
    <p class="mt-4 text-slate-300">
      Signed in as {{ auth.user?.name }} ({{ auth.user?.email }}).
    </p>
    <p class="mt-3 text-slate-400">
      Your email is verified. ERP workflows are planned for later milestones.
    </p>
    <p v-if="errorMessage" role="alert" class="mt-5 text-rose-300">
      {{ errorMessage }}
    </p>
    <button
      type="button"
      :disabled="busy"
      class="mt-8 rounded-lg border border-slate-600 px-4 py-2 disabled:opacity-50"
      @click="logout"
    >
      Sign out
    </button>
  </section>
</template>
