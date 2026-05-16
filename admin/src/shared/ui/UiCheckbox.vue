<script setup lang="ts">
defineProps<{
  modelValue: boolean
  align?: 'center' | 'start'
}>()

defineEmits<{ 'update:modelValue': [boolean] }>()
</script>

<template>
  <label :class="['flex gap-3 cursor-pointer select-none', align === 'start' ? 'items-start' : 'items-center']">
    <input
      type="checkbox"
      :checked="modelValue"
      @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      class="sr-only"
    />
    <span
      :class="['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
        align === 'start' ? 'mt-0.5' : '',
        modelValue ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700']"
    >
      <span :class="['inline-block h-4 w-4 rounded-full bg-white shadow transition-transform',
        modelValue ? 'translate-x-6' : 'translate-x-1']" />
    </span>
    <slot />
  </label>
</template>
