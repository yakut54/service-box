import { ref } from 'vue'

export type ToastType = 'success' | 'error' | 'warning' | 'info'

interface Toast {
  id: number
  type: ToastType
  message: string
}

const toasts = ref<Toast[]>([])
let nextId = 0

function show(message: string, type: ToastType = 'info', duration = 4000) {
  const id = ++nextId
  toasts.value.push({ id, type, message })
  setTimeout(() => remove(id), duration)
}

function remove(id: number) {
  toasts.value = toasts.value.filter(t => t.id !== id)
}

export function useToast() {
  return {
    toasts,
    show,
    remove,
    success: (msg: string) => show(msg, 'success'),
    error:   (msg: string) => show(msg, 'error', 5000),
    warning: (msg: string) => show(msg, 'warning'),
    info:    (msg: string) => show(msg, 'info'),
  }
}
