<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  src?: string | null
  name?: string | null
  size?: 'sm' | 'md' | 'lg'
}>(), {
  src: null,
  name: '',
  size: 'md',
})

const sizeClass = computed(() => ({
  sm: 'w-8 h-8 text-xs',
  md: 'w-10 h-10 text-sm',
  lg: 'w-12 h-12 text-lg',
}[props.size]))

const initial = computed(() => (props.name || '?').trim().charAt(0).toUpperCase() || '?')
</script>

<template>
  <img
    v-if="src"
    :src="src"
    :alt="name || ''"
    :class="['rounded-full object-cover shrink-0 border border-gray-200 dark:border-gray-700', sizeClass]"
  />
  <div
    v-else
    :class="['rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-600 dark:text-primary-400 font-semibold shrink-0', sizeClass]"
  >
    {{ initial }}
  </div>
</template>
