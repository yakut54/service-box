import { ref } from 'vue'

type Theme = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'sb-admin-theme'
const theme = ref<Theme>('system')
const isDark = ref(false)

function applyDark(dark: boolean) {
  isDark.value = dark
  document.documentElement.classList.toggle('dark', dark)
}

function resolve(t: Theme): boolean {
  if (t === 'dark') return true
  if (t === 'light') return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

export function useTheme() {
  function init() {
    const saved = (localStorage.getItem(STORAGE_KEY) as Theme | null) ?? 'system'
    theme.value = saved
    applyDark(resolve(saved))

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
      if (theme.value === 'system') applyDark(e.matches)
    })
  }

  function toggle() {
    const next: Theme = isDark.value ? 'light' : 'dark'
    theme.value = next
    localStorage.setItem(STORAGE_KEY, next)
    applyDark(resolve(next))
  }

  return { theme, isDark, init, toggle }
}
