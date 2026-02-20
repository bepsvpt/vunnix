<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();

const auth = useAuthStore();
const mobileMenuOpen = ref(false);
const userMenuOpen = ref(false);
const currentUser = computed(() => auth.user || null);

function toggleMenu() {
    mobileMenuOpen.value = !mobileMenuOpen.value;
}

function closeMenu() {
    mobileMenuOpen.value = false;
}

function toggleUserMenu() {
    userMenuOpen.value = !userMenuOpen.value;
}

function closeUserMenu() {
    userMenuOpen.value = false;
}

async function handleLogout() {
    closeUserMenu();
    closeMenu();
    await auth.logout();
}

const navLinks = [
    { to: '/chat', label: 'Chat', icon: '💬' },
    { to: '/dashboard', label: 'Dashboard', icon: '📊' },
    { to: '/admin', label: 'Admin', icon: '⚙️' },
];

function isLinkActive(to: string): boolean {
    return route.path === to || route.path.startsWith(`${to}/`);
}
</script>

<template>
    <nav class="sticky top-0 z-30 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800 shadow-[var(--shadow-nav)]">
        <div class="max-w-[var(--width-content)] mx-auto px-4 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo / Brand -->
                <div class="flex items-center gap-2">
                    <img src="/vunnix-rt.svg" alt="Vunnix" class="h-8">
                </div>

                <!-- Desktop nav links -->
                <div class="hidden md:flex items-center gap-1">
                    <RouterLink
                        v-for="link in navLinks"
                        :key="link.to"
                        :to="link.to"
                        class="flex items-center gap-1.5 px-3 py-2 -mb-px border-b-2 text-sm font-medium transition-colors"
                        :class="isLinkActive(link.to)
                            ? 'border-zinc-900 dark:border-zinc-100 text-zinc-900 dark:text-zinc-100'
                            : 'border-transparent text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:border-zinc-300 dark:hover:border-zinc-600'"
                        @click="closeMenu"
                    >
                        <span class="text-base leading-none">{{ link.icon }}</span>
                        <span>{{ link.label }}</span>
                    </RouterLink>
                </div>

                <div class="flex items-center gap-2">
                    <!-- User menu (desktop) -->
                    <div v-if="currentUser" class="relative hidden md:block">
                        <button
                            class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                            aria-label="User menu"
                            :aria-expanded="userMenuOpen"
                            @click="toggleUserMenu"
                        >
                            <img
                                v-if="currentUser.avatar_url"
                                :src="currentUser.avatar_url"
                                :alt="currentUser.name"
                                class="h-7 w-7 rounded-full"
                            >
                            <span v-else class="h-7 w-7 rounded-full bg-zinc-300 dark:bg-zinc-700 flex items-center justify-center text-xs font-medium">
                                {{ currentUser.name?.charAt(0)?.toUpperCase() }}
                            </span>
                            <span class="max-w-[120px] truncate">{{ currentUser.name }}</span>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Dropdown -->
                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 mt-1 w-48 rounded-[var(--radius-card)] bg-white dark:bg-zinc-800 shadow-[var(--shadow-dropdown)] ring-1 ring-black/5 dark:ring-white/10 py-1 z-50"
                        >
                            <div class="px-3 py-2 text-xs text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-700">
                                {{ currentUser.username }}
                            </div>
                            <button
                                class="w-full text-left px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                                @click="handleLogout"
                            >
                                Log out
                            </button>
                        </div>
                    </div>

                    <!-- Click-away overlay for user menu -->
                    <div
                        v-if="userMenuOpen"
                        class="fixed inset-0 z-40"
                        @click="closeUserMenu"
                    />

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
        </div>

        <!-- Mobile menu panel -->
        <div v-if="mobileMenuOpen" class="md:hidden border-t border-zinc-200 dark:border-zinc-800">
            <div class="px-2 py-2 space-y-1">
                <RouterLink
                    v-for="link in navLinks"
                    :key="link.to"
                    :to="link.to"
                    class="block px-3 py-2 rounded-md text-base font-medium transition-colors"
                    :class="isLinkActive(link.to)
                        ? 'text-zinc-900 dark:text-zinc-100 bg-zinc-100 dark:bg-zinc-800'
                        : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                    @click="closeMenu"
                >
                    {{ link.label }}
                </RouterLink>
            </div>

            <!-- Mobile user section -->
            <div v-if="currentUser" class="border-t border-zinc-200 dark:border-zinc-800 px-2 py-2">
                <div class="flex items-center gap-3 px-3 py-2">
                    <img
                        v-if="currentUser.avatar_url"
                        :src="currentUser.avatar_url"
                        :alt="currentUser.name"
                        class="h-8 w-8 rounded-full"
                    >
                    <span v-else class="h-8 w-8 rounded-full bg-zinc-300 dark:bg-zinc-700 flex items-center justify-center text-sm font-medium">
                        {{ currentUser.name?.charAt(0)?.toUpperCase() }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">
                            {{ currentUser.name }}
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                            {{ currentUser.username }}
                        </p>
                    </div>
                </div>
                <button
                    class="w-full text-left px-3 py-2 rounded-md text-base font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                    @click="handleLogout"
                >
                    Log out
                </button>
            </div>
        </div>
    </nav>
</template>
