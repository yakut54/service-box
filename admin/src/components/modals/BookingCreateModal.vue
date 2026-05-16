<script setup lang="ts">
import { ref, computed, reactive, watch } from 'vue'
import { useBookingsStore } from '@/stores/bookings'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/lib/api'
import { handlePhoneInput, isValidPhone } from '@/composables/usePhoneInput'
import { RouterLink } from 'vue-router'
import CustomSelect from '@/components/CustomSelect.vue'
import DatePicker from '@/components/DatePicker.vue'
import UiModal from '@/shared/ui/UiModal.vue'
import type { Product, BookingSlot } from '@/types'

const props = defineProps<{
  modelValue: boolean
  prefillDate?: string
  services: Product[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'created': []
}>()

const bookingsStore = useBookingsStore()
const authStore     = useAuthStore()

const shopTz = computed(() => {
  const tz = authStore.shop?.timezone
  if (!tz) return 'Europe/Moscow'
  try { Intl.DateTimeFormat(undefined, { timeZone: tz }); return tz } catch { return 'Europe/Moscow' }
})

function shopToday(): string {
  return new Date().toLocaleDateString('sv', { timeZone: shopTz.value })
}

const serviceOptions = computed(() => [
  { value: '', label: 'Выберите услугу' },
  ...props.services.map((s) => ({ value: s.id, label: s.name })),
])

const masterModalOptions = computed(() => [
  { value: '', label: 'Любой доступный' },
  ...bookingsStore.masters
    .filter(m => !modalForm.value.service_id || m.services?.some(s => s.id === modalForm.value.service_id))
    .map(m => ({ value: m.id, label: m.name })),
])

const modalForm = ref({
  service_id: '', master_id: '', date: '', slot: '',
  customer_name: '', customer_phone: '', customer_email: '', notes: '',
})

const availableSlots  = ref<BookingSlot[]>([])
const loadingSlots    = ref(false)
const slotError       = ref<string | null>(null)
const creating        = ref(false)
const modalError      = ref('')

const bookingErrors = reactive({ customer_name: '', customer_phone: '', customer_email: '' })

function validateName(v: string)  { bookingErrors.customer_name  = v.trim() ? '' : 'Введите имя клиента' }
function validatePhone(v: string) {
  if (!v) { bookingErrors.customer_phone = 'Телефон обязателен'; return }
  bookingErrors.customer_phone = isValidPhone(v) ? '' : 'Введите полный номер: +7 (XXX) XXX-XX-XX'
}
function validateEmail(v: string) {
  if (!v) { bookingErrors.customer_email = ''; return }
  bookingErrors.customer_email = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Некорректный email'
}
function resetErrors() {
  bookingErrors.customer_name = ''; bookingErrors.customer_phone = ''; bookingErrors.customer_email = ''
}

const isFormValid = computed(() =>
  !bookingErrors.customer_name && !bookingErrors.customer_phone && !bookingErrors.customer_email &&
  modalForm.value.customer_name.trim() && modalForm.value.customer_phone &&
  isValidPhone(modalForm.value.customer_phone) &&
  modalForm.value.service_id && modalForm.value.slot
)

const submitHint = computed(() => {
  if (!modalForm.value.service_id)                                         return 'Выберите услугу'
  if (!modalForm.value.date)                                               return 'Выберите дату'
  if (!modalForm.value.slot)                                               return 'Выберите время'
  if (!modalForm.value.customer_name.trim())                               return 'Введите имя клиента'
  if (!modalForm.value.customer_phone || !isValidPhone(modalForm.value.customer_phone)) return 'Введите телефон'
  return ''
})

watch(() => props.modelValue, (open) => {
  if (!open) return
  modalError.value  = ''
  resetErrors()

  let preDate: string
  if (props.prefillDate) {
    preDate = props.prefillDate
  } else {
    const today   = shopToday()
    const shopNow = new Date().toLocaleTimeString('sv', { timeZone: shopTz.value }).slice(0, 5)
    const workEnd = authStore.shop?.work_end || '20:00'
    if (shopNow >= workEnd) {
      const d = new Date(today + 'T12:00:00')
      d.setDate(d.getDate() + 1)
      preDate = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    } else {
      preDate = today
    }
  }

  modalForm.value = {
    service_id: '', master_id: '', date: preDate, slot: '',
    customer_name: '', customer_phone: '', customer_email: '', notes: '',
  }
  availableSlots.value = []
  slotError.value = null
})

watch(() => modalForm.value.service_id, (serviceId) => {
  if (!serviceId || !modalForm.value.master_id) return
  const master = bookingsStore.masters.find(m => m.id === modalForm.value.master_id)
  if (!master?.services?.some(s => s.id === serviceId)) {
    modalForm.value.master_id = ''
  }
})

watch([
  () => modalForm.value.service_id,
  () => modalForm.value.date,
  () => modalForm.value.master_id,
], async () => {
  slotError.value = null
  if (!modalForm.value.service_id || !modalForm.value.date) { availableSlots.value = []; return }
  loadingSlots.value = true
  try {
    const params: Record<string, string> = { service_id: modalForm.value.service_id, date: modalForm.value.date }
    if (modalForm.value.master_id) params.master_id = modalForm.value.master_id
    const resp = await api.getAvailableSlots(params)
    availableSlots.value = resp.slots || []
  } catch (err) {
    availableSlots.value = []
    slotError.value = err instanceof ApiError ? err.message : 'Не удалось загрузить слоты'
  }
  loadingSlots.value = false
  modalForm.value.slot = ''
})

async function submit() {
  validateName(modalForm.value.customer_name)
  validatePhone(modalForm.value.customer_phone)
  validateEmail(modalForm.value.customer_email)
  if (!isFormValid.value) { modalError.value = 'Заполните все обязательные поля корректно'; return }
  creating.value = true
  modalError.value = ''
  try {
    await bookingsStore.createBooking({
      service_id: modalForm.value.service_id,
      start_time: modalForm.value.slot,
      master_id:  modalForm.value.master_id || undefined,
      customer: {
        name:  modalForm.value.customer_name,
        phone: modalForm.value.customer_phone,
        email: modalForm.value.customer_email || undefined,
      },
      notes: modalForm.value.notes || undefined,
    })
    emit('created')
    emit('update:modelValue', false)
  } catch (e: unknown) {
    modalError.value = e instanceof Error ? e.message : 'Не удалось создать запись'
  }
  creating.value = false
}
</script>

<template>
  <UiModal :modelValue="modelValue" @update:modelValue="emit('update:modelValue', $event)">
    <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
      <h2 class="text-lg font-bold text-gray-900 dark:text-white">Новая запись</h2>
      <button @click="emit('update:modelValue', false)" class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="p-4 space-y-4">
      <div v-if="modalError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm dark:bg-red-900/20 dark:border-red-800 dark:text-red-400">{{ modalError }}</div>

      <div>
        <p class="label">Услуга <span class="text-red-500">*</span></p>
        <CustomSelect v-model="modalForm.service_id" :options="serviceOptions" searchable />
        <p v-if="services.length === 0" class="mt-1 text-xs text-red-500">
          Нет активных услуг. <RouterLink to="/products" class="underline">Добавьте услугу</RouterLink>
        </p>
      </div>

      <div>
        <p class="label">Дата <span class="text-red-500">*</span></p>
        <DatePicker v-model="modalForm.date" :min="shopToday()" placeholder="Выберите дату" />
      </div>

      <div>
        <p class="label">Мастер</p>
        <CustomSelect v-model="modalForm.master_id" :options="masterModalOptions" searchable />
      </div>

      <div>
        <p class="label">Время <span class="text-red-500">*</span></p>
        <div v-if="!modalForm.service_id" class="flex items-center gap-2 py-2.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 text-sm text-gray-400 dark:text-gray-500">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Сначала выберите услугу
        </div>
        <div v-else-if="!modalForm.date" class="flex items-center gap-2 py-2.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 text-sm text-gray-400 dark:text-gray-500">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          Выберите дату
        </div>
        <div v-else-if="loadingSlots" class="flex items-center gap-2 py-2.5 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 text-sm text-gray-500 dark:text-gray-400">
          <div class="animate-spin w-3.5 h-3.5 border-2 border-primary-500 border-t-transparent rounded-full flex-shrink-0"></div>
          Загрузка слотов...
        </div>
        <div v-else-if="slotError" class="flex items-start gap-2 py-2.5 px-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
          <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          {{ slotError }}
        </div>
        <div v-else-if="availableSlots.length === 0" class="flex items-center gap-2 py-2.5 px-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-sm text-amber-700 dark:text-amber-400">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span>Нет свободных слотов<template v-if="modalForm.master_id"> для этого мастера</template> — выберите другую дату<template v-if="modalForm.master_id"> или другого мастера</template></span>
        </div>
        <div v-else class="grid grid-cols-4 gap-2">
          <button v-for="slot in availableSlots" :key="slot.time" type="button"
            :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
              modalForm.slot === slot.datetime
                ? 'border-primary-600 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-primary-400 dark:hover:border-primary-500']"
            @click="modalForm.slot = slot.datetime">{{ slot.time }}</button>
        </div>
      </div>

      <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
        <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Клиент</div>
        <div class="space-y-3">
          <div>
            <p class="label">Имя <span class="text-red-500">*</span></p>
            <input v-model="modalForm.customer_name"
              @input="validateName(modalForm.customer_name)"
              @blur="validateName(modalForm.customer_name)"
              type="text" :class="['input', bookingErrors.customer_name ? 'input-error' : '']" placeholder="Иван Иванов" />
            <p v-if="bookingErrors.customer_name" class="mt-1 text-xs text-red-500">{{ bookingErrors.customer_name }}</p>
          </div>
          <div>
            <p class="label">Телефон <span class="text-red-500">*</span></p>
            <input :value="modalForm.customer_phone"
              @input="handlePhoneInput($event, (v) => { modalForm.customer_phone = v; validatePhone(v) })"
              @blur="validatePhone(modalForm.customer_phone)" type="tel"
              :class="['input', bookingErrors.customer_phone ? 'input-error' : modalForm.customer_phone && !bookingErrors.customer_phone ? 'input-success' : '']"
              placeholder="+7 (999) 123-45-67" />
            <p v-if="bookingErrors.customer_phone" class="mt-1 text-xs text-red-500">{{ bookingErrors.customer_phone }}</p>
          </div>
          <div>
            <p class="label">Email</p>
            <input v-model="modalForm.customer_email"
              @input="validateEmail(modalForm.customer_email)"
              @blur="validateEmail(modalForm.customer_email)"
              type="email"
              :class="['input', bookingErrors.customer_email ? 'input-error' : modalForm.customer_email && !bookingErrors.customer_email ? 'input-success' : '']"
              placeholder="email@example.com" />
            <p v-if="bookingErrors.customer_email" class="mt-1 text-xs text-red-500">{{ bookingErrors.customer_email }}</p>
          </div>
          <div>
            <p class="label">Примечание</p>
            <textarea v-model="modalForm.notes" class="input" rows="2" placeholder="Дополнительная информация..."></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-2 p-4 border-t border-gray-100 dark:border-gray-700 sticky bottom-0 bg-white dark:bg-gray-800 z-10">
      <p v-if="submitHint && !creating" class="text-xs text-center text-gray-400 dark:text-gray-500">{{ submitHint }}</p>
      <div class="flex gap-3">
        <button @click="emit('update:modelValue', false)" class="btn-secondary flex-1">Отмена</button>
        <button @click="submit" :disabled="creating || !isFormValid" class="btn-primary flex-1">
          <template v-if="creating">
            <div class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></div>
            Создание...
          </template>
          <template v-else>Создать запись</template>
        </button>
      </div>
    </div>
  </UiModal>
</template>
