<script setup lang="ts">
withDefaults(defineProps<{
  modelValue: boolean
  maxWidth?: string
}>(), { maxWidth: 'max-w-lg' })

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="emit('update:modelValue', false)" />
      <div :class="['relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-h-[90vh] overflow-y-auto', maxWidth]">
        <slot />
      </div>
    </div>
  </Teleport>
</template>
