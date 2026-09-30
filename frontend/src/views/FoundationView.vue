<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '../lib/api'

const status = ref<'checking' | 'ready' | 'unavailable'>('checking')
const statusLabel = computed(
  () =>
    ({
      checking: 'Checking API connectivity…',
      ready: 'API and data services are ready.',
      unavailable: 'API is unavailable. Check the local stack and try again.',
    })[status.value],
)

async function checkReadiness() {
  status.value = 'checking'
  try {
    const response = await api.get<{ data: { status: string } }>('/ready')
    status.value = response.data.data.status === 'ok' ? 'ready' : 'unavailable'
  } catch {
    status.value = 'unavailable'
  }
}

onMounted(checkReadiness)
</script>

<template>
  <section aria-labelledby="foundation-title">
    <p class="mb-4 text-sm font-medium tracking-widest text-teal-300 uppercase">
      Phase 1.1
    </p>
    <h1
      id="foundation-title"
      class="max-w-2xl text-4xl leading-tight font-semibold sm:text-5xl"
    >
      A secure start for CoreERP.
    </h1>
    <p class="mt-6 max-w-xl text-lg leading-relaxed text-slate-400">
      Create an account and verify your email to enter the protected application
      shell. ERP workflows will arrive in later milestones.
    </p>
    <div class="mt-8 flex gap-4 text-sm font-medium">
      <RouterLink
        to="/register"
        class="rounded-lg bg-teal-300 px-4 py-2 text-slate-950"
        >Create account</RouterLink
      >
      <RouterLink
        to="/login"
        class="rounded-lg border border-slate-600 px-4 py-2"
        >Sign in</RouterLink
      >
    </div>
    <div
      class="mt-10 max-w-xl rounded-xl border border-slate-700 bg-slate-900 p-6"
    >
      <h2 class="text-base font-medium">Service readiness</h2>
      <p role="status" aria-live="polite" class="mt-2 text-sm text-slate-300">
        {{ statusLabel }}
      </p>
      <button
        type="button"
        :disabled="status === 'checking'"
        class="mt-5 rounded-lg bg-teal-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-teal-200 disabled:cursor-wait disabled:opacity-50"
        @click="checkReadiness"
      >
        Check again
      </button>
    </div>
  </section>
</template>
