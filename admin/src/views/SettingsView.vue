<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import CustomSelect from '@/components/CustomSelect.vue'
import TimeInput from '@/components/TimeInput.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { timezoneOptions } from '@/shared/lib/timezones'

const authStore = useAuthStore()

// ── Shop name ─────────────────────────────────────────────────
const shopName = ref('')
const savingName = ref(false)
const nameSuccess = ref(false)
const nameError = ref('')

async function saveShopName() {
  if (!shopName.value.trim()) { nameError.value = 'Название не может быть пустым'; return }
  savingName.value = true
  nameError.value = ''
  nameSuccess.value = false
  try {
    const updated = await api.updateShop({ name: shopName.value.trim() })
    if (authStore.shop) authStore.shop.name = updated.name
    nameSuccess.value = true
    setTimeout(() => nameSuccess.value = false, 3000)
  } catch (e: unknown) {
    nameError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingName.value = false
  }
}

const widgetCode = computed(() => {
  const apiKey = authStore.shop?.api_key || 'YOUR_API_KEY'
  return `<script src="https://cdn.servicebox.ru/widget.js" data-shop-id="${apiKey}"><\/script>`
})

const copied = ref(false)

function copyCode() {
  navigator.clipboard.writeText(widgetCode.value)
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}

const telegramCode = ref('')
const generatingCode = ref(false)
const telegramError = ref('')
const disconnecting = ref(false)
const showDisconnectConfirm = ref(false)

async function generateTelegramCode() {
  if (!authStore.shop) return
  generatingCode.value = true
  telegramError.value = ''
  try {
    const resp = await api.generateTelegramCode()
    telegramCode.value = resp.code
  } catch (e: unknown) {
    telegramError.value = e instanceof Error ? e.message : 'Ошибка генерации кода'
  }
  generatingCode.value = false
}

async function disconnectTelegram() {
  if (!authStore.shop) return
  disconnecting.value = true
  try {
    await api.disconnectTelegram()
    if (authStore.shop) {
      authStore.shop.telegram_bot_connected = false
      authStore.shop.telegram_chat_id = null
    }
    telegramCode.value = ''
  } catch (e: unknown) {
    telegramError.value = e instanceof Error ? e.message : 'Ошибка отключения'
  }
  disconnecting.value = false
}

// ── Work hours ───────────────────────────────────────────────
const workStart = ref('09:00')
const workEnd = ref('20:00')
const slotDuration = ref('30')
const timezone = ref('Europe/Moscow')
const savingHours = ref(false)
const hoursSuccess = ref(false)
const hoursError = ref('')

// ── Change password ───────────────────────────────────────────
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const savingPassword = ref(false)
const passwordSuccess = ref(false)
const passwordError = ref('')

const newPasswordTouched        = ref(false)
const newPasswordConfirmTouched = ref(false)

const newPasswordLengthError = computed(() => {
  if (!newPasswordTouched.value || !newPassword.value) return ''
  return newPassword.value.length >= 8 ? '' : 'Минимум 8 символов'
})

const newPasswordConfirmStatus = computed((): 'error' | 'success' | undefined => {
  if (!newPasswordConfirmTouched.value || !newPasswordConfirm.value) return undefined
  return newPasswordConfirm.value === newPassword.value ? 'success' : 'error'
})

async function savePassword() {
  newPasswordTouched.value        = true
  newPasswordConfirmTouched.value = true
  if (newPasswordLengthError.value || !currentPassword.value || !newPassword.value || !newPasswordConfirm.value) return
  if (newPassword.value !== newPasswordConfirm.value) return
  savingPassword.value = true
  passwordError.value = ''
  passwordSuccess.value = false
  try {
    await api.changePassword(currentPassword.value, newPassword.value, newPasswordConfirm.value)
    passwordSuccess.value = true
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirm.value = ''
    setTimeout(() => passwordSuccess.value = false, 3000)
  } catch (e: unknown) {
    passwordError.value = e instanceof Error ? e.message : 'Ошибка смены пароля'
  } finally {
    savingPassword.value = false
  }
}

const slotOptions = [
  { value: '10', label: '10 минут' },
  { value: '15', label: '15 минут' },
  { value: '20', label: '20 минут' },
  { value: '30', label: '30 минут' },
  { value: '45', label: '45 минут' },
  { value: '60', label: '1 час' },
]


onMounted(() => {
  if (authStore.shop) {
    shopName.value = authStore.shop.name || ''
    workStart.value = authStore.shop.work_start || '09:00'
    workEnd.value = authStore.shop.work_end || '20:00'
    slotDuration.value = String(authStore.shop.slot_duration || 30)
    timezone.value = authStore.shop.timezone || 'Europe/Moscow'
  }
})

// ── YooKassa ──────────────────────────────────────────────────
const yookassaShopId    = ref('')
const yookassaSecretKey = ref('')
const savingYookassa    = ref(false)
const yookassaSuccess   = ref(false)
const yookassaError     = ref('')

onMounted(() => {
  yookassaShopId.value = authStore.shop?.yookassa_shop_id || ''
})

async function saveYookassa() {
  if (!yookassaShopId.value) {
    yookassaError.value = 'Укажите Shop ID'
    return
  }
  savingYookassa.value = true
  yookassaError.value  = ''
  yookassaSuccess.value = false
  try {
    const payload: Record<string, string | null> = {
      yookassa_shop_id: yookassaShopId.value,
    }
    if (yookassaSecretKey.value) {
      payload.yookassa_secret_key = yookassaSecretKey.value
    }
    const updated = await api.updateShop(payload)
    if (authStore.shop) {
      authStore.shop.yookassa_shop_id = updated.yookassa_shop_id ?? yookassaShopId.value
    }
    yookassaSecretKey.value = ''
    yookassaSuccess.value = true
    setTimeout(() => yookassaSuccess.value = false, 3000)
  } catch (e: unknown) {
    yookassaError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingYookassa.value = false
  }
}

async function saveWorkHours() {
  if (workStart.value >= workEnd.value) {
    hoursError.value = 'Время начала должно быть раньше времени окончания'
    return
  }
  savingHours.value = true
  hoursError.value = ''
  hoursSuccess.value = false
  try {
    const updated = await api.updateShop({
      work_start: workStart.value,
      work_end: workEnd.value,
      slot_duration: Number(slotDuration.value),
      timezone: timezone.value,
    })
    if (authStore.shop) {
      authStore.shop.work_start = updated.work_start
      authStore.shop.work_end = updated.work_end
      authStore.shop.slot_duration = updated.slot_duration
      authStore.shop.timezone = updated.timezone
    }
    hoursSuccess.value = true
    setTimeout(() => hoursSuccess.value = false, 3000)
  } catch (e: unknown) {
    hoursError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingHours.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Настройки</h1>
      <p class="text-gray-500 dark:text-gray-400 mt-1">Управление магазином</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <!-- Левая колонка -->
      <div class="flex flex-col gap-6">
        <!-- Shop info -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Информация о магазине</h2>
          <div class="space-y-4">
            <div>
              <label class="label">Название магазина</label>
              <div class="flex gap-2">
                <input v-model="shopName" type="text" class="input flex-1" placeholder="Название магазина" />
                <button @click="saveShopName" :disabled="savingName" class="btn-primary whitespace-nowrap">
                  {{ savingName ? 'Сохраняем…' : nameSuccess ? 'Сохранено!' : 'Сохранить' }}
                </button>
              </div>
              <p v-if="nameError" class="text-red-500 text-xs mt-1">{{ nameError }}</p>
            </div>
            <div>
              <label class="label">API ключ</label>
              <input type="text" :value="authStore.shop?.api_key" class="input font-mono text-sm" disabled />
            </div>
            <div>
              <label class="label">Тарифный план</label>
              <div class="flex items-center gap-3">
                <span class="badge bg-primary-100 dark:bg-primary-900/30 text-primary-800 dark:text-primary-300 text-sm px-3 py-1">
                  {{ authStore.shop?.subscription_plan?.toUpperCase() || 'MICRO' }}
                </span>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                  Действует до: {{ authStore.shop?.subscription_expires_at
                    ? new Date(authStore.shop.subscription_expires_at).toLocaleDateString('ru-RU')
                    : 'бессрочно' }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Widget code -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Код виджета</h2>
          <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">
            Скопируйте этот код и вставьте перед закрывающим тегом &lt;/body&gt; на вашем сайте:
          </p>
          <div class="relative">
            <pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-sm">{{ widgetCode }}</pre>
            <button @click="copyCode" class="absolute top-2 right-2 btn-ghost btn-sm bg-gray-800 text-white hover:bg-gray-700">
              <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              {{ copied ? 'Скопировано!' : 'Копировать' }}
            </button>
          </div>
        </div>

        <!-- Change password -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Смена пароля</h2>
          <div class="space-y-4">
            <PasswordInput label="Текущий пароль" v-model="currentPassword" placeholder="Введите текущий пароль" autocomplete="current-password" />

            <div>
              <PasswordInput label="Новый пароль" v-model="newPassword" placeholder="Минимум 8 символов" autocomplete="new-password" with-generate @generate="v => { newPasswordConfirm = v; newPasswordConfirmTouched = true }" @blur="newPasswordTouched = true" />
              <p v-if="newPasswordLengthError" class="mt-1 text-sm text-red-500">{{ newPasswordLengthError }}</p>
            </div>

            <div>
              <PasswordInput label="Подтверждение нового пароля" v-model="newPasswordConfirm" placeholder="Повторите новый пароль" autocomplete="new-password" :status="newPasswordConfirmStatus" @blur="newPasswordConfirmTouched = true" />
              <p v-if="newPasswordConfirmStatus === 'error'"   class="mt-1 text-sm text-red-500">Пароли не совпадают</p>
              <p v-if="newPasswordConfirmStatus === 'success'" class="mt-1 text-sm text-green-600">Пароли совпадают</p>
            </div>
            <div v-if="passwordError" class="text-sm text-red-600 dark:text-red-400">{{ passwordError }}</div>
            <div v-if="passwordSuccess" class="text-sm text-green-600 dark:text-green-400">Пароль успешно изменён!</div>
            <button @click="savePassword" :disabled="savingPassword" class="btn-primary">
              {{ savingPassword ? 'Сохранение...' : 'Сменить пароль' }}
            </button>
          </div>
        </div>

        <!-- Telegram -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Telegram уведомления</h2>
          <div v-if="authStore.shop?.telegram_bot_connected" class="space-y-3">
            <div class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
              <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <div class="flex-1">
                <div class="font-medium text-green-800 dark:text-green-300">Telegram подключён</div>
                <div class="text-sm text-green-600 dark:text-green-400">Уведомления о новых записях и заказах активны</div>
              </div>
            </div>
            <button @click="showDisconnectConfirm = true" :disabled="disconnecting" class="btn-secondary text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 border-red-200 dark:border-red-800">
              {{ disconnecting ? 'Отключение...' : 'Отключить Telegram' }}
            </button>
            <div v-if="telegramError" class="text-red-600 text-sm">{{ telegramError }}</div>
          </div>
          <div v-else class="space-y-4">
            <p class="text-gray-500 dark:text-gray-400 text-sm">
              Подключите Telegram для получения уведомлений о новых заказах и записях.
            </p>
            <div v-if="telegramCode" class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-2">1. Откройте бота <a href="https://t.me/sb_widget_bot" target="_blank" class="font-semibold underline">@sb_widget_bot</a></div>
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-1">2. Отправьте код:</div>
              <div class="font-mono text-lg font-bold text-blue-900 dark:text-blue-200 tracking-widest">{{ telegramCode }}</div>
              <div class="text-xs text-blue-600 dark:text-blue-400 mt-2">Код действителен 10 минут</div>
            </div>
            <button v-if="!telegramCode" @click="generateTelegramCode" :disabled="generatingCode" class="btn-primary">
              {{ generatingCode ? 'Генерация...' : 'Получить код подключения' }}
            </button>
            <div v-if="telegramError" class="text-red-600 text-sm">{{ telegramError }}</div>
          </div>
        </div>
      </div>

      <!-- Правая колонка -->
      <div class="flex flex-col gap-6">
        <!-- Work hours -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Рабочие часы</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Время работы и шаг слотов для записи</p>

          <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
              <label class="label">Начало рабочего дня</label>
              <TimeInput v-model="workStart" />
            </div>
            <div>
              <label class="label">Конец рабочего дня</label>
              <TimeInput v-model="workEnd" />
            </div>
          </div>

          <div class="mb-4">
            <label class="label">Шаг слота (интервал записи)</label>
            <CustomSelect
                v-model="slotDuration"
                :options="slotOptions"
                label="Шаг слота (интервал записи)"
                placeholder="Выберите интервал"
            />
          </div>

          <div class="mb-4">
            <label class="label">Часовой пояс</label>
            <CustomSelect
                v-model="timezone"
                :options="timezoneOptions"
                label="Часовой пояс"
                placeholder="Выберите часовой пояс"
                searchable
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
              Используется для корректного отображения слотов записи
            </p>
          </div>

          <div v-if="hoursError" class="mb-3 text-sm text-red-600 dark:text-red-400">{{ hoursError }}</div>
          <div v-if="hoursSuccess" class="mb-3 text-sm text-green-600 dark:text-green-400">Сохранено!</div>

          <button @click="saveWorkHours" :disabled="savingHours" class="btn-primary">
            {{ savingHours ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>

        <!-- Payment settings -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">ЮКасса — приём оплаты</h2>

          <div v-if="authStore.shop?.yookassa_shop_id" class="mb-4 flex items-center gap-2 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
            <svg class="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm text-green-700 dark:text-green-400">Подключено · Shop ID: <span class="font-mono font-medium">{{ authStore.shop.yookassa_shop_id }}</span></span>
          </div>

          <div class="space-y-4">
            <div>
              <label class="label">Shop ID <span class="text-gray-400 font-normal">(из личного кабинета ЮКасса)</span></label>
              <input v-model="yookassaShopId" type="text" class="input font-mono" placeholder="123456" />
            </div>
            <div>
              <label class="label">Секретный ключ <span class="text-gray-400 font-normal">(оставьте пустым чтобы не менять)</span></label>
              <PasswordInput v-model="yookassaSecretKey" placeholder="live_xxxxxxxxxxxxxxxxxxxx" />
            </div>

            <div v-if="yookassaError" class="text-sm text-red-600">{{ yookassaError }}</div>
            <div v-if="yookassaSuccess" class="text-sm text-green-600">Сохранено!</div>

            <button @click="saveYookassa" :disabled="savingYookassa" class="btn-primary">
              {{ savingYookassa ? 'Сохранение...' : 'Сохранить' }}
            </button>

            <p class="text-xs text-gray-400 dark:text-gray-500">
              Настройки из раздела «Интеграции → API» в
              <a href="https://yookassa.ru/my" target="_blank" rel="noopener" class="text-primary-500 hover:underline">личном кабинете ЮКасса</a>.
              Секретный ключ нужен для приёма платежей в виджете магазина.
            </p>
          </div>
        </div>

        <!-- Widget customization -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Внешний вид виджета</h2>
          <div class="py-8 text-center text-gray-500 dark:text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
            </svg>
            <p>Настройка цветов, шрифтов и логотипа</p>
            <p class="text-sm text-primary-600 mt-2">В разработке</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <ConfirmDialog
    v-if="showDisconnectConfirm"
    title="Отключить Telegram?"
    message="Уведомления о новых записях и заказах перестанут приходить. Подключить заново можно в любой момент."
    confirm-text="Отключить"
    :danger="true"
    @confirm="showDisconnectConfirm = false; disconnectTelegram()"
    @cancel="showDisconnectConfirm = false"
  />
</template>
