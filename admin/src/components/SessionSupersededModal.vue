<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { getEcho } from '@/lib/echo'
import { useAuthStore } from '@/stores/auth'
import UiModal from '@/shared/ui/UiModal.vue'

// Владелец/сотрудник вошёл с другого устройства — AuthController::login()
// удаляет все прежние токены (одна активная сессия). Уже открытая вкладка
// узнаёт об этом в реальном времени через приватный канал user.{id} —
// сессия здесь уже мертва, но WS-соединение это не разрывает, так что
// событие всё равно долетает раньше, чем сработает следующий 401.
const authStore = useAuthStore()

const visible = ref(false)
const ip = ref('')
const totalUsers = ref(0)

let channelName: string | null = null

function subscribe() {
  const userId = authStore.user?.id
  if (!userId) return
  channelName = `user.${userId}`
  getEcho()
    .private(channelName)
    .listen('.session.superseded', (e: { ip: string; total_users: number }) => {
      ip.value = e.ip
      totalUsers.value = e.total_users
      visible.value = true
    })
}

onMounted(subscribe)
onUnmounted(() => {
  if (channelName) getEcho().leave(channelName)
})
</script>

<template>
  <UiModal :modelValue="visible" @update:modelValue="visible = $event" maxWidth="max-w-sm">
    <div class="p-6 space-y-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
          </svg>
        </div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Вход с другого устройства</h2>
      </div>
      <p class="text-sm text-gray-600 dark:text-gray-300">
        С IP-адреса <strong>{{ ip }}</strong> выполнен вход в ваш аккаунт.
        Сессия на этом устройстве больше не активна — при следующем действии
        потребуется войти заново.
      </p>
      <p class="text-xs text-gray-400 dark:text-gray-500">
        Пользователей в аккаунте магазина: {{ totalUsers }}
      </p>
      <button @click="visible = false" class="btn-primary w-full">Понятно</button>
    </div>
  </UiModal>
</template>
