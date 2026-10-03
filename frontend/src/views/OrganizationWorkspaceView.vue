<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { organizationApi, type Organization } from '../lib/organizations'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const organization = ref<Organization | null>(null)
const loading = ref(true)
const canViewAudit = ref(false)
const errorMessage = ref('')
const inaccessible = ref(false)
let generation = 0

async function load(id: string) {
  const current = ++generation
  organization.value = null
  canViewAudit.value = false
  loading.value = true
  errorMessage.value = ''
  inaccessible.value = false
  try {
    const context = await organizationApi.context(id)
    if (current !== generation) return
    organization.value = context.data
    canViewAudit.value = context.meta.can_view_audit === true
  } catch (error) {
    if (current !== generation) return
    if (isAxiosError(error) && error.response?.status === 404)
      inaccessible.value = true
    else errorMessage.value = formFeedback(error).message
  } finally {
    if (current === generation) loading.value = false
  }
}

watch(
  () => route.params.organizationId,
  (id) => load(String(id)),
  { immediate: true },
)
onBeforeUnmount(() => generation++)
</script>

<template>
  <section aria-labelledby="workspace-title" class="mx-auto max-w-2xl">
    <p v-if="loading" role="status">Loading organization…</p>
    <template v-else-if="organization">
      <RouterLink :to="{ name: 'organizations' }" class="text-teal-300"
        >← Organizations</RouterLink
      >
      <h1 id="workspace-title" class="mt-6 text-3xl font-semibold">
        {{ organization.name }}
      </h1>
      <p class="mt-4 text-slate-300">Organization workspace</p>
      <nav
        aria-label="Organization workspace"
        class="mt-6 flex flex-wrap gap-x-6 gap-y-3"
      >
        <RouterLink
          :to="{
            name: 'organization-roles',
            params: { organizationId: organization.id },
          }"
          class="text-teal-300"
          >Roles &amp; Permissions</RouterLink
        >
        <RouterLink
          :to="{
            name: 'organization-users',
            params: { organizationId: organization.id },
          }"
          class="text-teal-300"
          >Users</RouterLink
        >
        <RouterLink
          v-if="canViewAudit"
          :to="{
            name: 'organization-audit',
            params: { organizationId: organization.id },
          }"
          class="text-teal-300"
          >Audit Trail</RouterLink
        >
        <RouterLink
          :to="{
            name: 'organization-notifications',
            params: { organizationId: organization.id },
          }"
          class="text-teal-300"
          >Notifications</RouterLink
        >
      </nav>
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
