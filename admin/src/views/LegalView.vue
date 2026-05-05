<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'

const authStore = useAuthStore()

// ── Вкладки ───────────────────────────────────────────────────
const tabs = [
  { key: 'requisites',    label: 'Реквизиты' },
  { key: 'offer',         label: 'Публичная оферта' },
  { key: 'privacy',       label: 'Политика конфиденциальности' },
  { key: 'personal_data', label: 'Персональные данные' },
  { key: 'marketing',     label: 'Уведомления' },
] as const

type TabKey = typeof tabs[number]['key']

const activeTab = ref<TabKey>('requisites')

// ── Поля ──────────────────────────────────────────────────────
const requisites   = ref('')
const contactEmail = ref('')
const contactPhone = ref('')

const offerText        = ref('')
const privacyText      = ref('')
const personalDataText = ref('')
const marketingText    = ref('')

// ── URL документов ────────────────────────────────────────────
const apiKey = computed(() => authStore.shop?.api_key || '')

// Базовый URL платформы — берём из текущего окна в проде,
// в dev-режиме API_URL содержит :8080, заменяем на корень
const baseUrl = computed(() => {
  const apiBase = import.meta.env.VITE_API_URL || ''
  return apiBase.replace('/api', '').replace(':8080', '') || window.location.origin
})

function docUrl(type: string) {
  return `${baseUrl.value}/legal/${apiKey.value}/${type}`
}

// ── Статус сохранения ─────────────────────────────────────────
const saving  = ref(false)
const success = ref(false)
const error   = ref('')

// ── Заполненность вкладки — зелёная точка ────────────────────
function tabFilled(key: TabKey): boolean {
  switch (key) {
    case 'requisites':    return !!(requisites.value.trim() || contactEmail.value.trim())
    case 'offer':         return !!offerText.value.trim()
    case 'privacy':       return !!privacyText.value.trim()
    case 'personal_data': return !!personalDataText.value.trim()
    case 'marketing':     return !!marketingText.value.trim()
  }
}

// ── Загрузка ──────────────────────────────────────────────────
onMounted(() => {
  const cfg = authStore.shop?.legal_config ?? {}
  requisites.value   = cfg.requisites        ?? ''
  contactEmail.value = cfg.contact_email     ?? ''
  contactPhone.value = cfg.contact_phone     ?? ''
  offerText.value        = cfg.public_offer_text             ?? ''
  privacyText.value      = cfg.privacy_policy_text           ?? ''
  personalDataText.value = cfg.personal_data_consent_text    ?? ''
  marketingText.value    = cfg.marketing_consent_text        ?? ''
})

// ── Сохранение текущей вкладки ────────────────────────────────
async function save() {
  saving.value  = true
  success.value = false
  error.value   = ''

  const payload: Record<string, string | null> = {}

  switch (activeTab.value) {
    case 'requisites':
      payload.requisites    = requisites.value.trim()   || null
      payload.contact_email = contactEmail.value.trim() || null
      payload.contact_phone = contactPhone.value.trim() || null
      break
    case 'offer':
      payload.public_offer_text = offerText.value.trim() || null
      break
    case 'privacy':
      payload.privacy_policy_text = privacyText.value.trim() || null
      break
    case 'personal_data':
      payload.personal_data_consent_text = personalDataText.value.trim() || null
      break
    case 'marketing':
      payload.marketing_consent_text = marketingText.value.trim() || null
      break
  }

  try {
    const updated = await api.updateShop({ legal_config: payload })
    if (authStore.shop) {
      authStore.shop.legal_config = updated.legal_config
    }
    success.value = true
    setTimeout(() => { success.value = false }, 3000)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

// ── Копирование URL ────────────────────────────────────────────
const copiedKey = ref('')
function copyUrl(type: string, key: string) {
  navigator.clipboard.writeText(docUrl(type))
  copiedKey.value = key
  setTimeout(() => { copiedKey.value = '' }, 2000)
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Юридические документы</h1>
      <p class="text-gray-500 dark:text-gray-400 mt-1">
        Тексты документов отображаются покупателям на странице оформления заказа и по прямым ссылкам
      </p>
    </div>

    <div class="flex gap-6 items-start flex-col lg:flex-row">

      <!-- Вкладки (левая колонка на десктопе, горизонтальные на мобиле) -->
      <div class="lg:w-56 flex-shrink-0">
        <nav class="flex lg:flex-col gap-1">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            @click="activeTab = tab.key"
            :class="[
              'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors text-left w-full',
              activeTab === tab.key
                ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
                : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'
            ]"
          >
            <!-- Индикатор заполненности -->
            <span
              :class="[
                'w-2 h-2 rounded-full flex-shrink-0',
                tabFilled(tab.key) ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'
              ]"
            />
            <span class="truncate">{{ tab.label }}</span>
          </button>
        </nav>
      </div>

      <!-- Содержимое вкладки -->
      <div class="flex-1 min-w-0">

        <!-- ── Реквизиты ──────────────────────────────────────── -->
        <div v-if="activeTab === 'requisites'" class="card space-y-4">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Реквизиты и контакты</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400">
            Отображаются в нижней части каждого юридического документа
          </p>

          <div>
            <label class="label">Реквизиты (ИП/ООО, ИНН, ОГРН, адрес)</label>
            <textarea
              v-model="requisites"
              class="input"
              rows="5"
              style="min-height: 400px"
              placeholder="ИП Иванов Иван Иванович&#10;ИНН: 540100000000&#10;ОГРНИП: 315540100000000&#10;Юридический адрес: 630000, г. Новосибирск, ул. Ленина, д. 1&#10;Расчётный счёт: 40802810000000000000&#10;Банк: Сибирский филиал ПАО Сбербанк&#10;БИК: 045004641&#10;Корр. счёт: 30101810500000000641"
            />
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Email для связи</label>
              <input v-model="contactEmail" type="email" class="input" placeholder="info@myshop.ru" />
            </div>
            <div>
              <label class="label">Телефон для связи</label>
              <input v-model="contactPhone" type="tel" class="input" placeholder="+7 (900) 000-00-00" />
            </div>
          </div>
        </div>

        <!-- ── Оферта ──────────────────────────────────────────── -->
        <div v-else-if="activeTab === 'offer'" class="card space-y-4">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Публичная оферта</h2>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400 font-mono truncate max-w-xs">{{ docUrl('offer') }}</span>
              <button
                @click="copyUrl('offer', 'offer')"
                class="btn-ghost btn-sm flex-shrink-0"
                title="Скопировать ссылку"
              >
                <svg v-if="copiedKey !== 'offer'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <a :href="docUrl('offer')" target="_blank" class="btn-ghost btn-sm flex-shrink-0" title="Открыть">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
              </a>
            </div>
          </div>
          <textarea
            v-model="offerText"
            class="input font-mono text-sm"
            rows="18"
            placeholder="Текст публичной оферты..."
          />
        </div>

        <!-- ── Политика конфиденциальности ───────────────────── -->
        <div v-else-if="activeTab === 'privacy'" class="card space-y-4">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Политика конфиденциальности</h2>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400 font-mono truncate max-w-xs">{{ docUrl('privacy') }}</span>
              <button @click="copyUrl('privacy', 'privacy')" class="btn-ghost btn-sm flex-shrink-0" title="Скопировать ссылку">
                <svg v-if="copiedKey !== 'privacy'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <a :href="docUrl('privacy')" target="_blank" class="btn-ghost btn-sm flex-shrink-0" title="Открыть">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
              </a>
            </div>
          </div>
          <textarea
            v-model="privacyText"
            class="input font-mono text-sm"
            rows="18"
            placeholder="Текст политики конфиденциальности..."
          />
        </div>

        <!-- ── Персональные данные ─────────────────────────────── -->
        <div v-else-if="activeTab === 'personal_data'" class="card space-y-4">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Согласие на обработку персональных данных</h2>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400 font-mono truncate max-w-xs">{{ docUrl('personal-data') }}</span>
              <button @click="copyUrl('personal-data', 'personal_data')" class="btn-ghost btn-sm flex-shrink-0" title="Скопировать ссылку">
                <svg v-if="copiedKey !== 'personal_data'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <a :href="docUrl('personal-data')" target="_blank" class="btn-ghost btn-sm flex-shrink-0" title="Открыть">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
              </a>
            </div>
          </div>
          <p class="text-sm text-gray-500 dark:text-gray-400">
            Короткий текст согласия, который отображается прямо в форме оформления заказа рядом с чекбоксом.
          </p>
          <textarea
            v-model="personalDataText"
            class="input font-mono text-sm"
            rows="8"
            placeholder="Я согласен на обработку моих персональных данных в соответствии с Федеральным законом №152-ФЗ..."
          />
        </div>

        <!-- ── Маркетинговые уведомления ──────────────────────── -->
        <div v-else-if="activeTab === 'marketing'" class="card space-y-4">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Согласие на получение уведомлений</h2>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400 font-mono truncate max-w-xs">{{ docUrl('marketing') }}</span>
              <button @click="copyUrl('marketing', 'marketing')" class="btn-ghost btn-sm flex-shrink-0" title="Скопировать ссылку">
                <svg v-if="copiedKey !== 'marketing'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
              <a :href="docUrl('marketing')" target="_blank" class="btn-ghost btn-sm flex-shrink-0" title="Открыть">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
              </a>
            </div>
          </div>
          <textarea
            v-model="marketingText"
            class="input font-mono text-sm"
            rows="8"
            placeholder="Я согласен получать информационные и рекламные сообщения..."
          />
        </div>

        <!-- Статус + Сохранить -->
        <div class="flex items-center gap-4 mt-4">
          <button @click="save" :disabled="saving" class="btn-primary">
            {{ saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
          <span v-if="success" class="text-sm text-green-600 dark:text-green-400">Сохранено!</span>
          <span v-if="error"   class="text-sm text-red-600 dark:text-red-400">{{ error }}</span>
        </div>

      </div>
    </div>
  </div>
</template>
