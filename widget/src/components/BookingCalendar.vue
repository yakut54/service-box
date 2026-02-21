<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useShopStore } from '@/stores/shop'
import { formatPrice, cleanPhone, isPhoneValid, isEmailValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'
import SbSelect from '@/components/SbSelect.vue'

const props = defineProps<{ product: any }>()
const emit = defineEmits<{ back: []; success: [booking: any] }>()

const shopStore = useShopStore()

type Slot = {
  time: string
  datetime: string
  available: boolean
  masters: { id: string; name: string }[]
}

const loading = ref(false)
const submitting = ref(false)
const error = ref('')
const slots = ref<Slot[]>([])
const selectedDate = ref(todayStr())
const selectedSlot = ref<Slot | null>(null)
const selectedMasterId = ref<string | null>(null)

const form = ref({
  name: '',
  phone: '',
  email: '',
  notes: '',
})

const formErrors = ref<Record<string, string>>({})
const formTouched = ref<Record<string, boolean>>({})

const durationMinutes = computed(() => props.product?.service?.duration_minutes ?? 60)

// ── Live validation ─────────────────────────────────────────
function touch(field: string) {
  formTouched.value[field] = true
  validateField(field)
}

function validateField(field: string) {
  const e = { ...formErrors.value }
  delete e[field]
  switch (field) {
    case 'name':
      if (formTouched.value.name && !form.value.name.trim()) e.name = 'Укажите имя'
      else if (formTouched.value.name && form.value.name.trim().length < 2) e.name = 'Минимум 2 символа'
      break
    case 'phone':
      if (formTouched.value.phone && !form.value.phone.trim()) e.phone = 'Укажите телефон'
      else if (formTouched.value.phone && !isPhoneValid(form.value.phone)) e.phone = 'Введите полный номер: +7 (XXX) XXX-XX-XX'
      break
    case 'email':
      if (formTouched.value.email && form.value.email.trim() && !isEmailValid(form.value.email)) e.email = 'Некорректный email'
      break
  }
  formErrors.value = e
}

function isFieldValid(field: string): boolean {
  if (!formTouched.value[field]) return false
  if (formErrors.value[field]) return false
  switch (field) {
    case 'name': return form.value.name.trim().length >= 2
    case 'phone': return isPhoneValid(form.value.phone)
    case 'email': return form.value.email.trim() !== '' && isEmailValid(form.value.email)
    default: return false
  }
}

function onPhoneInput(e: Event) {
  handlePhoneInput(e, (v) => {
    form.value.phone = v
    if (formTouched.value.phone) validateField('phone')
  })
}

function todayStr(): string {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${dd}`
}

// ── Mini-calendar (full month grid, Calendly-style) ──────────
const today = new Date()
today.setHours(0, 0, 0, 0)

const calViewYear  = ref(today.getFullYear())
const calViewMonth = ref(today.getMonth()) // 0-based

// Max bookable date: today + 60 days
const maxDate = new Date(today)
maxDate.setDate(maxDate.getDate() + 60)

function dateToStr(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const calMonthLabel = computed(() => {
  const d = new Date(calViewYear.value, calViewMonth.value, 1)
  const month = d.toLocaleDateString('ru-RU', { month: 'long' })
  return `${month.charAt(0).toUpperCase() + month.slice(1)} ${calViewYear.value}`
})

// Weekday headers Mon–Sun
const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

type CalCell = {
  day: number | null   // null = empty padding cell
  value: string        // YYYY-MM-DD
  disabled: boolean
  isToday: boolean
}

const calendarCells = computed((): CalCell[] => {
  const year  = calViewYear.value
  const month = calViewMonth.value
  const firstDay = new Date(year, month, 1)
  const lastDay  = new Date(year, month + 1, 0)

  // Monday-based offset (0=Mon … 6=Sun)
  const startOffset = (firstDay.getDay() + 6) % 7

  const cells: CalCell[] = []

  // Empty padding cells before the 1st
  for (let i = 0; i < startOffset; i++) {
    cells.push({ day: null, value: '', disabled: true, isToday: false })
  }

  for (let d = 1; d <= lastDay.getDate(); d++) {
    const date = new Date(year, month, d)
    date.setHours(0, 0, 0, 0)
    const value = dateToStr(date)
    const disabled = date < today || date > maxDate
    const isToday  = date.getTime() === today.getTime()
    cells.push({ day: d, value, disabled, isToday })
  }

  return cells
})

const canPrevMonth = computed(() => {
  const now = new Date(today.getFullYear(), today.getMonth(), 1)
  const cur = new Date(calViewYear.value, calViewMonth.value, 1)
  return cur > now
})
const canNextMonth = computed(() => {
  const cur = new Date(calViewYear.value, calViewMonth.value, 1)
  const max = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1)
  return cur < max
})

function prevMonth() {
  if (!canPrevMonth.value) return
  if (calViewMonth.value === 0) { calViewMonth.value = 11; calViewYear.value-- }
  else calViewMonth.value--
}
function nextMonth() {
  if (!canNextMonth.value) return
  if (calViewMonth.value === 11) { calViewMonth.value = 0; calViewYear.value++ }
  else calViewMonth.value++
}

function selectCalDay(cell: CalCell) {
  if (!cell.day || cell.disabled) return
  selectedDate.value = cell.value
}

async function loadSlots() {
  loading.value = true
  error.value = ''
  selectedSlot.value = null
  selectedMasterId.value = null

  try {
    console.log('[BookingCalendar] loadSlots', { date: selectedDate.value, product: props.product.id })
    const result = await shopStore.getApi().getAvailableSlots({
      service_id: props.product.id,
      date: selectedDate.value,
    })
    console.log('[BookingCalendar] slots from API:', result.slots)
    slots.value = result.slots ?? []
    console.log('[BookingCalendar] after isPastSlot filter:', availableSlots.value.map(s => s.time))
  } catch (e: any) {
    error.value = e.message || 'Не удалось загрузить расписание'
    slots.value = []
    console.error('[BookingCalendar] error loading slots:', e)
  } finally {
    loading.value = false
  }
}

watch(selectedDate, () => loadSlots(), { immediate: true })

// ── Live clock ────────────────────────────────────────────────
const now = ref(new Date())
let _clockTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  _clockTimer = setInterval(() => { now.value = new Date() }, 1000)
})

onUnmounted(() => {
  if (_clockTimer) clearInterval(_clockTimer)
})

const shopTimezone = computed(() => shopStore.shop?.timezone || 'Europe/Moscow')

const clockDate = computed(() =>
  now.value.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', timeZone: shopTimezone.value })
)
const clockTime = computed(() =>
  now.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: shopTimezone.value })
)

function selectSlot(slot: Slot) {
  if (!slot.available) return
  selectedSlot.value = slot
  if (slot.masters.length === 1) {
    selectedMasterId.value = slot.masters[0].id
  } else if (slot.masters.length === 2) {
    selectedMasterId.value = null   // chips — no default
  } else {
    selectedMasterId.value = ''     // select — empty placeholder
  }
}

// Client-side past-slot guard using LOCAL time (server may be in different timezone).
// slot.time is "HH:MM" in shop's local time; selectedDate is "YYYY-MM-DD" local.
// new Date(year, month, day, h, m) always creates a local datetime — no UTC ambiguity.
const isPastSlot = (slot: Slot): boolean => {
  const [year, month, day] = selectedDate.value.split('-').map(Number)
  const [h, m] = slot.time.split(':').map(Number)
  const slotLocal = new Date(year, month - 1, day, h, m, 0, 0)
  return slotLocal <= new Date()
}

const availableSlots = computed(() => slots.value.filter(s => s.available && !isPastSlot(s)))
const unavailableSlots = computed(() => slots.value.filter(s => !s.available))

// Master select options for SbSelect
const masterOptions = computed(() =>
  selectedSlot.value?.masters.map(m => ({ value: m.id, label: m.name })) ?? []
)
const masterSelectValue = computed({
  get: () => selectedMasterId.value ?? '',
  set: (v: string) => { selectedMasterId.value = v || null },
})

function validate(): boolean {
  formTouched.value = { name: true, phone: true, email: true }
  const e: Record<string, string> = {}
  if (!form.value.name.trim()) e.name = 'Укажите имя'
  else if (form.value.name.trim().length < 2) e.name = 'Минимум 2 символа'
  if (!form.value.phone.trim()) e.phone = 'Укажите телефон'
  else if (!isPhoneValid(form.value.phone)) e.phone = 'Введите полный номер: +7 (XXX) XXX-XX-XX'
  if (form.value.email.trim() && !isEmailValid(form.value.email)) e.email = 'Некорректный email'
  formErrors.value = e
  return Object.keys(e).length === 0
}

async function handleSubmit() {
  if (!selectedSlot.value) return
  if (!validate()) return

  submitting.value = true
  error.value = ''

  try {
    const payload: Record<string, any> = {
      service_id: props.product.id,
      start_time: selectedSlot.value.datetime,
      customer: {
        name: form.value.name.trim(),
        phone: form.value.phone.trim(),
        email: form.value.email.trim() || null,
      },
      notes: form.value.notes.trim() || null,
    }
    if (selectedMasterId.value) {
      payload.master_id = selectedMasterId.value
    }

    const result = await shopStore.getApi().createBooking(payload)
    emit('success', result.data)
  } catch (e: any) {
    error.value = e.message || 'Ошибка при записи'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="sb-booking">
    <!-- Header -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <div class="sb-flex sb-items-center sb-gap-3" style="flex: 1; min-width: 0;">
        <button class="sb-btn sb-btn-ghost" @click="emit('back')">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Назад
        </button>
        <h2 class="sb-title" style="margin-bottom: 0;">Запись на услугу</h2>
      </div>
      <div class="sb-booking-clock" aria-label="Текущее время">
        <span class="sb-booking-clock-date">{{ clockDate }}</span>
        <span class="sb-booking-clock-time">{{ clockTime }}</span>
      </div>
    </div>

    <!-- Service info -->
    <div class="sb-booking-service sb-mb-4">
      <div class="sb-flex sb-items-center sb-justify-between">
        <div>
          <h3 class="sb-booking-service-name">{{ product.name }}</h3>
          <span class="sb-badge sb-badge-info">{{ durationMinutes }} мин</span>
        </div>
        <span class="sb-price">{{ formatPrice(product.price) }}</span>
      </div>
    </div>

    <div v-if="error" class="sb-alert-error sb-mb-4">{{ error }}</div>

    <!-- Progress stepper (reuses .sb-co-progress CSS) -->
    <div class="sb-co-progress sb-mb-4">
      <div :class="['sb-co-step', selectedSlot ? 'sb-co-step-done' : 'sb-co-step-active']">
        <span class="sb-co-step-circle">
          <svg v-if="selectedSlot" width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <template v-else>1</template>
        </span>
        <span class="sb-co-step-label">Дата и время</span>
      </div>
      <div :class="['sb-co-line', selectedSlot ? 'sb-co-line-done' : '']"></div>
      <div :class="['sb-co-step', selectedSlot ? 'sb-co-step-active' : '']">
        <span class="sb-co-step-circle">2</span>
        <span class="sb-co-step-label">Ваши данные</span>
      </div>
      <div class="sb-co-line"></div>
      <div class="sb-co-step">
        <span class="sb-co-step-circle">3</span>
        <span class="sb-co-step-label">Готово</span>
      </div>
    </div>

    <!-- Step 1: Date & Slot -->
    <div v-if="!selectedSlot">
      <!-- Mini-calendar (Calendly-style month grid) -->
      <div class="sb-cal sb-mb-4">
        <!-- Month navigation header -->
        <div class="sb-cal-header">
          <button class="sb-cal-nav" :disabled="!canPrevMonth" @click="prevMonth" aria-label="Предыдущий месяц">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
          </button>
          <span class="sb-cal-month-label">{{ calMonthLabel }}</span>
          <button class="sb-cal-nav" :disabled="!canNextMonth" @click="nextMonth" aria-label="Следующий месяц">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
        </div>

        <!-- Weekday headers -->
        <div class="sb-cal-weekdays">
          <span v-for="wd in WEEKDAYS" :key="wd">{{ wd }}</span>
        </div>

        <!-- Day cells grid -->
        <div class="sb-cal-grid">
          <button
            v-for="(cell, i) in calendarCells"
            :key="i"
            class="sb-cal-day"
            :class="{
              'sb-cal-day-empty':    !cell.day,
              'sb-cal-day-disabled': cell.disabled,
              'sb-cal-day-today':    cell.isToday,
              'sb-cal-day-active':   cell.value === selectedDate,
            }"
            :disabled="!cell.day || cell.disabled"
            @click="selectCalDay(cell)"
          >
            <span class="sb-cal-day-inner">{{ cell.day ?? '' }}</span>
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="sb-booking-slots-loading">
        <div v-for="i in 6" :key="i" class="sb-skeleton sb-skeleton-slot"></div>
      </div>

      <!-- Slots grid -->
      <div v-else-if="availableSlots.length > 0" class="sb-booking-slots">
        <button
          v-for="slot in availableSlots"
          :key="slot.time"
          class="sb-slot"
          @click="selectSlot(slot)"
        >
          {{ slot.time }}
        </button>
      </div>

      <!-- No slots -->
      <div v-else class="sb-empty" style="padding: 24px;">
        <p class="sb-empty-title">Нет свободных слотов</p>
        <p class="sb-empty-text">Выберите другую дату</p>
      </div>
    </div>

    <!-- Step 2: Customer form -->
    <div v-else>

      <!-- Confirmed time block -->
      <div class="sb-booking-confirmed sb-mb-3">
        <div class="sb-booking-confirmed-icon">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        </div>
        <div class="sb-booking-confirmed-info">
          <span class="sb-booking-confirmed-date">
            {{ new Date(selectedSlot.datetime).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) }},
            {{ selectedSlot.time }}
          </span>
          <span class="sb-booking-confirmed-sub">{{ durationMinutes }} мин · {{ product.name }}</span>
        </div>
        <button class="sb-booking-confirmed-change" @click="selectedSlot = null">Изменить</button>
      </div>

      <!-- Master selection — separate block (only if multiple masters) -->
      <div v-if="selectedSlot.masters.length > 1" class="sb-booking-master-card sb-mb-4">
        <label class="sb-label sb-mb-2">Выберите мастера</label>

        <!-- ≤2 masters: pills -->
        <div v-if="selectedSlot.masters.length <= 2" class="sb-master-list">
          <button
            v-for="m in selectedSlot.masters"
            :key="m.id"
            class="sb-master-chip"
            :class="{ 'sb-master-chip-active': selectedMasterId === m.id }"
            @click="selectedMasterId = m.id"
          >
            {{ m.name }}
          </button>
        </div>

        <!-- 3+ masters: custom dropdown -->
        <SbSelect
          v-else
          v-model="masterSelectValue"
          :options="masterOptions"
          placeholder="— Выберите мастера —"
        />
      </div>

      <form @submit.prevent="handleSubmit">
        <div class="sb-checkout-section">
          <h3 class="sb-checkout-section-title">Ваши данные</h3>

          <div class="sb-field">
            <label class="sb-label">Имя *</label>
            <div class="sb-field-wrap">
              <input
                v-model="form.name"
                type="text"
                class="sb-input"
                :class="{ 'sb-input-error': formErrors.name, 'sb-input-success': isFieldValid('name') }"
                placeholder="Ваше имя"
                @blur="touch('name')"
                @input="validateField('name')"
              />
              <svg v-if="isFieldValid('name')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="formErrors.name" class="sb-error-text">{{ formErrors.name }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Телефон *</label>
            <div class="sb-field-wrap">
              <input
                :value="form.phone"
                type="tel"
                class="sb-input"
                :class="{ 'sb-input-error': formErrors.phone, 'sb-input-success': isFieldValid('phone') }"
                placeholder="+7 (___) ___-__-__"
                @input="onPhoneInput"
                @blur="touch('phone')"
              />
              <svg v-if="isFieldValid('phone')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="formErrors.phone" class="sb-error-text">{{ formErrors.phone }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Email</label>
            <div class="sb-field-wrap">
              <input
                v-model="form.email"
                type="email"
                class="sb-input"
                :class="{ 'sb-input-error': formErrors.email, 'sb-input-success': isFieldValid('email') }"
                placeholder="email@example.com"
                @blur="touch('email')"
                @input="validateField('email')"
              />
              <svg v-if="isFieldValid('email')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="formErrors.email" class="sb-error-text">{{ formErrors.email }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Комментарий</label>
            <textarea
              v-model="form.notes"
              class="sb-input"
              rows="2"
              placeholder="Пожелания..."
            />
          </div>
        </div>

        <button
          type="submit"
          class="sb-btn sb-btn-primary sb-btn-block"
          :disabled="submitting"
        >
          {{ submitting ? 'Записываем...' : 'Записаться' }}
        </button>
      </form>
    </div>
  </div>
</template>
