/**
 * Звук уведомления — один и тот же сигнал для любого всплывающего события
 * (бейджи в AppLayout, "вам предлагают заказ", "вам отказали"). Раньше был
 * скопирован в AppLayout.vue и OrderAssignmentRequestModal.vue по отдельности
 * (тот же Audio-объект, тот же тумблер localStorage) — вынесено сюда, чтобы
 * третья копия для OrderAssignmentDeclinedModal.vue не плодила ту же логику
 * ещё раз.
 */
const alertAudio = new Audio('/sounds/chat-notify.wav')

export function useAlertSound() {
  function playAlertSound() {
    try {
      if (localStorage.getItem('chat_sound_enabled') === 'false') return
    } catch {
      // localStorage недоступен (приватный режим и т.п.) — считаем звук включённым
    }
    alertAudio.currentTime = 0
    alertAudio.play().catch(() => { /* автовоспроизведение заблокировано браузером — тихо игнорируем */ })
  }

  return { playAlertSound }
}
