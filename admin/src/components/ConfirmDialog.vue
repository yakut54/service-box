<script setup lang="ts">
import { onMounted, onUnmounted, useTemplateRef } from 'vue'

const props = withDefaults(defineProps<{
  title: string
  message: string
  confirmText?: string
  cancelText?: string
  danger?: boolean
}>(), {
  confirmText: 'Подтвердить',
  cancelText: 'Отмена',
  danger: false,
})

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const cancelBtn = useTemplateRef('cancelBtn')

onMounted(() => {
  cancelBtn.value?.focus()
  document.addEventListener('keydown', onKey)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKey)
})

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('cancel')
}
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      @click.self="emit('cancel')"
    >
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="emit('cancel')" />

      <!-- Dialog -->
      <div
        class="relative z-10 w-full max-w-sm bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6"
        role="dialog"
        aria-modal="true"
      >
        <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-2">{{ title }}</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ message }}</p>

        <div class="flex gap-3 justify-end">
          <button
            ref="cancelBtn"
            class="btn-secondary px-4"
            @click="emit('cancel')"
          >
            {{ cancelText }}
          </button>
          <button
            :class="danger ? 'bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2 rounded-lg transition-colors' : 'btn-primary px-4'"
            @click="emit('confirm')"
          >
            {{ confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
