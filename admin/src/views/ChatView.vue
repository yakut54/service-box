<script setup lang="ts">
import { ref, computed, nextTick, onUnmounted } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import { compressIfNeeded } from '@/composables/useImageCompression'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import { useToast } from '@/composables/useToast'
import { getEcho } from '@/lib/echo'
import { UiSpinner, UiEmptyState } from '@/shared/ui'
import UiModal from '@/shared/ui/UiModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import type { ChatThread, ChatMessage } from '@/types'

const authStore = useAuthStore()
const chatStore = useChatStore()

const threads = ref<ChatThread[]>([])
const loadingThreads = ref(true)
const searchQuery = ref('')

const selectedThread = ref<ChatThread | null>(null)
const messages = ref<ChatMessage[]>([])
const loadingMessages = ref(false)
const loadingOlder = ref(false)
const hasMoreOlder = ref(true)

const shopTimezone = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

function formatTime(dateStr: string): string {
  return new Date(dateStr).toLocaleTimeString('ru-RU', {
    hour: '2-digit', minute: '2-digit', timeZone: shopTimezone.value,
  })
}

function formatDay(dateStr: string): string {
  const tz = shopTimezone.value
  if (dayKey(dateStr) === dayKey(new Date().toISOString())) return 'Сегодня'
  const yesterday = new Date()
  yesterday.setDate(yesterday.getDate() - 1)
  if (dayKey(dateStr) === dayKey(yesterday.toISOString())) return 'Вчера'
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', timeZone: tz })
}

function dayKey(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { timeZone: shopTimezone.value })
}

async function loadThreads(silent = false) {
  if (!silent) loadingThreads.value = true
  try {
    const params: Record<string, string> = {}
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
    const data = await api.getChatThreads(params)
    threads.value = data.data
  } catch { /* фоновая подгрузка — молча пропускаем */ }
  if (!silent) loadingThreads.value = false
}

function onSearch() {
  loadThreads()
}

useAutoRefresh(() => loadThreads(true), 10_000)

const messagesEl = ref<HTMLElement | null>(null)
const isAtBottom = ref(true)

function onScroll() {
  const el = messagesEl.value
  if (!el) return
  isAtBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80
  if (el.scrollTop < 60 && hasMoreOlder.value && !loadingOlder.value) {
    loadOlder()
  }
}

async function scrollToBottom() {
  await nextTick()
  const el = messagesEl.value
  if (el) el.scrollTop = el.scrollHeight
}

// ── Реалтайм через Reverb (WebSocket) — polling ниже остаётся как
// подстраховка на случай обрыва/недоступности сокета, но основная доставка
// теперь мгновенная (см. PLAN-CHAT.md §12). При любом событии просто
// перезапрашиваем последнее окно сообщений тем же кодом, что и poll —
// не дублируем логику слияния/удаления в двух местах.
let subscribedChannel: string | null = null

function subscribeRealtime(thread: ChatThread) {
  unsubscribeRealtime()
  const apiKey = authStore.shop?.api_key
  if (!apiKey) return
  const channelName = `chat.thread.${apiKey}.${thread.id}`
  subscribedChannel = channelName
  getEcho()
    .private(channelName)
    .listen('.message.new', (e: { message: ChatMessage }) => {
      pollOpenThread()
      if (e.message.sender_type === 'customer') playNotifySound()
    })
    .listen('.message.updated', () => pollOpenThread())
    .listen('.message.deleted', () => pollOpenThread())
    .listen('.thread.read', () => pollOpenThread())
    .listen('.thread.blocked', (e: { is_blocked_by_shop: boolean }) => {
      if (selectedThread.value) selectedThread.value.is_blocked_by_shop = e.is_blocked_by_shop
    })
}

function unsubscribeRealtime() {
  if (subscribedChannel) {
    getEcho().leave(subscribedChannel)
    subscribedChannel = null
  }
}

onUnmounted(() => unsubscribeRealtime())

// ── Звук нового сообщения — только пока чат открыт, только на входящее
// (не на своё же исходящее), с переключателем в localStorage (§11.7).
const soundEnabled = ref(localStorage.getItem('chat_sound_enabled') !== 'false')
const notifyAudio = new Audio('/sounds/chat-notify.wav')

function toggleSound() {
  soundEnabled.value = !soundEnabled.value
  localStorage.setItem('chat_sound_enabled', String(soundEnabled.value))
}

function playNotifySound() {
  if (!soundEnabled.value) return
  notifyAudio.currentTime = 0
  notifyAudio.play().catch(() => { /* автовоспроизведение заблокировано браузером — тихо игнорируем */ })
}

async function selectThread(thread: ChatThread) {
  selectedThread.value = thread
  messages.value = []
  hasMoreOlder.value = true
  loadingMessages.value = true
  cancelReply()
  cancelEdit()
  try {
    const data = await api.getChatMessages(thread.id)
    messages.value = [...data.data].reverse()
    if (data.data.length < 30) hasMoreOlder.value = false
    if (thread.unread_by_shop > 0) {
      await api.markChatRead(thread.id)
      thread.unread_by_shop = 0
      await chatStore.poll()
    }
    subscribeRealtime(thread)
  } catch (e) {
    toastError(parseApiError(e, 'Не удалось загрузить переписку'))
  }
  loadingMessages.value = false
  await scrollToBottom()
}

function backToList() {
  unsubscribeRealtime()
  selectedThread.value = null
}

async function loadOlder() {
  if (!selectedThread.value || messages.value.length === 0) return
  loadingOlder.value = true
  const el = messagesEl.value
  const prevHeight = el?.scrollHeight ?? 0
  try {
    const oldest = messages.value[0]
    const data = await api.getChatMessages(selectedThread.value.id, { before: oldest.id })
    if (data.data.length < 30) hasMoreOlder.value = false
    messages.value = [...data.data].reverse().concat(messages.value)
    await nextTick()
    if (el) el.scrollTop = el.scrollHeight - prevHeight
  } catch { /* ignore */ }
  loadingOlder.value = false
}

// ── Live polling внутри открытого диалога ───────────────────────────────────
async function pollOpenThread() {
  if (!selectedThread.value) return
  try {
    const data = await api.getChatMessages(selectedThread.value.id)
    const fresh = [...data.data].reverse()
    const known = new Set(messages.value.map(m => m.id))
    const newOnes = fresh.filter(m => !known.has(m.id))

    // fresh покрывает только последние ~30 сообщений. Если сообщение из
    // этого же окна пропало (другой сотрудник удалил его как модерацию),
    // убираем его и здесь — иначе оно зависает в памяти навсегда, раз
    // poll умеет только добавлять. Сообщения старше окна (подгруженные
    // через loadOlder) не трогаем.
    const windowStart = fresh.length > 0 ? fresh[0].created_at : null
    const freshIds = new Set(fresh.map(m => m.id))
    messages.value = messages.value.filter(
      m => windowStart === null || m.created_at < windowStart || freshIds.has(m.id)
    )

    if (newOnes.length > 0) {
      messages.value = messages.value.concat(newOnes)
      const hasIncoming = newOnes.some(m => m.sender_type === 'customer')
      if (hasIncoming) {
        await api.markChatRead(selectedThread.value.id)
        selectedThread.value.unread_by_shop = 0
        await chatStore.poll()
      }
      if (isAtBottom.value || newOnes.some(m => m.sender_type === 'shop')) {
        await scrollToBottom()
      }
    }
    // статусы прочтения (✓✓) могли обновиться без новых сообщений
    const byId = new Map(fresh.map(m => [m.id, m]))
    messages.value = messages.value.map(m => byId.get(m.id) ?? m)
  } catch { /* ignore */ }
  await loadThreads(true)
}

// Раньше это был единственный канал доставки (4с) — теперь основной путь
// это WebSocket-события выше, poll остаётся более редкой подстраховкой на
// случай обрыва сокета/пропущенного события при реконнекте.
useAutoRefresh(() => pollOpenThread(), 20_000)

// ── Ответ на сообщение (reply) ────────────────────────────────────────────────
const replyTarget = ref<ChatMessage | null>(null)

function startReply(m: ChatMessage) {
  replyTarget.value = m
  editingMessage.value = null
}

function cancelReply() {
  replyTarget.value = null
}

function replyPreviewText(m: ChatMessage): string {
  return m.body || (m.image_url ? '📷 Фото' : '')
}

// ── Редактирование своего сообщения (переиспользует композер, как в
// Telegram Desktop — не отдельная модалка) ────────────────────────────────
const editingMessage = ref<ChatMessage | null>(null)

function startEdit(m: ChatMessage) {
  if (m.sender_type !== 'shop' || m.image_url) return
  editingMessage.value = m
  draft.value = m.body ?? ''
  replyTarget.value = null
}

function cancelEdit() {
  editingMessage.value = null
  draft.value = ''
}

// ── Отправка сообщений ───────────────────────────────────────────────────────
const draft = ref('')
const sending = ref(false)
const pendingImageUrl = ref<string | null>(null)
const uploadingImage = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

function pickImage() {
  fileInput.value?.click()
}

async function onFileSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  uploadingImage.value = true
  try {
    const toUpload = await compressIfNeeded(file, { maxSizeMB: 1, maxWidthOrHeight: 1600 })
    const { url } = await api.uploadChatImage(toUpload)
    pendingImageUrl.value = url
  } catch (e2) {
    toastError(parseApiError(e2, 'Не удалось загрузить фото'))
  }
  uploadingImage.value = false
  if (fileInput.value) fileInput.value.value = ''
}

function removePendingImage() {
  pendingImageUrl.value = null
}

function openImage(url: string | null) {
  if (url) window.open(url, '_blank')
}

async function sendMessage() {
  if (!selectedThread.value) return
  const body = draft.value.trim()

  if (editingMessage.value) {
    if (!body) return
    sending.value = true
    try {
      const { data } = await api.editChatMessage(selectedThread.value.id, editingMessage.value.id, body)
      messages.value = messages.value.map(m => (m.id === data.id ? data : m))
      cancelEdit()
    } catch (e) {
      toastError(parseApiError(e, 'Не удалось изменить сообщение'))
    }
    sending.value = false
    return
  }

  const imageUrl = pendingImageUrl.value
  if (!body && !imageUrl) return

  sending.value = true
  const clientMessageId = crypto.randomUUID()
  try {
    const { data } = await api.sendChatMessage(selectedThread.value.id, {
      body: body || null,
      image_url: imageUrl,
      client_message_id: clientMessageId,
      reply_to_message_id: replyTarget.value?.id ?? null,
    })
    messages.value.push(data)
    draft.value = ''
    pendingImageUrl.value = null
    cancelReply()
    selectedThread.value.last_message_preview = body || '📷 Фото'
    selectedThread.value.last_message_at = data.created_at
    await scrollToBottom()
    loadThreads(true)
  } catch (e) {
    toastError(parseApiError(e, 'Не удалось отправить сообщение'))
  }
  sending.value = false
}

// ── Удаление сообщения ───────────────────────────────────────────────────────
const deleteTarget = ref<ChatMessage | null>(null)
const deleting = ref(false)

async function confirmDelete() {
  if (!deleteTarget.value || !selectedThread.value) return
  deleting.value = true
  try {
    await api.deleteChatMessage(selectedThread.value.id, deleteTarget.value.id)
    messages.value = messages.value.filter(m => m.id !== deleteTarget.value!.id)
    deleteTarget.value = null
  } catch (e) {
    toastError(parseApiError(e, 'Не удалось удалить сообщение'))
  }
  deleting.value = false
}

// ── Блокировка ───────────────────────────────────────────────────────────────
const blockConfirmOpen = ref(false)
const blocking = ref(false)

async function toggleBlock() {
  if (!selectedThread.value) return
  if (!selectedThread.value.is_blocked_by_shop) {
    blockConfirmOpen.value = true
    return
  }
  await setBlocked(false)
}

async function setBlocked(blocked: boolean) {
  if (!selectedThread.value) return
  blocking.value = true
  try {
    const { data } = await api.blockChatThread(selectedThread.value.id, blocked)
    selectedThread.value.is_blocked_by_shop = data.is_blocked_by_shop
    blockConfirmOpen.value = false
  } catch (e) {
    toastError(parseApiError(e, 'Не удалось изменить блокировку'))
  }
  blocking.value = false
}

const toast = useToast()
function toastError(msg: string) { toast.error(msg) }

// ── Группировка сообщений по дню для разделителей ───────────────────────────
const messageGroups = computed(() => {
  const groups: { key: string; label: string; items: ChatMessage[] }[] = []
  for (const m of messages.value) {
    const key = dayKey(m.created_at)
    const last = groups[groups.length - 1]
    if (last && last.key === key) {
      last.items.push(m)
    } else {
      groups.push({ key, label: formatDay(m.created_at), items: [m] })
    }
  }
  return groups
})

loadThreads()
</script>

<template>
  <div class="lg:flex-1 lg:min-h-0 flex flex-col">
    <PageHeader class="mb-4" title="Чат с покупателями" />

    <div class="card p-0 overflow-hidden flex-1 min-h-0 flex" style="min-height: 60vh">
      <!-- Список диалогов -->
      <div
        :class="[
          'w-full sm:w-80 shrink-0 border-r border-gray-100 dark:border-gray-800 flex flex-col',
          selectedThread ? 'hidden sm:flex' : 'flex'
        ]"
      >
        <div class="p-3 border-b border-gray-100 dark:border-gray-800">
          <input
            v-model="searchQuery"
            @input="onSearch"
            type="text"
            class="input text-sm"
            placeholder="Поиск по имени или телефону..."
          />
        </div>

        <div v-if="loadingThreads" class="flex-1 flex items-center justify-center py-12">
          <UiSpinner />
        </div>

        <UiEmptyState v-else-if="threads.length === 0" title="Нет диалогов" description="Сообщения от покупателей появятся здесь">
          <template #icon>
            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
          </template>
        </UiEmptyState>

        <div v-else class="flex-1 overflow-y-auto">
          <button
            v-for="t in threads"
            :key="t.id"
            type="button"
            @click="selectThread(t)"
            :class="[
              'w-full text-left flex items-center gap-3 px-4 py-3 border-b border-gray-50 dark:border-gray-800/50 transition-colors',
              selectedThread?.id === t.id
                ? 'bg-primary-50 dark:bg-primary-900/20'
                : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'
            ]"
          >
            <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center shrink-0">
              <span class="text-gray-600 dark:text-gray-300 font-medium text-sm">
                {{ (t.customer?.name || '?').charAt(0).toUpperCase() }}
              </span>
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-2">
                <span class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">
                  {{ t.customer?.name || t.customer?.phone || 'Покупатель' }}
                </span>
                <span v-if="t.last_message_at" class="text-[11px] text-gray-400 shrink-0">{{ formatTime(t.last_message_at) }}</span>
              </div>
              <div class="flex items-center justify-between gap-2 mt-0.5">
                <span class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ t.last_message_preview || '—' }}</span>
                <span
                  v-if="t.unread_by_shop > 0"
                  class="min-w-[1.1rem] h-[1.1rem] px-1 flex items-center justify-center rounded-full bg-red-500 text-white text-[10px] font-semibold leading-none shrink-0"
                >
                  {{ t.unread_by_shop > 99 ? '99+' : t.unread_by_shop }}
                </span>
                <svg v-if="t.is_blocked_by_shop" class="w-3.5 h-3.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
              </div>
            </div>
          </button>
        </div>
      </div>

      <!-- Диалог -->
      <div :class="['flex-1 min-w-0 flex flex-col', !selectedThread ? 'hidden sm:flex' : 'flex']">
        <template v-if="!selectedThread">
          <div class="flex-1 hidden sm:flex items-center justify-center text-sm text-gray-400 dark:text-gray-500">
            Выберите диалог слева
          </div>
        </template>

        <template v-else>
          <!-- Шапка диалога -->
          <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800 shrink-0">
            <button type="button" @click="backToList" class="sm:hidden btn-ghost btn-sm -ml-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <div class="min-w-0 flex-1">
              <RouterLink
                :to="`/customers/${selectedThread.customer_id}`"
                class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate hover:text-primary-600 dark:hover:text-primary-400 block"
              >
                {{ selectedThread.customer?.name || selectedThread.customer?.phone || 'Покупатель' }}
              </RouterLink>
              <p class="text-xs text-gray-400 dark:text-gray-500 truncate">
                {{ selectedThread.customer?.phone }}
                <span v-if="selectedThread.customer?.total_orders">· {{ selectedThread.customer.total_orders }} {{ selectedThread.customer.total_orders === 1 ? 'заказ' : 'заказов' }}</span>
              </p>
            </div>
            <button
              type="button"
              @click="toggleSound"
              class="btn-ghost btn-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
              :title="soundEnabled ? 'Звук уведомлений включён' : 'Звук уведомлений выключен'"
            >
              <svg v-if="soundEnabled" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M6 8h2l4-4v16l-4-4H6a1 1 0 01-1-1V9a1 1 0 011-1z" />
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2 2m0-4l-2 2M6 8h2l4-4v16l-4-4H6a1 1 0 01-1-1V9a1 1 0 011-1z" />
              </svg>
            </button>
            <button
              type="button"
              @click="toggleBlock"
              :disabled="blocking"
              :class="[
                'btn-sm text-xs px-3 py-1.5 rounded-lg border transition-colors',
                selectedThread.is_blocked_by_shop
                  ? 'border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800'
                  : 'border-red-200 dark:border-red-900 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/10'
              ]"
            >
              {{ selectedThread.is_blocked_by_shop ? 'Разблокировать' : 'Заблокировать' }}
            </button>
          </div>

          <p v-if="selectedThread.is_blocked_by_shop" class="text-xs text-center text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/10 py-1.5 shrink-0">
            Переписка заблокирована — покупатель не может отправлять сообщения
          </p>

          <!-- Лента сообщений -->
          <div ref="messagesEl" @scroll="onScroll" class="flex-1 overflow-y-auto px-4 py-3 space-y-1">
            <div v-if="loadingMessages" class="flex justify-center py-8"><UiSpinner /></div>
            <template v-else>
              <div v-if="loadingOlder" class="flex justify-center py-2"><UiSpinner size="sm" /></div>
              <div v-for="group in messageGroups" :key="group.key">
                <div class="text-center my-3">
                  <span class="text-[11px] text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800 px-2 py-0.5 rounded-full">{{ group.label }}</span>
                </div>
                <div
                  v-for="m in group.items"
                  :key="m.id"
                  :class="['group flex mb-2', m.sender_type === 'shop' ? 'justify-end' : 'justify-start']"
                >
                  <div class="relative max-w-[75%] flex items-end gap-1.5" :class="m.sender_type === 'shop' ? 'flex-row' : 'flex-row-reverse'">
                    <div class="opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 shrink-0 mb-1">
                      <button
                        type="button"
                        @click="startReply(m)"
                        class="text-gray-300 hover:text-primary-500"
                        title="Ответить"
                      >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17l-5-5 5-5m-5 5h12a4 4 0 004-4V6" />
                        </svg>
                      </button>
                      <button
                        v-if="m.sender_type === 'shop' && !m.image_url"
                        type="button"
                        @click="startEdit(m)"
                        class="text-gray-300 hover:text-primary-500"
                        title="Редактировать"
                      >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                      </button>
                      <button
                        type="button"
                        @click="deleteTarget = m"
                        class="text-gray-300 hover:text-red-500"
                        title="Удалить сообщение"
                      >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                      </button>
                    </div>
                    <div
                      :class="[
                        'rounded-2xl px-3 py-2',
                        m.sender_type === 'shop'
                          ? 'bg-primary-600 text-white rounded-br-sm'
                          : 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-bl-sm'
                      ]"
                    >
                      <div
                        v-if="m.reply_to"
                        :class="[
                          'text-xs mb-1.5 pl-2 border-l-2 rounded-sm truncate max-w-[220px]',
                          m.sender_type === 'shop' ? 'border-white/50 text-white/80' : 'border-primary-400 text-gray-500 dark:text-gray-400'
                        ]"
                      >
                        {{ replyPreviewText(m.reply_to) }}
                      </div>
                      <div v-else-if="m.reply_to_message_id" class="text-xs mb-1.5 pl-2 border-l-2 border-gray-300 text-gray-400 italic">
                        Сообщение удалено
                      </div>
                      <img
                        v-if="m.image_url"
                        :src="m.image_url"
                        class="rounded-lg max-w-full max-h-64 mb-1 cursor-pointer"
                        @click="openImage(m.image_url)"
                      />
                      <p v-if="m.body" class="text-sm whitespace-pre-wrap break-words">{{ m.body }}</p>
                      <div class="flex items-center gap-1 justify-end mt-0.5">
                        <span v-if="m.edited_at" :class="['text-[10px] italic', m.sender_type === 'shop' ? 'text-white/60' : 'text-gray-400']">изменено</span>
                        <span :class="['text-[10px]', m.sender_type === 'shop' ? 'text-white/70' : 'text-gray-400']">{{ formatTime(m.created_at) }}</span>
                        <svg v-if="m.sender_type === 'shop'" class="w-4 h-3.5" :class="m.status === 'read' ? 'text-sky-300' : 'text-white/60'" fill="none" stroke="currentColor" viewBox="0 0 24 12">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 6l3 4L11 2" />
                          <path v-if="m.status === 'read'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6l3 4L18 2" />
                        </svg>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </template>
          </div>

          <!-- Композер -->
          <div class="border-t border-gray-100 dark:border-gray-800 p-3 shrink-0">
            <div v-if="replyTarget" class="mb-2 flex items-center gap-2 bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
              <svg class="w-4 h-4 text-primary-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17l-5-5 5-5m-5 5h12a4 4 0 004-4V6" />
              </svg>
              <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-primary-600 dark:text-primary-400">Ответ на сообщение</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ replyPreviewText(replyTarget) }}</p>
              </div>
              <button type="button" @click="cancelReply" class="text-gray-400 hover:text-gray-600 shrink-0">✕</button>
            </div>
            <div v-if="editingMessage" class="mb-2 flex items-center gap-2 bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
              <svg class="w-4 h-4 text-primary-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              <p class="text-xs font-medium text-primary-600 dark:text-primary-400 flex-1">Редактирование сообщения</p>
              <button type="button" @click="cancelEdit" class="text-gray-400 hover:text-gray-600 shrink-0">✕</button>
            </div>
            <div v-if="pendingImageUrl" class="mb-2 relative inline-block">
              <img :src="pendingImageUrl" class="h-16 rounded-lg" />
              <button
                type="button"
                @click="removePendingImage"
                class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs"
              >×</button>
            </div>
            <div v-if="uploadingImage" class="mb-2 text-xs text-gray-400 flex items-center gap-2">
              <UiSpinner size="sm" /> Загрузка фото...
            </div>
            <form @submit.prevent="sendMessage" class="flex items-end gap-2">
              <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFileSelected" />
              <button
                type="button"
                @click="pickImage"
                :disabled="selectedThread.is_blocked_by_shop || uploadingImage || !!editingMessage"
                class="btn-ghost btn-sm shrink-0 disabled:opacity-40"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h4M13 3l6 6m0-6v6h-6" />
                </svg>
              </button>
              <textarea
                v-model="draft"
                rows="1"
                class="input flex-1 resize-none text-sm"
                :placeholder="editingMessage ? 'Изменить текст...' : 'Написать покупателю...'"
                :disabled="selectedThread.is_blocked_by_shop"
                @keydown.enter.exact.prevent="sendMessage"
                @keydown.esc="cancelEdit(); cancelReply()"
              />
              <button
                type="submit"
                class="btn-primary btn-sm shrink-0"
                :disabled="sending || selectedThread.is_blocked_by_shop || (!draft.trim() && !pendingImageUrl)"
              >
                {{ editingMessage ? 'Сохранить' : 'Отправить' }}
              </button>
            </form>
          </div>
        </template>
      </div>
    </div>
  </div>

  <!-- Удаление сообщения -->
  <UiModal :modelValue="!!deleteTarget" @update:modelValue="!$event && (deleteTarget = null)" maxWidth="max-w-sm">
    <div v-if="deleteTarget" class="p-6 space-y-4">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Удалить сообщение?</h2>
      <p class="text-sm text-gray-500 dark:text-gray-400">Сообщение и приложенное фото (если есть) будут удалены безвозвратно.</p>
      <div class="flex gap-3">
        <button @click="deleteTarget = null" class="btn-secondary flex-1">Отмена</button>
        <button @click="confirmDelete" class="btn-danger flex-1" :disabled="deleting">{{ deleting ? 'Удаление...' : 'Удалить' }}</button>
      </div>
    </div>
  </UiModal>

  <!-- Подтверждение блокировки -->
  <UiModal :modelValue="blockConfirmOpen" @update:modelValue="blockConfirmOpen = $event" maxWidth="max-w-sm">
    <div class="p-6 space-y-4">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Заблокировать переписку?</h2>
      <p class="text-sm text-gray-500 dark:text-gray-400">Покупатель не сможет отправлять новые сообщения, но история переписки останется видна обеим сторонам.</p>
      <div class="flex gap-3">
        <button @click="blockConfirmOpen = false" class="btn-secondary flex-1">Отмена</button>
        <button @click="setBlocked(true)" class="btn-danger flex-1" :disabled="blocking">{{ blocking ? 'Применение...' : 'Заблокировать' }}</button>
      </div>
    </div>
  </UiModal>
</template>
