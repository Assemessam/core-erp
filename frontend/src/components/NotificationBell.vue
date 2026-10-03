<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationScope } from '../composables/useOrganizationNotifications'

const scope = useNotificationScope()
const router = useRouter()
const label = computed(() =>
  scope.unreadCount.value === null
    ? 'Notifications'
    : `Notifications, ${scope.unreadCount.value} unread`,
)
</script>

<template>
  <button
    v-if="scope.organizationId.value && !scope.accessError.value"
    type="button"
    :aria-label="label"
    class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-slate-700 px-3 py-2 text-sm text-teal-300"
    @click="
      router.push({
        name: 'organization-notifications',
        params: { organizationId: scope.organizationId.value },
      })
    "
  >
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      class="h-5 w-5"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"
      />
    </svg>
    <span>Notifications</span>
    <span
      v-if="scope.unreadCount.value && scope.unreadCount.value > 0"
      aria-hidden="true"
      class="rounded-full bg-teal-300 px-2 text-xs font-semibold text-slate-950"
    >
      {{ scope.unreadCount.value > 99 ? '99+' : scope.unreadCount.value }}
    </span>
  </button>
</template>
