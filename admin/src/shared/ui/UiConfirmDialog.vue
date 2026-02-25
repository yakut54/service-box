<script setup lang="ts">
import UiModal from './UiModal.vue'

withDefaults(defineProps<{
  modelValue: boolean
  title: string
  loading?: boolean
  confirmLabel?: string
  danger?: boolean
}>(), {
  loading: false,
  confirmLabel: 'Удалить',
  danger: true,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: []
}>()
</script>

<template>
  <UiModal :modelValue="modelValue" @update:modelValue="emit('update:modelValue', $event)" maxWidth="max-w-sm">
    <div class="p-6 space-y-4">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ title }}</h2>
      <div class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
        <slot />
      </div>
      <div class="flex gap-3">
        <button @click="emit('update:modelValue', false)" class="btn-secondary flex-1">Отмена</button>
        <button
          @click="emit('confirm')"
          :class="['flex-1', danger ? 'btn-danger' : 'btn-primary']"
          :disabled="loading"
        >
          {{ loading ? 'Удаление...' : confirmLabel }}
        </button>
      </div>
    </div>
  </UiModal>
</template>
