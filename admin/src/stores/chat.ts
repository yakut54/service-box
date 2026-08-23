import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { api } from '@/lib/api'

/**
 * Общий счётчик непрочитанных диалогов — один опрос на всю админку
 * (не по треду), см. PLAN-CHAT.md §4/§7. Двигает бейдж в меню и
 * бейдж на вкладке браузера (title), пока конкретный диалог не открыт
 * на экране (там уже своя более частая подгрузка сообщений).
 */
export const useChatStore = defineStore('chat', () => {
  const unreadByThread = ref<Record<string, number>>({})
  const totalUnread = ref(0)
  const baseTitle = document.title

  async function poll() {
    try {
      const data = await api.pollChat()
      unreadByThread.value = data.unread_by_thread
      totalUnread.value = data.total_unread
    } catch {
      // тихо игнорируем — это фоновый опрос, не критичная операция
    }
  }

  watch(totalUnread, (count) => {
    document.title = count > 0 ? `(${count}) ${baseTitle}` : baseTitle
  })

  function $reset() {
    unreadByThread.value = {}
    totalUnread.value = 0
  }

  return { unreadByThread, totalUnread, poll, $reset }
})
