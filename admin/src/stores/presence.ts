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
  let unsubscribeConnection: (() => void) | null = null
  let wasConnected = true

  function isOnline(userId: string | null | undefined): boolean {
    return !!userId && onlineUserIds.value.has(userId)
  }

  function subscribe(name: string) {
    getEcho()
      .join(name)
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

  function join(shopId: string) {
    if (channelName) return
    channelName = `online.${shopId}`
    subscribe(channelName)

    // Сеть могла ненадолго оборваться и восстановиться сама (WS
    // переподключается автоматически) без чистого leave/joining на этом
    // канале — presence иногда «зависает» в устаревшем состоянии до
    // следующего .here() (баг найден 2026-09-14 живым тестом: «иногда
    // некорректно показывает время отсутствия в сети»). Пересоздаём
    // подписку при каждом восстановлении соединения, чтобы .here() всегда
    // сверял актуальный список заново, а не полагался на пропущенные
    // join/leave события за время обрыва.
    unsubscribeConnection = getEcho().connector.onConnectionChange((status: string) => {
      if (status === 'connected' && !wasConnected && channelName) {
        getEcho().leave(channelName)
        subscribe(channelName)
      }
      wasConnected = status === 'connected'
    })
  }

  function leave() {
    if (channelName) getEcho().leave(channelName)
    if (unsubscribeConnection) unsubscribeConnection()
    channelName = null
    unsubscribeConnection = null
    onlineUserIds.value = new Set()
  }

  return { onlineUserIds, isOnline, join, leave }
})
