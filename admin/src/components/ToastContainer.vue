<script setup lang="ts">
import { useToast } from '@/composables/useToast'

const { toasts, remove } = useToast()

const icons = {
  success: 'M5 13l4 4L19 7',
  error:   'M6 18L18 6M6 6l12 12',
  warning: 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
  info:    'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
}

const iconColors = {
  success: 'text-green-400',
  error:   'text-red-400',
  warning: 'text-yellow-400',
  info:    'text-blue-400',
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50 flex flex-col items-center gap-2 pointer-events-none w-max max-w-[calc(100vw-2rem)]">
      <TransitionGroup name="snackbar">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="flex items-center gap-3 px-4 py-3 rounded-2xl bg-gray-900 dark:bg-gray-800 shadow-xl pointer-events-auto"
        >
          <svg class="w-4 h-4 flex-shrink-0" :class="iconColors[toast.type]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" :d="icons[toast.type]"/>
          </svg>
          <p class="text-sm text-white leading-snug">{{ toast.message }}</p>
          <button @click="remove(toast.id)" class="flex-shrink-0 text-gray-500 hover:text-white transition-colors ml-1">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.snackbar-enter-active { transition: all 0.25s cubic-bezier(0.2, 0.8, 0.4, 1); }
.snackbar-leave-active { transition: all 0.2s ease-in; }
.snackbar-enter-from   { opacity: 0; transform: translateY(16px); }
.snackbar-leave-to     { opacity: 0; transform: translateY(16px); }
</style>
