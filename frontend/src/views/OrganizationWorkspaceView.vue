<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { useOrganizationStore } from '../stores/organizations'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const organizations = useOrganizationStore()
const errorMessage = ref('')
const inaccessible = ref(false)

async function load(id: string) {
  errorMessage.value = ''
  inaccessible.value = false
  try {
    await organizations.select(id)
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 404)
      inaccessible.value = true
    else errorMessage.value = formFeedback(error).message
  }
}

onMounted(() => load(String(route.params.organizationId)))
watch(
  () => route.params.organizationId,
  (id) => load(String(id)),
)
</script>

<template>
  <section aria-labelledby="workspace-title" class="mx-auto max-w-2xl">
    <p v-if="organizations.loading" role="status">Loading organization…</p>
    <template v-else-if="organizations.current">
      <RouterLink :to="{ name: 'organizations' }" class="text-teal-300"
        >← Organizations</RouterLink
      >
      <h1 id="workspace-title" class="mt-6 text-3xl font-semibold">
        {{ organizations.current.name }}
      </h1>
      <p class="mt-4 text-slate-300">Organization workspace</p>
      <RouterLink
        :to="{
          name: 'organization-roles',
          params: { organizationId: organizations.current.id },
        }"
        class="mt-6 inline-block text-teal-300"
        >Roles &amp; Permissions</RouterLink
      >
      <p class="mt-2 text-slate-400">
        This workspace confirms your organization context. ERP modules arrive in
        later milestones.
      </p>
    </template>
    <template v-else>
      <h1 id="workspace-title" class="text-3xl font-semibold">
        Organization unavailable
      </h1>
      <p v-if="inaccessible" role="alert" class="mt-4">
        This organization could not be found or accessed.
      </p>
      <p v-if="errorMessage" role="alert" class="mt-4">{{ errorMessage }}</p>
      <RouterLink
        :to="{ name: 'organizations' }"
        class="mt-6 inline-block text-teal-300"
        >Choose an organization</RouterLink
      >
    </template>
  </section>
</template>
