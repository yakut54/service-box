<script setup lang="ts">
/**
 * Переключатель on/off. Канонический тумблер админки — брать его, не верстать
 * заново (см. CLAUDE.md → переиспользование компонентов).
 */
withDefaults(defineProps<{
  modelValue: boolean
  disabled?: boolean
  loading?: boolean
}>(), {
  disabled: false,
  loading: false,
})

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

function toggle(current: boolean) {
  emit('update:modelValue', !current)
}
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :disabled="disabled || loading"
    class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
    :class="modelValue ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-600'"
    @click="toggle(modelValue)"
  >
    <span
      class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
      :class="[modelValue ? 'translate-x-4' : 'translate-x-0', loading ? 'animate-pulse' : '']"
    />
  </button>
</template>
