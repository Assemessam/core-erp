<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useNotificationScope } from '../composables/useOrganizationNotifications'
import {
  notificationAccessLost,
  notificationApi,
  notificationDestination,
  notificationTime,
  supportedNotification,
  type NotificationItem,
} from '../lib/notifications'
import { organizationApi, type Organization } from '../lib/organizations'
import { formFeedback } from '../lib/httpError'

const route = useRoute()
const scope = useNotificationScope()
const organization = ref<Organization | null>(null)
const rows = ref<NotificationItem[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(false)
const continuing = ref(false)
const loaded = ref(false)
const mutation = ref<string | null>(null)
const errorMessage = ref('')
const mutationError = ref('')
const confirmedRead = ref(new Set<string>())
let generation = 0
let controller = new globalThis.AbortController()
const id = () => String(route.params.organizationId)
const current = (token: number, epoch: number, organizationId: string) =>
  token === generation &&
  epoch === scope.epoch.value &&
  organizationId === id() &&
  organizationId === scope.organizationId.value &&
  !scope.accessError.value

function clear() {
  generation++
  controller?.abort()
  rows.value = []
  nextCursor.value = null
  organization.value = null
  loading.value = false
  continuing.value = false
  loaded.value = false
  mutation.value = null
  confirmedRead.value = new Set()
  errorMessage.value = ''
  mutationError.value = ''
}

function showError(error: unknown, epoch: number) {
  if (notificationAccessLost(error)) scope.reportAccessLoss(error, epoch)
  else errorMessage.value = formFeedback(error).message
}

async function firstPage() {
  clear()
  const token = generation
  const epoch = scope.epoch.value
  const organizationId = id()
  if (!current(token, epoch, organizationId)) return
  loading.value = true
  controller = new globalThis.AbortController()
  try {
    void organizationApi
      .get(organizationId)
      .then((context) => {
        if (current(token, epoch, organizationId)) organization.value = context
      })
      .catch((error) => {
        if (
          current(token, epoch, organizationId) &&
          notificationAccessLost(error)
        )
          scope.reportAccessLoss(error, epoch)
      })
    const page = await notificationApi.list(
      organizationId,
      undefined,
      controller.signal,
    )
    if (!current(token, epoch, organizationId)) return
    rows.value = page.data
    nextCursor.value = page.meta.has_more ? page.meta.next_cursor : null
    loaded.value = true
  } catch (error) {
    if (current(token, epoch, organizationId)) showError(error, epoch)
  } finally {
    if (current(token, epoch, organizationId)) loading.value = false
  }
}

function refresh() {
  if (scope.accessError.value)
    scope.retry() // Explicit recheck, never automatic polling after denial.
  else {
    void firstPage()
    void scope.refreshCount(true)
  }
}

async function loadOlder() {
  if (
    loading.value ||
    continuing.value ||
    mutation.value ||
    !nextCursor.value ||
    scope.accessError.value
  )
    return
  const token = generation
  const epoch = scope.epoch.value
  const organizationId = id()
  continuing.value = true
  errorMessage.value = ''
  try {
    const page = await notificationApi.list(organizationId, nextCursor.value)
    if (!current(token, epoch, organizationId)) return
    const seen = new Set(rows.value.map((row) => row.id))
    rows.value = [
      ...rows.value,
      ...page.data.filter((row) => {
        if (seen.has(row.id)) return false
        seen.add(row.id)
        return true
      }),
    ]
    nextCursor.value = page.meta.has_more ? page.meta.next_cursor : null
  } catch (error) {
    if (current(token, epoch, organizationId)) showError(error, epoch)
  } finally {
    if (current(token, epoch, organizationId)) continuing.value = false
  }
}

const isRead = (row: NotificationItem) =>
  row.read_at !== null || confirmedRead.value.has(row.id)

async function markRead(row?: NotificationItem) {
  if (
    mutation.value ||
    loading.value ||
    continuing.value ||
    scope.accessError.value ||
    (row && isRead(row))
  )
    return
  const token = generation
  const epoch = scope.epoch.value
  const organizationId = id()
  mutation.value = row?.id ?? 'all'
  mutationError.value = ''
  try {
    if (row) await notificationApi.markRead(organizationId, row.id)
    else await notificationApi.markAllRead(organizationId)
    if (!current(token, epoch, organizationId)) return
    if (row) {
      // A successful 204 confirms read state; no server timestamp is invented.
      confirmedRead.value = new Set([...confirmedRead.value, row.id])
      await scope.refreshCount(true)
    } else {
      await Promise.all([firstPage(), scope.refreshCount(true)])
    }
  } catch (error) {
    if (!current(token, epoch, organizationId)) return
    if (notificationAccessLost(error)) scope.reportAccessLoss(error, epoch)
    else mutationError.value = formFeedback(error).message
  } finally {
    if (current(token, epoch, organizationId)) mutation.value = null
  }
}

watch(
  () => scope.epoch.value,
  () => {
    void firstPage()
  },
  { immediate: true, flush: 'sync' },
)
onBeforeUnmount(clear)
</script>

<template>
  <section
    aria-labelledby="notifications-title"
    class="mx-auto max-w-4xl min-w-0"
  >
    <RouterLink
      :to="{ name: 'organization', params: { organizationId: id() } }"
      class="text-teal-300"
      >← Organization workspace</RouterLink
    >
    <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h1 id="notifications-title" class="text-3xl font-semibold">
          Notifications
        </h1>
        <p v-if="organization" class="mt-2 break-words text-slate-300">
          {{ organization.name }}
        </p>
      </div>
      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          :disabled="Boolean(mutation)"
          class="rounded-lg border border-slate-600 px-4 py-2 text-teal-300 disabled:opacity-50"
          @click="refresh"
        >
          Refresh
        </button>
        <button
          type="button"
          :disabled="
            loading ||
            continuing ||
            Boolean(mutation) ||
            !scope.unreadCount.value ||
            Boolean(scope.accessError.value)
          "
          class="rounded-lg bg-teal-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
          @click="markRead()"
        >
          Mark all as read
        </button>
      </div>
    </div>
    <p class="mt-4 text-slate-400">
      Newest first. Times are shown in UTC. Refresh to see new notifications;
      opening this page does not mark them read.
    </p>
    <p
      v-if="scope.unreadCount.value !== null"
      role="status"
      class="mt-3 text-slate-300"
    >
      {{ scope.unreadCount.value }} unread
    </p>
    <p v-if="scope.countError.value" role="status" class="mt-3 text-slate-400">
      {{ scope.countError.value }}
    </p>
    <p v-if="scope.accessError.value" role="alert" class="mt-6 text-rose-300">
      {{ scope.accessError.value }}
    </p>
    <RouterLink
      v-if="scope.accessError.value"
      :to="{ name: 'organizations' }"
      class="mt-3 inline-block text-teal-300"
      >Choose an organization</RouterLink
    >
    <p v-if="loading" role="status" class="mt-6">Loading notifications…</p>
    <p v-if="errorMessage" role="alert" class="mt-6 text-rose-300">
      {{ loaded ? 'Older notifications could not be loaded. ' : ''
      }}{{ errorMessage }}
    </p>
    <p v-if="mutationError" role="alert" class="mt-6 text-rose-300">
      Read state could not be updated. {{ mutationError }}
    </p>
    <p
      v-if="loaded && rows.length === 0"
      role="status"
      class="mt-6 rounded-lg border border-slate-700 p-6"
    >
      No notifications yet.
    </p>
    <ol
      v-if="loaded && rows.length"
      aria-label="Notifications"
      class="mt-6 space-y-4"
    >
      <li v-for="row in rows" :key="row.id" :data-notification-id="row.id">
        <article
          class="min-w-0 rounded-lg border p-5"
          :class="
            isRead(row) ? 'border-slate-700' : 'border-teal-700 bg-slate-900'
          "
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="min-w-0 break-words text-lg font-semibold">
              {{ row.title }}
            </h2>
            <span
              class="rounded-full border border-slate-600 px-2 py-1 text-xs"
              >{{ isRead(row) ? 'Read' : 'Unread' }}</span
            >
          </div>
          <p class="mt-3 whitespace-pre-wrap break-words text-slate-300">
            {{ row.body }}
          </p>
          <time
            :datetime="row.created_at"
            :title="row.created_at"
            class="mt-3 block break-words text-sm text-slate-400"
            >{{ notificationTime(row.created_at) }}</time
          >
          <p
            v-if="!supportedNotification(row)"
            class="mt-3 text-sm text-slate-400"
          >
            Unsupported notification format. Stored text is shown.
          </p>
          <div class="mt-4 flex flex-wrap items-center gap-5">
            <button
              v-if="!isRead(row)"
              type="button"
              :disabled="Boolean(mutation) || continuing"
              class="text-teal-300 disabled:opacity-50"
              @click="markRead(row)"
            >
              {{ mutation === row.id ? 'Marking as read…' : 'Mark as read' }}
            </button>
            <RouterLink
              v-if="notificationDestination(row, id())"
              :to="notificationDestination(row, id())!"
              class="text-teal-300"
              :aria-label="`View ${row.title}`"
              >View</RouterLink
            >
          </div>
        </article>
      </li>
    </ol>
    <p v-if="continuing" role="status" class="mt-6">
      Loading older notifications…
    </p>
    <p v-if="mutation === 'all'" role="status" class="mt-6">
      Marking all as read…
    </p>
    <button
      v-if="loaded && nextCursor"
      type="button"
      :disabled="continuing || Boolean(mutation)"
      class="mt-6 rounded-lg border border-slate-600 px-4 py-2 text-teal-300 disabled:opacity-50"
      @click="loadOlder"
    >
      Load older
    </button>
    <p v-else-if="loaded && rows.length" class="mt-6 text-slate-400">
      End of notification history.
    </p>
  </section>
</template>
