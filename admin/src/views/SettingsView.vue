<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'

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
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Настройки</h1>
      <p class="text-gray-500 mt-1">Управление магазином</p>
    </div>

    <!-- Shop info -->
    <div class="card mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Информация о магазине</h2>
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
            <span class="badge bg-primary-100 text-primary-800 text-sm px-3 py-1">
              {{ authStore.shop?.subscription_plan?.toUpperCase() || 'MICRO' }}
            </span>
            <span class="text-sm text-gray-500">
              Действует до: {{ authStore.shop?.subscription_expires_at
                ? new Date(authStore.shop.subscription_expires_at).toLocaleDateString('ru-RU')
                : 'бессрочно' }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Widget code -->
    <div class="card mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Код виджета</h2>
      <p class="text-gray-500 text-sm mb-4">
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
    <div class="card mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Telegram уведомления</h2>
      <div v-if="authStore.shop?.telegram_bot_connected" class="flex items-center gap-3 p-4 bg-green-50 rounded-lg">
        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div>
          <div class="font-medium text-green-800">Telegram подключён</div>
          <div class="text-sm text-green-600">Вы будете получать уведомления о новых заказах</div>
        </div>
      </div>
      <div v-else class="space-y-4">
        <p class="text-gray-500 text-sm">
          Подключите Telegram для получения уведомлений о новых заказах и записях.
        </p>
        <div v-if="telegramCode" class="p-4 bg-blue-50 rounded-lg">
          <div class="text-sm text-blue-800 mb-2">1. Откройте бота <strong>@ServiceBoxBot</strong></div>
          <div class="text-sm text-blue-800 mb-2">2. Отправьте команду /start</div>
          <div class="text-sm text-blue-800">3. Введите код: <span class="font-mono font-bold text-lg">{{ telegramCode }}</span></div>
          <div class="text-xs text-blue-600 mt-2">Код действителен 10 минут</div>
        </div>
        <button v-if="!telegramCode" @click="generateTelegramCode" :disabled="generatingCode" class="btn-primary">
          {{ generatingCode ? 'Генерация...' : 'Получить код подключения' }}
        </button>
        <div v-if="telegramError" class="text-red-600 text-sm">{{ telegramError }}</div>
      </div>
    </div>

    <!-- Payment settings -->
    <div class="card mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Платёжная система</h2>
      <div class="space-y-4">
        <div>
          <label class="label">Провайдер</label>
          <select class="input" disabled>
            <option value="yookassa">YooKassa</option>
          </select>
        </div>
        <div v-if="authStore.shop?.yookassa_shop_id">
          <label class="label">Shop ID</label>
          <input type="text" :value="authStore.shop.yookassa_shop_id" class="input" disabled />
        </div>
        <p class="text-sm text-gray-500">Для изменения платёжных настроек обратитесь в поддержку.</p>
      </div>
    </div>

    <!-- Widget customization -->
    <div class="card">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Внешний вид виджета</h2>
      <div class="py-8 text-center text-gray-500">
        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
        </svg>
        <p>Настройка цветов, шрифтов и логотипа</p>
        <p class="text-sm text-primary-600 mt-2">В разработке</p>
      </div>
    </div>
  </div>
</template>
