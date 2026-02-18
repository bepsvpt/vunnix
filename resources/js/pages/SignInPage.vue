<script setup lang="ts">
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

function signInWithGitLab(): void {
    auth.login();
}
</script>

<template>
    <div class="relative min-h-dvh flex items-center justify-center bg-zinc-950 overflow-hidden">
        <!-- Ambient gradient orbs echoing the logo's green→blue→purple palette -->
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute top-[15%] -left-[10%] w-[500px] h-[500px] rounded-full bg-[#00dc82]/[0.07] blur-[100px]" />
            <div class="absolute top-[20%] -right-[8%] w-[420px] h-[420px] rounded-full bg-[#3b82f6]/[0.06] blur-[100px]" />
            <div class="absolute -bottom-[15%] left-[25%] w-[460px] h-[460px] rounded-full bg-[#8b5cf6]/[0.06] blur-[100px]" />
        </div>

        <!-- Centered content -->
        <div class="relative z-10 flex flex-col items-center px-6">
            <!-- Logo (includes "VUNNIX" text) -->
            <img
                src="/vunnix-ut.svg"
                alt="Vunnix"
                class="w-28 h-28 mb-4 drop-shadow-[0_0_24px_rgba(59,130,246,0.25)]"
            >

            <!-- Tagline -->
            <p class="text-zinc-400 text-sm tracking-wide mb-10 text-center">
                AI First for GitLab
            </p>

            <!-- Sign in button -->
            <button
                class="group flex items-center gap-3 px-6 py-3 rounded-[var(--radius-button)] bg-white/10 text-zinc-100 ring-1 ring-white/15 font-medium text-sm backdrop-blur-sm hover:bg-white/15 hover:ring-white/25 active:bg-white/[0.08] transition-all duration-150"
                @click="signInWithGitLab"
            >
                <!-- GitLab Tanuki icon -->
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 380 380" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M190.4 350.3 252 161.5H128.8l61.6 188.8Z" fill="#E24329" />
                    <path d="m190.4 350.3-61.6-188.8H26.2l164.2 188.8Z" fill="#FC6D26" />
                    <path d="M26.2 161.5 5.4 225.6c-1.9 5.8.1 12.2 5 15.7l180 130.9L26.2 161.5Z" fill="#FCA326" />
                    <path d="M26.2 161.5h102.6L88.1 29.8c-2.1-6.5-11.3-6.5-13.5 0L26.2 161.5Z" fill="#E24329" />
                    <path d="m190.4 350.3 61.6-188.8h102.6L190.4 350.3Z" fill="#FC6D26" />
                    <path d="M354.6 161.5 375.4 225.6c1.9 5.8-.1 12.2-5 15.7l-180 130.9 164.2-210.7Z" fill="#FCA326" />
                    <path d="M354.6 161.5H252l40.7-131.7c2.1-6.5 11.3-6.5 13.5 0l48.4 131.7Z" fill="#E24329" />
                </svg>
                Sign in with GitLab
            </button>
        </div>
    </div>
</template>
