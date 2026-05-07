<script setup lang="ts">
defineProps<{
  title: string
  value: string
  subtitle?: string
  delta?: string
  deltaUp?: boolean
  loading?: boolean
  color?: 'green' | 'blue' | 'purple' | 'yellow' | 'violet' | 'gray'
  icon?: 'revenue' | 'orders' | 'avg' | 'bookings' | 'pending' | 'masters'
}>()
</script>

<template>
  <div class="card">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ title }}</p>

    <div v-if="loading" class="mt-2 space-y-1.5">
      <div class="h-7 w-28 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
      <div class="h-4 w-20 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
    </div>

    <template v-else>
      <p class="text-2xl font-bold mt-1 tabular-nums"
        :class="color === 'yellow' && value !== '0'
          ? 'text-yellow-500'
          : 'text-gray-900 dark:text-white'">
        {{ value }}
      </p>

      <div class="flex items-center gap-1 mt-1.5 h-4">
        <template v-if="delta">
          <svg v-if="deltaUp" class="w-3 h-3 text-emerald-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
          </svg>
          <svg v-else class="w-3 h-3 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M14.707 10.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586V5a1 1 0 012 0v7.586l2.293-2.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
          </svg>
          <span :class="['text-xs font-semibold', deltaUp ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400']">
            {{ delta }}
          </span>
          <span class="text-xs text-gray-400">vs прошлый</span>
        </template>
        <span v-else-if="subtitle" class="text-xs text-gray-400">{{ subtitle }}</span>
      </div>
    </template>

    <!-- Icon -->
    <div class="w-10 h-10 rounded-xl flex items-center justify-center mt-3"
      :class="{
        'bg-green-100  dark:bg-green-900/30':  color === 'green',
        'bg-primary-100 dark:bg-primary-900/30': color === 'blue',
        'bg-purple-100 dark:bg-purple-900/30': color === 'purple',
        'bg-yellow-100 dark:bg-yellow-900/30': color === 'yellow',
        'bg-violet-100 dark:bg-violet-900/30': color === 'violet',
        'bg-gray-100   dark:bg-gray-700':      color === 'gray' || !color,
      }">
      <!-- Revenue -->
      <svg v-if="icon === 'revenue'" class="w-5 h-5" :class="color === 'green' ? 'text-green-600 dark:text-green-400' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <!-- Orders -->
      <svg v-else-if="icon === 'orders'" class="w-5 h-5" :class="color === 'blue' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
      </svg>
      <!-- Avg -->
      <svg v-else-if="icon === 'avg'" class="w-5 h-5" :class="color === 'purple' ? 'text-purple-600 dark:text-purple-400' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
      </svg>
      <!-- Bookings -->
      <svg v-else-if="icon === 'bookings'" class="w-5 h-5" :class="color === 'violet' ? 'text-violet-600 dark:text-violet-400' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
      </svg>
      <!-- Pending -->
      <svg v-else-if="icon === 'pending'" class="w-5 h-5" :class="color === 'yellow' ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>
  </div>
</template>
