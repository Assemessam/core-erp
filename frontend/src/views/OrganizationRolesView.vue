<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { roleApi, type Role, type Permission } from '../lib/roles'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const roles = ref<Role[]>([])
const permissions = ref<Permission[]>([])
const canManage = ref(false)
const loading = ref(true)
const loaded = ref(false)
const busy = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string>>({})
const success = ref('')
const editingId = ref<string | null>(null)
const name = ref('')
const selectedPermissions = ref<string[]>([])
let generation = 0

function resetEditor() {
  editingId.value = null
  name.value = ''
  selectedPermissions.value = []
}

function showError(error: unknown) {
  if (isAxiosError(error) && error.response?.status === 403) {
    errorMessage.value = 'You do not have permission to access or manage roles.'
    canManage.value = false
  } else if (isAxiosError(error) && error.response?.status === 404) {
    errorMessage.value =
      'This organization or role could not be found or accessed.'
  } else {
    const feedback = formFeedback(error)
    errorMessage.value = feedback.message
    fieldErrors.value = feedback.fields
  }
}

async function load(organizationId: string) {
  const current = ++generation
  roles.value = []
  permissions.value = []
  canManage.value = false
  loaded.value = false
  loading.value = true
  busy.value = false
  errorMessage.value = ''
  fieldErrors.value = {}
  success.value = ''
  resetEditor()
  try {
    const [list, catalog] = await Promise.all([
      roleApi.list(organizationId),
      roleApi.permissions(organizationId),
    ])
    if (current !== generation) return
    roles.value = list.data
    permissions.value = catalog
    // Presentation only; every API mutation is authorized on the server.
    canManage.value = list.meta.can_manage
    loaded.value = true
  } catch (error) {
    if (current === generation) showError(error)
  } finally {
    if (current === generation) loading.value = false
  }
}

function edit(role: Role) {
  editingId.value = role.id
  name.value = role.name
  selectedPermissions.value = [...role.permissions]
  success.value = ''
  errorMessage.value = ''
  fieldErrors.value = {}
}

async function save() {
  const current = generation
  busy.value = true
  errorMessage.value = ''
  fieldErrors.value = {}
  success.value = ''
  try {
    const role = await roleApi.save(
      String(route.params.organizationId),
      editingId.value,
      { name: name.value, permissions: [...selectedPermissions.value] },
    )
    if (current !== generation) return
    roles.value = [
      ...roles.value.filter((item) => item.id !== role.id),
      role,
    ].sort((a, b) => a.name.localeCompare(b.name))
    resetEditor()
    success.value = 'Role saved.'
  } catch (error) {
    if (current === generation) showError(error)
  } finally {
    if (current === generation) busy.value = false
  }
}

watch(
  () => route.params.organizationId,
  (id) => load(String(id)),
  { immediate: true },
)
</script>

<template>
  <section aria-labelledby="roles-title" class="mx-auto max-w-3xl">
    <RouterLink
      :to="{
        name: 'organization',
        params: { organizationId: route.params.organizationId },
      }"
      class="text-teal-300"
      >← Organization workspace</RouterLink
    >
    <h1 id="roles-title" class="mt-6 text-3xl font-semibold">
      Roles &amp; Permissions
    </h1>
    <p class="mt-3 text-slate-400">
      Define capabilities for this organization. The owner manages roles.
      Assigning roles to members arrives with member management.
    </p>
    <p v-if="loading" role="status" class="mt-6">Loading roles…</p>
    <p v-if="errorMessage" role="alert" class="mt-6 text-rose-300">
      {{ errorMessage }}
    </p>
    <p v-if="success" role="status" class="mt-6 text-teal-300">{{ success }}</p>
    <template v-if="loaded">
      <p v-if="!canManage" class="mt-6 text-slate-300">
        Read-only access. Only the organization owner can change roles.
      </p>
      <p v-if="roles.length === 0" class="mt-6 text-slate-300">
        No roles yet. Ownership already grants full organization authority.
      </p>
      <ul v-else class="mt-6 space-y-3" aria-label="Organization roles">
        <li
          v-for="role in roles"
          :key="role.id"
          class="rounded-lg border border-slate-700 p-4"
        >
          <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold">{{ role.name }}</h2>
            <button
              v-if="canManage"
              type="button"
              :disabled="busy"
              :aria-label="`Edit ${role.name}`"
              class="text-teal-300 disabled:opacity-50"
              @click="edit(role)"
            >
              Edit
            </button>
          </div>
          <p class="mt-2 text-sm text-slate-400">
            {{ role.permissions.join(', ') || 'No permissions assigned' }}
          </p>
        </li>
      </ul>
      <form
        v-if="canManage"
        class="mt-8 rounded-lg border border-slate-700 p-5"
        @submit.prevent="save"
      >
        <h2 class="text-xl font-semibold">
          {{ editingId ? 'Edit role' : 'Create role' }}
        </h2>
        <fieldset :disabled="busy" class="mt-4">
          <label for="role-name" class="block font-medium">Role name</label>
          <input
            id="role-name"
            v-model="name"
            required
            maxlength="80"
            class="mt-2 block w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2"
          />
          <p class="mt-2 text-sm text-slate-400">
            Names are unique within this organization, ignoring case and
            surrounding spaces.
          </p>
          <fieldset class="mt-5 space-y-3">
            <legend class="mb-3 font-medium">Permissions</legend>
            <label
              v-for="permission in permissions"
              :key="permission.key"
              class="flex items-start gap-3"
            >
              <input
                v-model="selectedPermissions"
                type="checkbox"
                :value="permission.key"
                class="mt-1"
              />
              <span
                >{{ permission.label
                }}<span class="block text-sm text-slate-400">{{
                  permission.key
                }}</span></span
              >
            </label>
          </fieldset>
          <p
            v-for="(message, field) in fieldErrors"
            :key="field"
            role="alert"
            class="mt-3 text-rose-300"
          >
            {{ message }}
          </p>
          <div class="mt-6 flex gap-4">
            <button
              type="submit"
              class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
              {{ busy ? 'Saving…' : 'Save role' }}
            </button>
            <button
              v-if="editingId"
              type="button"
              class="text-slate-300"
              @click="resetEditor"
            >
              Cancel edit
            </button>
          </div>
        </fieldset>
      </form>
      <div v-else class="mt-8 border-t border-slate-700 pt-5">
        <h2 class="text-xl font-semibold">Available permissions</h2>
        <ul class="mt-3 space-y-2 text-slate-300">
          <li v-for="permission in permissions" :key="permission.key">
            {{ permission.label }} ({{ permission.key }})
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
