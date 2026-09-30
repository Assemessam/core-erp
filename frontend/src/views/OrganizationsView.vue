<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useOrganizationStore } from '../stores/organizations'
import { formFeedback } from '../lib/httpError'

const auth = useAuthStore()
const organizations = useOrganizationStore()
const router = useRouter()
const name = ref('')
const busy = ref(false)
const errorMessage = ref('')
const nameError = ref('')

onMounted(async () => {
  try {
    await organizations.load()
  } catch (error) {
    errorMessage.value = formFeedback(error).message
  }
})

async function createOrganization() {
  busy.value = true
  errorMessage.value = ''
  nameError.value = ''
  try {
    const organization = await organizations.create(name.value)
    await router.push({
      name: 'organization',
      params: { organizationId: organization.id },
    })
  } catch (error) {
    const feedback = formFeedback(error)
    errorMessage.value = feedback.message
    nameError.value = feedback.fields.name ?? ''
  } finally {
    busy.value = false
  }
}

async function logout() {
  busy.value = true
  errorMessage.value = ''
  try {
    await auth.logout()
    organizations.clear()
    await router.replace('/login')
  } catch (error) {
    errorMessage.value = formFeedback(error).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section aria-labelledby="organizations-title" class="mx-auto max-w-2xl">
    <h1 id="organizations-title" class="text-3xl font-semibold">
      Organizations
    </h1>
    <p class="mt-4 text-slate-300">
      Choose an organization to enter its workspace.
    </p>
    <p v-if="organizations.loading" role="status" class="mt-6">
      Loading organizations…
    </p>
    <p v-if="errorMessage" role="alert" class="mt-5 text-rose-300">
      {{ errorMessage }}
    </p>

    <div
      v-if="!organizations.loading && organizations.organizations.length === 0"
      class="mt-8"
    >
      <h2 class="text-xl font-medium">Create your first organization</h2>
      <p class="mt-2 text-slate-400">
        An organization gives your work its own space.
      </p>
    </div>
    <ul v-else-if="!organizations.loading" class="mt-8 space-y-3">
      <li
        v-for="organization in organizations.organizations"
        :key="organization.id"
      >
        <RouterLink
          :to="{
            name: 'organization',
            params: { organizationId: organization.id },
          }"
          class="block rounded-lg border border-slate-700 p-4 hover:border-teal-300"
          >{{ organization.name }}</RouterLink
        >
      </li>
    </ul>

    <form
      class="mt-8 border-t border-slate-700 pt-6"
      @submit.prevent="createOrganization"
    >
      <label for="organization-name" class="block font-medium"
        >Organization name</label
      >
      <input
        id="organization-name"
        v-model="name"
        required
        maxlength="255"
        class="mt-2 block w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
      />
      <p v-if="nameError" role="alert" class="mt-2 text-rose-300">
        {{ nameError }}
      </p>
      <button
        type="submit"
        :disabled="busy"
        class="mt-4 rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
      >
        Create organization
      </button>
    </form>
    <button
      type="button"
      :disabled="busy"
      class="mt-8 text-slate-400 underline"
      @click="logout"
    >
      Sign out
    </button>
  </section>
</template>
