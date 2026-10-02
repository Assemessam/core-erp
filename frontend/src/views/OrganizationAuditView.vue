<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { organizationApi, type Organization } from '../lib/organizations'
import {
  auditApi,
  auditActions,
  auditSubjects,
  actionLabel,
  actorLabel,
  auditChanges,
  auditTime,
  type AuditEvent,
  type AuditFilters,
  type AuditAction,
  type AuditSubjectType,
} from '../lib/audit'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const organization = ref<Organization | null>(null)
const events = ref<AuditEvent[]>([])
const nextCursor = ref<string | null>(null)
const busy = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string>>({})
const sessionEnded = ref(false)
const action = ref<AuditAction | ''>('')
const subjectType = ref<AuditSubjectType | ''>('')
const subjectId = ref('')
let applied: AuditFilters = {}
let generation = 0

function showError(error: unknown) {
  const status = isAxiosError(error) ? error.response?.status : undefined
  if (status === 401 || status === 403 || status === 404) {
    events.value = []
    nextCursor.value = null
    loaded.value = false
    if (status === 404) organization.value = null
  }
  sessionEnded.value = status === 401
  const feedback = formFeedback(error)
  errorMessage.value =
    status === 403
      ? 'You do not have permission to view this audit trail.'
      : feedback.message
  fieldErrors.value = feedback.fields
}

async function fetchPage(current: number, id: string, cursor?: string) {
  try {
    const page = await auditApi.list(id, { ...applied }, cursor)
    if (current !== generation) return
    events.value = cursor ? [...events.value, ...page.data] : page.data
    nextCursor.value = page.meta.has_more ? page.meta.next_cursor : null
    loaded.value = true
  } catch (error) {
    if (current === generation) showError(error)
  } finally {
    if (current === generation) busy.value = false
  }
}

async function start(filters: AuditFilters = applied) {
  const current = ++generation
  const id = String(route.params.organizationId)
  applied = { ...filters }
  events.value = []
  nextCursor.value = null
  organization.value = null
  busy.value = true
  loaded.value = false
  errorMessage.value = ''
  fieldErrors.value = {}
  sessionEnded.value = false
  try {
    const context = await organizationApi.context(id)
    if (current !== generation) return
    organization.value = context.data
    if (context.meta.can_view_audit !== true) {
      errorMessage.value =
        'You do not have permission to view this audit trail.'
      busy.value = false
      return
    }
    // Capability is navigation feedback; the endpoint independently authorizes this request.
    await fetchPage(current, id)
  } catch (error) {
    if (current === generation) showError(error)
  } finally {
    if (current === generation) busy.value = false
  }
}

function applyFilters() {
  errorMessage.value = ''
  fieldErrors.value = {}
  if (Boolean(subjectType.value) !== Boolean(subjectId.value.trim())) {
    fieldErrors.value = { subject: 'Choose a subject type and ID together.' }
    return
  }
  void start({
    ...(action.value ? { action: action.value } : {}),
    ...(subjectType.value
      ? { subject_type: subjectType.value, subject_id: subjectId.value.trim() }
      : {}),
  })
}

function clearFilters() {
  action.value = ''
  subjectType.value = ''
  subjectId.value = ''
  void start({})
}

function loadMore() {
  if (busy.value || !nextCursor.value) return
  busy.value = true
  errorMessage.value = ''
  fieldErrors.value = {}
  void fetchPage(
    generation,
    String(route.params.organizationId),
    nextCursor.value,
  )
}

watch(
  () => route.params.organizationId,
  () => clearFilters(),
  { immediate: true },
)
onBeforeUnmount(() => generation++)
</script>

<template>
  <section aria-labelledby="audit-title" class="mx-auto max-w-4xl">
    <RouterLink
      :to="{
        name: 'organization',
        params: { organizationId: route.params.organizationId },
      }"
      class="text-teal-300"
      >← Organization workspace</RouterLink
    >
    <h1 id="audit-title" class="mt-6 text-3xl font-semibold">Audit Trail</h1>
    <p v-if="organization" class="mt-2 text-slate-300">
      {{ organization.name }}
    </p>
    <p class="mt-3 text-slate-400">
      Recorded changes, newest first. Times are shown in UTC. Users and subjects
      are identified by their stable IDs.
    </p>
    <form
      class="mt-6 rounded-lg border border-slate-700 p-4"
      @submit.prevent="applyFilters"
    >
      <fieldset :disabled="busy" class="grid gap-4 sm:grid-cols-3">
        <div>
          <label for="audit-action">Action</label>
          <select
            id="audit-action"
            v-model="action"
            class="mt-2 block w-full rounded-lg border border-slate-600 bg-slate-900 p-2"
          >
            <option value="">All actions</option>
            <option
              v-for="item in auditActions"
              :key="item.key"
              :value="item.key"
            >
              {{ item.label }}
            </option>
          </select>
        </div>
        <div>
          <label for="audit-subject-type">Subject type</label>
          <select
            id="audit-subject-type"
            v-model="subjectType"
            class="mt-2 block w-full rounded-lg border border-slate-600 bg-slate-900 p-2"
          >
            <option value="">All subjects</option>
            <option v-for="type in auditSubjects" :key="type" :value="type">
              {{ type }}
            </option>
          </select>
        </div>
        <div>
          <label for="audit-subject-id">Subject ID</label>
          <input
            id="audit-subject-id"
            v-model="subjectId"
            maxlength="64"
            class="mt-2 block w-full rounded-lg border border-slate-600 bg-slate-900 p-2"
          />
        </div>
      </fieldset>
      <div class="mt-4 flex flex-wrap gap-4">
        <button
          type="submit"
          :disabled="busy"
          class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
        >
          Apply filters
        </button>
        <button
          type="button"
          :disabled="busy"
          class="text-teal-300 disabled:opacity-50"
          @click="clearFilters"
        >
          Clear filters
        </button>
        <button
          type="button"
          :disabled="busy"
          class="text-teal-300 disabled:opacity-50"
          @click="start()"
        >
          Refresh
        </button>
      </div>
    </form>
    <p v-if="busy" role="status" class="mt-6">Loading audit history…</p>
    <p v-if="errorMessage" role="alert" class="mt-6 text-rose-300">
      {{ errorMessage }}
    </p>
    <p
      v-for="(message, field) in fieldErrors"
      :key="field"
      role="alert"
      class="mt-3 text-rose-300"
    >
      {{ message }}
    </p>
    <RouterLink
      v-if="sessionEnded"
      :to="{ name: 'login', query: { redirect: route.fullPath } }"
      class="mt-3 inline-block text-teal-300"
      >Sign in again</RouterLink
    >
    <template v-if="loaded">
      <p v-if="events.length === 0" role="status" class="mt-6">
        No audit events match these filters.
      </p>
      <ol v-else aria-label="Audit events" class="mt-6 space-y-4">
        <li
          v-for="event in events"
          :key="event.id"
          :data-event-id="event.id"
          class="rounded-lg border border-slate-700 p-4"
        >
          <h2 class="text-lg font-semibold">{{ actionLabel(event.action) }}</h2>
          <time
            :datetime="event.created_at"
            :title="event.created_at"
            class="mt-2 block text-sm text-slate-400"
            >{{ auditTime(event.created_at) }}</time
          >
          <p class="mt-2">{{ actorLabel(event) }}</p>
          <p class="mt-1 break-all text-sm text-slate-300">
            Subject: {{ event.subject.type }} #{{ event.subject.id }}
          </p>
          <details class="mt-3">
            <summary class="cursor-pointer text-teal-300">View changes</summary>
            <p v-if="event.payload_version !== 1" class="mt-3 text-slate-300">
              Change details are unavailable for this event format (version
              {{ event.payload_version }}).
            </p>
            <div v-else class="mt-3 overflow-x-auto">
              <table
                v-if="auditChanges(event).length"
                class="w-full text-left text-sm"
              >
                <caption class="sr-only">
                  Before and after changes for
                  {{
                    actionLabel(event.action)
                  }}
                </caption>
                <thead>
                  <tr>
                    <th scope="col" class="p-2">Field</th>
                    <th scope="col" class="p-2">Before</th>
                    <th scope="col" class="p-2">After</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="change in auditChanges(event)" :key="change.key">
                    <th scope="row" class="p-2">{{ change.label }}</th>
                    <td class="max-w-xs break-words p-2">
                      {{ change.before }}
                    </td>
                    <td class="max-w-xs break-words p-2">{{ change.after }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-else>No supported change details are available.</p>
            </div>
          </details>
        </li>
      </ol>
      <button
        v-if="nextCursor"
        type="button"
        :disabled="busy"
        class="mt-6 rounded-lg border border-teal-300 px-4 py-2 text-teal-300 disabled:opacity-50"
        @click="loadMore"
      >
        Load more
      </button>
      <p v-else-if="events.length" role="status" class="mt-6 text-slate-400">
        End of audit history.
      </p>
    </template>
  </section>
</template>
