<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <div class="mx-auto min-h-screen max-w-5xl px-6 py-8 sm:px-10">
    <header
      class="flex items-center justify-between border-b border-slate-800 pb-6"
    >
      <RouterLink to="/" class="text-xl font-semibold tracking-tight"
        >CoreERP</RouterLink
      >
      <span class="text-sm text-slate-400">Phase 1.1 · Authentication</span>
    </header>
    <main id="main-content" class="py-16 sm:py-24">
      <RouterView />
    </main>
    <footer class="border-t border-slate-800 pt-6 text-sm text-slate-400">
      A portfolio-grade ERP under active development.
    </footer>
  </div>
</template>
