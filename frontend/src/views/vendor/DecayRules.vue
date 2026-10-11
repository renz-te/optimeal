<template>
  <div class="p-8 max-w-5xl mx-auto space-y-8 font-sans antialiased text-slate-900">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-5">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
          <Clock class="text-emerald-600" :size="32" />
          <span>Surplus Decay Rules</span>
        </h1>
        <p class="text-slate-500 mt-1 font-medium">
          Configure cafeteria closing time, automated surplus discount tiers, and emergency clearance.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <button
          @click="fetchDecayConfig"
          :disabled="loading"
          class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 active:scale-95 transition-all shadow-sm disabled:opacity-50"
        >
          <RefreshCw :size="16" :class="{ 'animate-spin': loading }" />
          <span>Refresh</span>
        </button>

        <button
          @click="saveDecayConfig"
          :disabled="saving || loading"
          class="flex items-center gap-2 px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow-md hover:bg-emerald-700 active:scale-95 transition-all disabled:opacity-50"
        >
          <Save v-if="!saving" :size="16" />
          <RefreshCw v-else :size="16" class="animate-spin" />
          <span>{{ saving ? 'Saving...' : 'Save Changes' }}</span>
        </button>
      </div>
    </div>

    <!-- Feedback Banners -->
    <div v-if="saveMessage" class="p-4 rounded-xl text-sm font-semibold flex items-center justify-between bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-sm">
      <div class="flex items-center gap-2">
        <CheckCircle2 :size="18" class="text-emerald-600 shrink-0" />
        <span>{{ saveMessage }}</span>
      </div>
      <button @click="saveMessage = ''" class="text-emerald-600 hover:text-emerald-800">
        <X :size="16" />
      </button>
    </div>

    <div v-if="errorMessage" class="p-4 rounded-xl text-sm font-semibold flex items-center justify-between bg-rose-50 text-rose-800 border border-rose-200 shadow-sm">
      <div class="flex items-center gap-2">
        <AlertTriangle :size="18" class="text-rose-600 shrink-0" />
        <span>{{ errorMessage }}</span>
      </div>
      <button @click="errorMessage = ''" class="text-rose-600 hover:text-rose-800">
        <X :size="16" />
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && !configLoaded" class="py-16 text-center text-slate-400">
      <RefreshCw :size="36" class="animate-spin text-emerald-600 mx-auto mb-3" />
      <p class="font-medium text-base">Loading surplus decay configuration...</p>
    </div>

    <div v-else class="space-y-6">
      <!-- Card 1: Closing Time -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-2.5 mb-2">
          <Clock class="text-slate-700" :size="20" />
          <h2 class="text-lg font-bold text-slate-800">Canteen Closing Time</h2>
        </div>
        <p class="text-xs text-slate-500 mb-4">
          After this closing time, student menu browsing displays no purchasable items, new reservations are blocked, and unclaimed pending orders expire.
        </p>

        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
          <div class="relative w-44">
            <input
              type="time"
              v-model="closingTime"
              class="w-full px-4 py-2.5 border border-slate-300 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 outline-none font-bold text-slate-800 text-lg tabular-nums"
            >
          </div>
          <span class="text-sm font-medium text-slate-500">
            Timezone: <span class="font-bold text-slate-700">Asia/Manila (PHT)</span>
          </span>
        </div>
      </div>

      <!-- Card 2: Discount Schedule Tiers -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-2.5 mb-2">
          <TrendingDown class="text-slate-700" :size="20" />
          <h2 class="text-lg font-bold text-slate-800">Discount Schedule Tiers</h2>
        </div>
        <p class="text-xs text-slate-500 mb-6">
          Surplus decay discounts are STEPPED percentages applied to dish <code class="bg-slate-100 px-1 py-0.5 rounded text-rose-600 font-mono">base_price</code> (non-compounding). Prices never drop below each dish's designated <code class="bg-slate-100 px-1 py-0.5 rounded text-emerald-700 font-mono">price_floor</code>.
        </p>

        <div class="space-y-5">
          <!-- Tier 1 -->
          <div class="p-5 bg-slate-50 border border-slate-200 rounded-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
              <div class="flex items-center gap-2">
                <span class="px-2.5 py-0.5 bg-emerald-100 text-emerald-800 text-xs font-black rounded-md uppercase tracking-wider">Tier 1</span>
                <span class="font-bold text-slate-800">Early Surplus Clearance</span>
              </div>
              <div class="text-xs text-slate-500 mt-1 flex items-center gap-1.5">
                <Clock :size="13" />
                <span>Starts {{ tier1Minutes }} minutes before closing ({{ formatRelativeTime(tier1Minutes) }})</span>
              </div>
            </div>

            <div class="flex items-center gap-6">
              <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-500">Offset (min):</label>
                <input
                  type="number"
                  min="1"
                  max="480"
                  v-model.number="tier1Minutes"
                  class="w-20 px-3 py-1.5 border border-slate-300 rounded-lg text-sm font-bold text-slate-800 text-center"
                >
              </div>

              <div class="flex items-center gap-3">
                <input
                  type="range"
                  min="0"
                  max="100"
                  step="5"
                  v-model.number="tier1Discount"
                  class="w-36 accent-emerald-600 cursor-pointer"
                >
                <span class="font-black text-emerald-700 text-lg tabular-nums w-14 text-right">
                  {{ tier1Discount }}%
                </span>
              </div>
            </div>
          </div>

          <!-- Tier 2 -->
          <div class="p-5 bg-slate-50 border border-slate-200 rounded-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
              <div class="flex items-center gap-2">
                <span class="px-2.5 py-0.5 bg-amber-100 text-amber-800 text-xs font-black rounded-md uppercase tracking-wider">Tier 2</span>
                <span class="font-bold text-slate-800">Final Surplus Clearance</span>
              </div>
              <div class="text-xs text-slate-500 mt-1 flex items-center gap-1.5">
                <Clock :size="13" />
                <span>Starts {{ tier2Minutes }} minutes before closing ({{ formatRelativeTime(tier2Minutes) }})</span>
              </div>
            </div>

            <div class="flex items-center gap-6">
              <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-500">Offset (min):</label>
                <input
                  type="number"
                  min="1"
                  max="480"
                  v-model.number="tier2Minutes"
                  class="w-20 px-3 py-1.5 border border-slate-300 rounded-lg text-sm font-bold text-slate-800 text-center"
                >
              </div>

              <div class="flex items-center gap-3">
                <input
                  type="range"
                  min="0"
                  max="100"
                  step="5"
                  v-model.number="tier2Discount"
                  class="w-36 accent-amber-600 cursor-pointer"
                >
                <span class="font-black text-amber-700 text-lg tabular-nums w-14 text-right">
                  {{ tier2Discount }}%
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Card 3: Emergency 50% End-of-Day Clearance -->
      <div
        class="p-6 rounded-2xl border transition-all shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6"
        :class="emergencyActive ? 'bg-rose-100/70 border-rose-300 ring-2 ring-rose-400' : 'bg-rose-50 border-rose-200'"
      >
        <div class="flex-1">
          <div class="flex items-center gap-2 mb-1">
            <AlertOctagon class="text-rose-700" :size="22" />
            <h2 class="text-lg font-black text-rose-900">Emergency 50% End-of-Day Clearance</h2>
            <span
              v-if="emergencyActive"
              class="px-2.5 py-0.5 bg-rose-600 text-white font-black text-xs rounded-full uppercase tracking-wider animate-pulse"
            >
              ACTIVE NOW
            </span>
          </div>
          <p class="text-sm text-rose-700">
            Instantly slashes prices of all active online dishes by <span class="font-black">50%</span> across the student app to clear remaining food stock immediately. Price floor clamping is strictly maintained.
          </p>
          <div v-if="emergencyActivatedAt && emergencyActive" class="text-xs text-rose-800 font-semibold mt-2">
            Activated at: {{ new Date(emergencyActivatedAt).toLocaleTimeString() }}
          </div>
        </div>

        <div class="shrink-0 flex items-center gap-3">
          <button
            v-if="emergencyActive"
            @click="toggleEmergency(false)"
            :disabled="emergencyLoading"
            class="px-5 py-3 rounded-xl font-bold bg-white text-rose-700 border border-rose-300 hover:bg-rose-50 active:scale-95 transition-all text-sm shadow-sm disabled:opacity-50"
          >
            {{ emergencyLoading ? 'Deactivating...' : 'Deactivate Clearance' }}
          </button>

          <button
            v-else
            @click="toggleEmergency(true)"
            :disabled="emergencyLoading"
            class="px-6 py-3.5 bg-rose-600 text-white font-black rounded-xl shadow-md hover:bg-rose-700 active:scale-95 transition-all text-sm flex items-center gap-2 disabled:opacity-50"
          >
            <Flame :size="18" />
            <span>{{ emergencyLoading ? 'Activating...' : 'Trigger Instant 50% Clearance' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import {
  Clock, RefreshCw, Save, CheckCircle2, AlertTriangle, AlertOctagon,
  TrendingDown, Flame, X
} from 'lucide-vue-next'

const closingTime = ref('20:00')
const tier1Minutes = ref(120)
const tier1Discount = ref(25)
const tier2Minutes = ref(60)
const tier2Discount = ref(40)
const emergencyActive = ref(false)
const emergencyActivatedAt = ref(null)

const loading = ref(false)
const saving = ref(false)
const emergencyLoading = ref(false)
const configLoaded = ref(false)
const saveMessage = ref('')
const errorMessage = ref('')

const fetchDecayConfig = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await fetch('/api/vendor/decay')
    if (!res.ok) {
      const err = await res.json().catch(() => ({}))
      throw new Error(err.error || 'Failed to fetch surplus decay configuration')
    }
    const data = await res.json()
    closingTime.value = data.closing_time ? data.closing_time.substring(0, 5) : '20:00'
    tier1Minutes.value = Number(data.tier1_minutes_before_close) || 120
    tier1Discount.value = Number(data.tier1_discount_percent) || 25
    tier2Minutes.value = Number(data.tier2_minutes_before_close) || 60
    tier2Discount.value = Number(data.tier2_discount_percent) || 40
    emergencyActive.value = Boolean(data.emergency_clearance_active)
    emergencyActivatedAt.value = data.emergency_activated_at || null
    configLoaded.value = true
  } catch (err) {
    console.error('Error fetching decay config:', err)
    errorMessage.value = err.message
  } finally {
    loading.value = false
  }
}

const saveDecayConfig = async () => {
  if (tier1Minutes.value <= tier2Minutes.value) {
    errorMessage.value = 'Validation error: Tier 1 offset must be greater than Tier 2 offset (Tier 1 starts earlier).'
    return
  }

  saving.value = true
  saveMessage.value = ''
  errorMessage.value = ''

  try {
    const payload = {
      closing_time: closingTime.value,
      tier1_minutes_before_close: tier1Minutes.value,
      tier1_discount_percent: tier1Discount.value,
      tier2_minutes_before_close: tier2Minutes.value,
      tier2_discount_percent: tier2Discount.value
    }

    const res = await fetch('/api/vendor/decay', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })

    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      throw new Error(data.error || 'Failed to save surplus decay rules')
    }

    saveMessage.value = 'Surplus decay rules successfully updated and live!'
    setTimeout(() => {
      saveMessage.value = ''
    }, 4000)
  } catch (err) {
    console.error('Save decay config error:', err)
    errorMessage.value = err.message
  } finally {
    saving.value = false
  }
}

const toggleEmergency = async (activate) => {
  if (activate && !confirm('Are you sure you want to trigger 50% emergency clearance on all menu items?')) {
    return
  }

  emergencyLoading.value = true
  errorMessage.value = ''
  saveMessage.value = ''

  try {
    const res = await fetch('/api/vendor/decay/emergency', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ active: activate ? 1 : 0 })
    })

    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      throw new Error(data.error || 'Failed to update emergency clearance state')
    }

    emergencyActive.value = Boolean(data.emergency_clearance_active)
    if (activate) {
      emergencyActivatedAt.value = new Date().toISOString()
      saveMessage.value = 'Emergency 50% clearance is now ACTIVE across all customer menus!'
    } else {
      emergencyActivatedAt.value = null
      saveMessage.value = 'Emergency clearance has been deactivated.'
    }
  } catch (err) {
    console.error('Emergency clearance error:', err)
    errorMessage.value = err.message
  } finally {
    emergencyLoading.value = false
  }
}

const formatRelativeTime = (mins) => {
  const h = Math.floor(mins / 60)
  const m = mins % 60
  if (h > 0 && m > 0) return `${h}h ${m}m before close`
  if (h > 0) return `${h} hour${h > 1 ? 's' : ''} before close`
  return `${m} minutes before close`
}

onMounted(() => {
  fetchDecayConfig()
})
</script>
