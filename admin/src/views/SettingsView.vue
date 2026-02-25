<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import CustomSelect from '@/components/CustomSelect.vue'
import TimeInput from '@/components/TimeInput.vue'
import { timezoneOptions } from '@/shared/lib/timezones'

const authStore = useAuthStore()

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

async function generateTelegramCode() {
  if (!authStore.shop) return
  generatingCode.value = true
  telegramError.value = ''
  try {
    const resp = await api.generateTelegramCode()
    telegramCode.value = resp.code
  } catch (e: any) {
    telegramError.value = e.message || 'Ошибка генерации кода'
  }
  generatingCode.value = false
}

// ── Work hours ───────────────────────────────────────────────
const workStart = ref('09:00')
const workEnd = ref('20:00')
const slotDuration = ref('30')
const timezone = ref('Europe/Moscow')
const savingHours = ref(false)
const hoursSuccess = ref(false)
const hoursError = ref('')

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
    workStart.value = authStore.shop.work_start || '09:00'
    workEnd.value = authStore.shop.work_end || '20:00'
    slotDuration.value = String(authStore.shop.slot_duration || 30)
    timezone.value = (authStore.shop as any).timezone || 'Europe/Moscow'
  }
})

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
      ;(authStore.shop as any).timezone = updated.timezone
    }
    hoursSuccess.value = true
    setTimeout(() => hoursSuccess.value = false, 3000)
  } catch (e: any) {
    hoursError.value = e.message || 'Ошибка сохранения'
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
              <input type="text" :value="authStore.shop?.name" class="input" disabled />
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

        <!-- Telegram -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Telegram уведомления</h2>
          <div v-if="authStore.shop?.telegram_bot_connected" class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
            <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <div>
              <div class="font-medium text-green-800 dark:text-green-300">Telegram подключён</div>
              <div class="text-sm text-green-600 dark:text-green-400">Вы будете получать уведомления о новых заказах</div>
            </div>
          </div>
          <div v-else class="space-y-4">
            <p class="text-gray-500 dark:text-gray-400 text-sm">
              Подключите Telegram для получения уведомлений о новых заказах и записях.
            </p>
            <div v-if="telegramCode" class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-2">1. Откройте бота <strong>@sb_widget_bot</strong></div>
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-2">2. Отправьте команду: <span class="font-mono">/start {{ telegramCode }}</span></div>
              <div class="text-sm text-blue-800 dark:text-blue-300">3. Бот ответит подтверждением</div>
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
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Платёжная система</h2>
          <div class="space-y-4">
            <div>
              <label class="label">Провайдер</label>
              <CustomSelect
                  model-value="yookassa"
                  :options="[{ value: 'yookassa', label: 'YooKassa' }]"
                  disabled
              />
            </div>
            <div v-if="authStore.shop?.yookassa_shop_id">
              <label class="label">Shop ID</label>
              <input type="text" :value="authStore.shop.yookassa_shop_id" class="input" disabled />
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Для изменения платёжных настроек обратитесь в поддержку.</p>
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
</template>
