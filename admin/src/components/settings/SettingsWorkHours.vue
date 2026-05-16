<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import TimeInput from '@/components/TimeInput.vue'
import CustomSelect from '@/components/CustomSelect.vue'
import { timezoneOptions } from '@/shared/lib/timezones'

const authStore = useAuthStore()

const workStart    = ref('09:00')
const workEnd      = ref('20:00')
const slotDuration      = ref('30')
const minBookingNotice  = ref('0')
const timezone          = ref('Europe/Moscow')
const saving       = ref(false)
const success      = ref(false)
const error        = ref('')

const noticeOptions = [
  { value: '0',    label: 'Без ограничений' },
  { value: '30',   label: '30 минут' },
  { value: '60',   label: '1 час' },
  { value: '120',  label: '2 часа' },
  { value: '180',  label: '3 часа' },
  { value: '360',  label: '6 часов' },
  { value: '720',  label: '12 часов' },
  { value: '1440', label: '24 часа' },
  { value: '2880', label: '48 часов' },
]

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
    workStart.value    = authStore.shop.work_start    || '09:00'
    workEnd.value      = authStore.shop.work_end      || '20:00'
    slotDuration.value     = String(authStore.shop.slot_duration      || 30)
    minBookingNotice.value = String(authStore.shop.min_booking_notice ?? 0)
    timezone.value         = authStore.shop.timezone                  || 'Europe/Moscow'
  }
})

async function save() {
  if (workStart.value >= workEnd.value) {
    error.value = 'Время начала должно быть раньше времени окончания'
    return
  }
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    const updated = await api.updateShop({
      work_start:          workStart.value,
      work_end:            workEnd.value,
      slot_duration:       Number(slotDuration.value),
      min_booking_notice:  Number(minBookingNotice.value),
      timezone:            timezone.value,
    })
    if (authStore.shop) {
      authStore.shop.work_start          = updated.work_start
      authStore.shop.work_end            = updated.work_end
      authStore.shop.slot_duration       = updated.slot_duration
      authStore.shop.min_booking_notice  = updated.min_booking_notice
      authStore.shop.timezone            = updated.timezone
    }
    success.value = true
    setTimeout(() => success.value = false, 3000)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Рабочие часы</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Время работы и шаг слотов для записи</p>

    <div class="grid grid-cols-2 gap-4 mb-4">
      <div>
        <p class="label">Начало рабочего дня</p>
        <TimeInput v-model="workStart" />
      </div>
      <div>
        <p class="label">Конец рабочего дня</p>
        <TimeInput v-model="workEnd" />
      </div>
    </div>

    <div class="mb-4">
      <p class="label">Шаг слота (интервал записи)</p>
      <CustomSelect v-model="slotDuration" :options="slotOptions" placeholder="Выберите интервал" />
    </div>

    <div class="mb-4">
      <p class="label">Минимальное время до записи онлайн</p>
      <CustomSelect v-model="minBookingNotice" :options="noticeOptions" placeholder="Выберите ограничение" />
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Слоты ближе этого времени не будут показаны в виджете</p>
    </div>

    <div class="mb-4">
      <p class="label">Часовой пояс</p>
      <CustomSelect v-model="timezone" :options="timezoneOptions" placeholder="Выберите часовой пояс" searchable />
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Используется для корректного отображения слотов записи</p>
    </div>

    <div v-if="error"   class="mb-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
    <div v-if="success" class="mb-3 text-sm text-green-600 dark:text-green-400">Сохранено!</div>

    <button @click="save" :disabled="saving" class="btn-primary">
      {{ saving ? 'Сохранение...' : 'Сохранить' }}
    </button>
  </div>
</template>
