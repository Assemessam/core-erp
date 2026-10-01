<script setup lang="ts">
import { watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { isAxiosError } from 'axios'
import { useAuthStore } from '../stores/auth'
import { pendingInvitation } from '../lib/pendingInvitation'
import { usersApi } from '../lib/organizationUsers'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const busy = ref(false)
const loading = ref(true)
const error = ref('')
const terminal = ref(false)
const token = ref<string | null>(null)
const invitationId = () => String(route.params.invitationId)
let generation = 0
watch(
  () => route.params.invitationId,
  async () => {
    const current = ++generation
    error.value = ''
    terminal.value = false
    loading.value = true
    pendingInvitation.capture(invitationId(), route.hash)
    token.value = pendingInvitation.token(invitationId())
    if (route.hash) await router.replace({ path: route.path, hash: '' })
    try {
      await auth.refresh()
    } catch (failure) {
      if (current === generation) error.value = formFeedback(failure).message
    } finally {
      if (current === generation) loading.value = false
    }
  },
  { immediate: true },
)
async function accept() {
  if (busy.value || !token.value) return
  const current = generation
  busy.value = true
  error.value = ''
  try {
    const organization = await usersApi.accept(invitationId(), token.value)
    if (current !== generation) return
    pendingInvitation.clear()
    token.value = null
    await router.replace({
      name: 'organization',
      params: { organizationId: organization.id },
    })
  } catch (failure) {
    if (current !== generation) return
    const reason = isAxiosError(failure) ? failure.response?.data?.reason : null
    error.value =
      isAxiosError(failure) && failure.response?.data?.message
        ? failure.response.data.message
        : formFeedback(failure).message
    terminal.value = [
      'expired',
      'revoked',
      'accepted',
      'invalid',
      'member_exists',
    ].includes(reason)
    if (terminal.value) {
      pendingInvitation.clear()
      token.value = null
    }
  } finally {
    busy.value = false
  }
}
async function switchAccount() {
  try {
    await auth.logout()
  } catch (failure) {
    error.value = formFeedback(failure).message
  }
}
</script>

<template>
  <section class="mx-auto max-w-lg space-y-5">
    <h1 class="text-3xl font-semibold">Accept invitation</h1>
    <p v-if="loading" role="status">Checking your account…</p>
    <p v-if="error" role="alert" class="text-rose-300">{{ error }}</p>
    <template v-if="!loading && !terminal">
      <p v-if="!token">
        Reopen the invitation link from your email to continue.
      </p>
      <template v-else-if="!auth.user">
        <p>
          Sign in or create an account using the email address that received
          this invitation.
        </p>
        <div class="flex gap-5 text-teal-300">
          <RouterLink to="/login">Sign in</RouterLink
          ><RouterLink to="/register">Create account</RouterLink>
        </div>
      </template>
      <template v-else-if="!auth.user.email_verified">
        <p>
          Verify your email, then return here to accept. Open the verification
          link in another tab, or reopen the invitation email afterward.
        </p>
        <RouterLink to="/verify-email" class="text-teal-300"
          >Verify email</RouterLink
        >
      </template>
      <template v-else>
        <p>
          Accepting as {{ auth.user.email }}. This must match the invited email
          address.
        </p>
        <button
          :disabled="busy"
          class="rounded bg-teal-300 px-4 py-2 text-slate-950"
          @click="accept"
        >
          {{ busy ? 'Accepting…' : 'Accept invitation' }}
        </button>
        <button
          :disabled="busy"
          class="ml-4 text-teal-300"
          @click="switchAccount"
        >
          Use another account
        </button>
      </template>
    </template>
    <RouterLink v-if="terminal" to="/app/organizations" class="text-teal-300"
      >Your organizations</RouterLink
    >
  </section>
</template>
