<script setup>
import { ref } from 'vue';
import { RouterLink } from 'vue-router';

const mobileMenuOpen = ref(false);

function toggleMenu() {
    mobileMenuOpen.value = !mobileMenuOpen.value;
}

function closeMenu() {
    mobileMenuOpen.value = false;
}

const navLinks = [
    { to: '/chat', label: 'Chat', icon: '💬' },
    { to: '/dashboard', label: 'Dashboard', icon: '📊' },
    { to: '/admin', label: 'Admin', icon: '⚙️' },
];
</script>

<template>
  <nav class="bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
    <div class="max-w-7xl mx-auto px-4 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <!-- Logo / Brand -->
        <div class="flex items-center gap-2">
          <span class="text-lg font-semibold">Vunnix</span>
        </div>

        <!-- Desktop nav links -->
        <div class="hidden md:flex items-center gap-1">
          <RouterLink
            v-for="link in navLinks"
            :key="link.to"
            :to="link.to"
            class="px-3 py-2 rounded-md text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
            active-class="!text-zinc-900 dark:!text-zinc-100 bg-zinc-100 dark:bg-zinc-800"
            @click="closeMenu"
          >
            {{ link.label }}
          </RouterLink>
        </div>

        <!-- Mobile hamburger button -->
        <button
          class="md:hidden p-2 rounded-md text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
          :aria-expanded="mobileMenuOpen"
          aria-label="Toggle navigation menu"
          @click="toggleMenu"
        >
          <svg v-if="!mobileMenuOpen" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
          <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Mobile menu panel -->
    <div v-if="mobileMenuOpen" class="md:hidden border-t border-zinc-200 dark:border-zinc-800">
      <div class="px-2 py-2 space-y-1">
        <RouterLink
          v-for="link in navLinks"
          :key="link.to"
          :to="link.to"
          class="block px-3 py-2 rounded-md text-base font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
          active-class="!text-zinc-900 dark:!text-zinc-100 bg-zinc-100 dark:bg-zinc-800"
          @click="closeMenu"
        >
          {{ link.label }}
        </RouterLink>
      </div>
    </div>
  </nav>
</template>
