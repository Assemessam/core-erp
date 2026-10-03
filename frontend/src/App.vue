<script setup lang="ts">
import { computed, provide } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import NotificationBell from './components/NotificationBell.vue'
import {
  notificationScopeKey,
  useOrganizationNotifications,
} from './composables/useOrganizationNotifications'

const route = useRoute()
const organizationId = computed(() =>
  route.meta.requiresAuth &&
  route.meta.requiresVerified &&
  typeof route.params.organizationId === 'string'
    ? route.params.organizationId
    : null,
)
provide(notificationScopeKey, useOrganizationNotifications(organizationId))
</script>

<template>
  <div class="mx-auto min-h-screen max-w-5xl px-6 py-8 sm:px-10">
    <header
      class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-6"
    >
      <RouterLink to="/" class="text-xl font-semibold tracking-tight"
        >CoreERP</RouterLink
      >
      <NotificationBell />
    </header>
    <main id="main-content" class="py-16 sm:py-24">
      <RouterView />
    </main>
    <footer class="border-t border-slate-800 pt-6 text-sm text-slate-400">
      A portfolio-grade ERP under active development.
    </footer>
  </div>
</template>
