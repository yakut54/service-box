<script setup lang="ts">
defineProps<{
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  block?: boolean
  sm?: boolean
  type?: 'button' | 'submit' | 'reset'
  disabled?: boolean
  loading?: boolean
}>()
</script>

<template>
  <button
    :type="type ?? 'button'"
    :disabled="disabled || loading"
    :class="[
      'sb-btn',
      `sb-btn-${variant ?? 'primary'}`,
      block && 'sb-btn-block',
      sm && 'sb-btn-sm',
    ]"
  >
    <slot />
  </button>
</template>
