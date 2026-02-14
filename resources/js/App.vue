<script setup>
import AppNavigation from '@/components/AppNavigation.vue';
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppNavigation />
    <main class="flex-1 p-4 lg:p-8">
      <router-view />
    </main>
  </div>
</template>
