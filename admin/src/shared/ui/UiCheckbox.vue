<script setup lang="ts">
defineProps<{
  modelValue: boolean
  align?: 'center' | 'start'
}>()

defineEmits<{ 'update:modelValue': [boolean] }>()
</script>

<template>
  <label :class="['flex gap-2 cursor-pointer select-none', align === 'start' ? 'items-start' : 'items-center']">
    <input
      type="checkbox"
      :checked="modelValue"
      @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      :class="['w-4 h-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer shrink-0', align === 'start' ? 'mt-0.5' : '']"
    />
    <slot />
  </label>
</template>
