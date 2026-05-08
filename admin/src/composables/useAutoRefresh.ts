import { onMounted, onUnmounted } from 'vue'

export function useAutoRefresh(callback: () => void, intervalMs = 30_000) {
  let timer: ReturnType<typeof setInterval> | null = null

  function start() {
    timer = setInterval(callback, intervalMs)
  }

  function stop() {
    if (timer !== null) { clearInterval(timer); timer = null }
  }

  function onVisibilityChange() {
    if (document.hidden) {
      stop()
    } else {
      callback()
      start()
    }
  }

  onMounted(() => {
    start()
    document.addEventListener('visibilitychange', onVisibilityChange)
  })

  onUnmounted(() => {
    stop()
    document.removeEventListener('visibilitychange', onVisibilityChange)
  })
}
