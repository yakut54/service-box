import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from './api'

// @ts-expect-error — laravel-echo ожидает глобальный Pusher, не импортированный модуль
window.Pusher = Pusher

let echo: Echo<'reverb'> | null = null

/**
 * Один Echo-инстанс на всю вкладку — Reverb говорит по Pusher-протоколу,
 * поэтому обычный pusher-js клиент подходит без изменений (см.
 * PLAN-CHAT.md §12). Авторизация приватных каналов идёт через
 * /api/broadcasting/auth (auth:sanctum, тот же Bearer-токен, что и у
 * обычных запросов) — не через cookie/CSRF, поэтому передаём заголовок
 * вручную в authorizer.
 */
export function getEcho(): Echo<'reverb'> {
  if (echo) return echo

  const host = import.meta.env.VITE_REVERB_HOST || 'localhost'
  const port = Number(import.meta.env.VITE_REVERB_PORT || 8080)
  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'https'
  const useTLS = scheme === 'https'

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: useTLS,
    enabledTransports: useTLS ? ['ws', 'wss'] : ['ws'],
    // pusher-js типизирует этот колбэк под старый (deprecated, но всё ещё
    // рабочий) authorizer-контракт довольно неудобно для точного соответствия
    // типов — сигнатура ниже корректна на рантайме (Reverb ждёт ровно
    // {auth: "..."} вторым аргументом при отсутствии ошибки).
    // @ts-expect-error — see comment above
    authorizer: (channel: { name: string }) => ({
      authorize(socketId: string, callback: (error: unknown, data: unknown) => void) {
        fetch(`${(import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '')}/broadcasting/auth`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${api.getToken() ?? ''}`,
          },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then((res) => (res.ok ? res.json() : Promise.reject(res)))
          .then((data) => callback(null, data))
          .catch(() => callback(new Error('Chat channel auth failed'), null))
      },
    }),
  })

  return echo
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
