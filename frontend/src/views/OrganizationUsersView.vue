<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import {
  usersApi,
  type Member,
  type Invitation,
  type UsersAccess,
} from '../lib/organizationUsers'
import { roleApi, type Role } from '../lib/roles'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const access = ref<UsersAccess | null>(null)
const members = ref<Member[]>([])
const invitations = ref<Invitation[]>([])
const roles = ref<Role[]>([])
const email = ref('')
const selectedRoles = ref<string[]>([])
const editing = ref<Member | null>(null)
const memberRoles = ref<string[]>([])
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const status = ref('')
let generation = 0
const id = () => String(route.params.organizationId)

async function load() {
  const current = ++generation
  const organization = id()
  access.value = null
  members.value = []
  invitations.value = []
  roles.value = []
  editing.value = null
  selectedRoles.value = []
  email.value = ''
  loading.value = true
  error.value = ''
  try {
    const permissions = await usersApi.access(organization)
    const [memberList, invitationList, roleList] = await Promise.all([
      permissions.can_view ? usersApi.members(organization) : [],
      permissions.can_invite ? usersApi.invitations(organization) : [],
      permissions.can_manage
        ? roleApi.list(organization).then((result) => result.data)
        : [],
    ])
    if (current !== generation) return
    access.value = permissions
    members.value = memberList
    invitations.value = invitationList
    roles.value = roleList
  } catch (failure) {
    if (current === generation) error.value = formFeedback(failure).message
  } finally {
    if (current === generation) loading.value = false
  }
}
watch(
  () => route.params.organizationId,
  () => {
    status.value = ''
    void load()
  },
  { immediate: true },
)

async function run(operation: () => Promise<unknown>, message: string) {
  if (busy.value) return
  const current = generation
  busy.value = true
  error.value = ''
  status.value = ''
  try {
    await operation()
    if (current !== generation) return
    await load()
    if (generation === current + 1) status.value = message
  } catch (failure) {
    if (current !== generation) return
    const feedback = formFeedback(failure)
    error.value = Object.values(feedback.fields).join(' ') || feedback.message
    if (
      isAxiosError(failure) &&
      [403, 404].includes(failure.response?.status ?? 0)
    )
      access.value = null
  } finally {
    busy.value = false
  }
}
function edit(member: Member) {
  editing.value = member
  memberRoles.value = member.roles.map((role) => role.id)
}
function remove(member: Member) {
  if (globalThis.confirm(`Remove ${member.email} from this organization?`))
    void run(
      () => usersApi.lifecycle(id(), member.id, 'remove'),
      'Member removed.',
    )
}
</script>

<template>
  <section class="mx-auto max-w-4xl space-y-6">
    <RouterLink
      :to="{ name: 'organization', params: { organizationId: id() } }"
      class="text-teal-300"
      >← Organization workspace</RouterLink
    >
    <h1 class="text-3xl font-semibold">Organization Users</h1>
    <p v-if="loading" role="status">Loading users…</p>
    <p v-if="error" role="alert" class="text-rose-300">{{ error }}</p>
    <p v-if="status" role="status" class="text-teal-300">{{ status }}</p>
    <p v-if="access && !access.can_view && !access.can_invite">
      You do not have permission to view members or manage invitations.
    </p>
    <section v-if="access?.can_view" class="space-y-4">
      <h2 class="text-xl font-semibold">Members</h2>
      <ul aria-label="Organization members" class="space-y-3">
        <li
          v-for="member in members"
          :key="member.id"
          class="rounded-lg border border-slate-700 p-4"
        >
          <h3 class="font-semibold">
            {{ member.name }}
            <span v-if="member.is_owner" class="text-teal-300"> · Owner</span>
          </h3>
          <p>{{ member.email }}</p>
          <p class="text-slate-400">
            {{ member.status }} ·
            {{ member.roles.map((role) => role.name).join(', ') || 'No roles' }}
          </p>
          <div v-if="access.can_manage" class="mt-3 flex gap-4 text-teal-300">
            <button
              :disabled="busy"
              :aria-label="`Assign roles to ${member.email}`"
              @click="edit(member)"
            >
              Assign roles
            </button>
            <template v-if="!member.is_owner">
              <button
                v-if="member.status === 'active'"
                :disabled="busy"
                @click="
                  run(
                    () => usersApi.lifecycle(id(), member.id, 'suspend'),
                    'Member suspended.',
                  )
                "
              >
                Suspend
              </button>
              <button
                v-else
                :disabled="busy"
                @click="
                  run(
                    () => usersApi.lifecycle(id(), member.id, 'activate'),
                    'Member reactivated.',
                  )
                "
              >
                Reactivate
              </button>
              <button
                :disabled="busy"
                class="text-rose-300"
                @click="remove(member)"
              >
                Remove
              </button>
            </template>
          </div>
        </li>
      </ul>
      <form
        v-if="editing && access.can_manage"
        class="space-y-3 rounded-lg border border-slate-600 p-4"
        @submit.prevent="
          run(
            () => usersApi.roles(id(), editing!.id, memberRoles),
            'Roles saved.',
          )
        "
      >
        <h3>Roles for {{ editing.email }}</h3>
        <label
          v-for="role in roles"
          :key="role.id"
          class="mr-4 inline-flex gap-2"
          ><input v-model="memberRoles" type="checkbox" :value="role.id" />{{
            role.name
          }}</label
        >
        <p v-if="!roles.length">
          Create roles in Roles &amp; Permissions first.
        </p>
        <div class="flex gap-4">
          <button :disabled="busy" class="text-teal-300">
            Save member roles</button
          ><button type="button" @click="editing = null">Cancel</button>
        </div>
      </form>
    </section>
    <section v-if="access?.can_invite" class="space-y-4">
      <h2 class="text-xl font-semibold">Invitations</h2>
      <form
        class="space-y-3 rounded-lg border border-slate-700 p-4"
        @submit.prevent="
          run(
            () => usersApi.invite(id(), email, selectedRoles),
            'Invitation sent.',
          )
        "
      >
        <label for="invite-email" class="block">Invite email</label>
        <input
          id="invite-email"
          v-model="email"
          required
          type="email"
          class="w-full rounded border border-slate-600 bg-slate-900 px-3 py-2"
        />
        <fieldset v-if="access.can_manage">
          <legend>Invitation roles</legend>
          <label
            v-for="role in roles"
            :key="role.id"
            class="mr-4 inline-flex gap-2"
            ><input
              v-model="selectedRoles"
              type="checkbox"
              :value="role.id"
            />{{ role.name }}</label
          >
        </fieldset>
        <p v-else class="text-slate-400">
          Invitations you send have no roles. The owner manages role grants.
        </p>
        <button
          :disabled="busy"
          class="rounded bg-teal-300 px-4 py-2 text-slate-950"
        >
          Send invitation
        </button>
      </form>
      <p v-if="!invitations.length">No pending invitations.</p>
      <ul aria-label="Pending invitations" class="space-y-3">
        <li
          v-for="invitation in invitations"
          :key="invitation.id"
          class="rounded-lg border border-slate-700 p-4"
        >
          <p class="font-semibold">{{ invitation.email }}</p>
          <p>
            {{ invitation.state }} · Expires
            {{ new Date(invitation.expires_at).toLocaleString() }}
          </p>
          <p>
            {{
              invitation.roles.map((role) => role.name).join(', ') || 'No roles'
            }}
          </p>
          <div
            v-if="access.can_manage || invitation.roles.length === 0"
            class="mt-3 flex gap-4 text-teal-300"
          >
            <button
              :disabled="busy"
              @click="
                run(
                  () => usersApi.revoke(id(), invitation.id),
                  'Invitation revoked.',
                )
              "
            >
              Revoke
            </button>
            <button
              :disabled="busy"
              @click="
                run(
                  () =>
                    usersApi.invite(
                      id(),
                      invitation.email,
                      invitation.roles.map((role) => role.id),
                    ),
                  'New invitation sent. Previous link revoked.',
                )
              "
            >
              Reinvite
            </button>
          </div>
        </li>
      </ul>
    </section>
  </section>
</template>
