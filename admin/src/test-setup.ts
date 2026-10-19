// jsdom не реализует воспроизведение медиа — play() кидает "Not implemented"
// и возвращает undefined, а не промис. useAlertSound() (AppLayout,
// OrderAssignmentRequestModal/DeclinedModal) вызывает .catch() на результате
// play() для подавления ошибки автовоспроизведения — без этой заглушки
// любой компонентный тест, который монтирует их, падает именно на звуке,
// а не на проверяемой логике.
if (typeof window !== 'undefined' && window.HTMLMediaElement) {
  window.HTMLMediaElement.prototype.play = () => Promise.resolve()
  window.HTMLMediaElement.prototype.pause = () => {}
}
