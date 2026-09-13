import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getEcho } from '@/lib/echo'

/**
 * Кто из сотрудников магазина прямо сейчас в приложении — через presence-
 * канал (см. routes/channels.php 'online.{shopId}'), не через опрос базы.
 * Presence-канал сам говорит серверу, кто зашёл (.here/.joining) и кто
 * вышел (.leaving) — включая обрыв соединения при закрытии вкладки, без
 * какого-либо действия от клиента.
 */
export const usePresenceStore = defineStore('presence', () => {
  const onlineUserIds = ref<Set<string>>(new Set())
  let channelName: string | null = null

  function isOnline(userId: string | null | undefined): boolean {
    return !!userId && onlineUserIds.value.has(userId)
  }

  function join(shopId: string) {
    if (channelName) return
    channelName = `online.${shopId}`

    getEcho()
      .join(channelName)
      .here((users: { id: string }[]) => {
        onlineUserIds.value = new Set(users.map(u => u.id))
      })
      .joining((user: { id: string }) => {
        onlineUserIds.value = new Set(onlineUserIds.value).add(user.id)
      })
      .leaving((user: { id: string }) => {
        const next = new Set(onlineUserIds.value)
        next.delete(user.id)
        onlineUserIds.value = next
      })
  }

  function leave() {
    if (channelName) getEcho().leave(channelName)
    channelName = null
    onlineUserIds.value = new Set()
  }

  return { onlineUserIds, isOnline, join, leave }
})
