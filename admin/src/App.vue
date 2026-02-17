<script setup lang="ts">
import { RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { onMounted } from 'vue'

const authStore = useAuthStore()
const { init } = useTheme()

onMounted(() => {
  init()
  authStore.initialize()
})
</script>

<template>
  <RouterView />
</template>
