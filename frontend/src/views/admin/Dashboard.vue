<template>
  <div class="p-8 font-sans antialiased text-slate-900">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">System Overview & Settings</h1>
        <p class="text-slate-500 mt-1 font-medium">Live platform metrics and global cafeteria operating parameters.</p>
      </div>

      <button
        id="btn-refresh-dashboard"
        @click="loadDashboardData"
        :disabled="loadingMetrics || loadingSettings"
        class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-4 py-2.5 rounded-lg font-bold shadow-sm transition-colors flex items-center justify-center space-x-2 shrink-0 self-start md:self-auto"
      >
        <RefreshCw :size="16" :class="{ 'animate-spin': loadingMetrics || loadingSettings }" />
        <span>Refresh Data</span>
      </button>
    </div>

    <!-- Alert Messages -->
    <div v-if="pageError" class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm font-semibold flex items-center">
      <AlertCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ pageError }}</span>
    </div>

    <div v-if="successMessage" class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-sm font-semibold flex items-center">
      <CheckCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ successMessage }}</span>
    </div>

    <!-- Live Platform Metrics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
      <!-- Card 1: Users -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Registered Accounts</span>
          <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
            <Users :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.users?.total ?? 0 }}</span>
          </div>
          <div class="flex flex-wrap gap-1.5 mt-3">
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
              {{ metrics.users?.students ?? 0 }} Students
            </span>
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
              {{ metrics.users?.vendors ?? 0 }} Staff
            </span>
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
              {{ metrics.users?.admins ?? 0 }} Admins
            </span>
          </div>
        </div>
      </div>

      <!-- Card 2: Menu Dishes -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Menu Catalog</span>
          <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center">
            <Utensils :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.menu?.total ?? 0 }}</span>
          </div>
          <p class="text-xs text-slate-500 mt-2 font-medium">
            <span class="font-bold text-emerald-600">{{ metrics.menu?.active ?? 0 }}</span> active & available dishes
          </p>
        </div>
      </div>

      <!-- Card 3: Orders & Reservations -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Orders & Holds</span>
          <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
            <ShoppingBag :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.orders?.total ?? 0 }}</span>
          </div>
          <div class="flex flex-wrap gap-1.5 mt-3">
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
              {{ metrics.orders?.active ?? 0 }} Active
            </span>
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
              {{ metrics.orders?.claimed ?? 0 }} Claimed
            </span>
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
              {{ metrics.orders?.cancelled ?? 0 }} Cancelled
            </span>
          </div>
        </div>
      </div>

      <!-- Card 4: Taxonomy Health -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-4">
          <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Taxonomy & Health</span>
          <div class="w-10 h-10 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center">
            <ShieldCheck :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.taxonomy?.ingredients ?? 0 }}</span>
          </div>
          <div class="mt-2">
            <span
              v-if="metrics.taxonomy?.pending_submissions > 0"
              class="inline-flex items-center text-xs font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200"
            >
              {{ metrics.taxonomy?.pending_submissions }} Pending Review
            </span>
            <span v-else class="text-xs text-slate-400 font-medium">
              Queue clear ({{ metrics.taxonomy?.critical_allergens ?? 10 }} Master Allergens)
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Global Operating Configuration Form -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div class="flex items-center space-x-3">
          <div class="p-2 bg-emerald-100 text-emerald-800 rounded-xl">
            <Sliders :size="20" />
          </div>
          <div>
            <h2 class="text-lg font-bold text-slate-900">Global Cafeteria Operating Settings</h2>
            <p class="text-xs text-slate-500 mt-0.5">Control reservation expiration TTL, operating hours, and decay parameters.</p>
          </div>
        </div>

        <span v-if="settingsForm.updated_at" class="text-xs text-slate-400 font-mono">
          Last saved: {{ new Date(settingsForm.updated_at).toLocaleTimeString() }}
        </span>
      </div>

      <form @submit.prevent="handleSaveSettings" class="p-8 space-y-6">
        <div v-if="settingsError" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center">
          <AlertCircle :size="18" class="mr-2 shrink-0" />
          <span>{{ settingsError }}</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <!-- Reservation TTL -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label class="block text-sm font-bold text-slate-800 mb-1 flex items-center justify-between">
              <span>Reservation Expiry TTL</span>
              <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">Minutes</span>
            </label>
            <p class="text-xs text-slate-500 mb-3">
              How long uncollected reserved meals are locked before returning to available inventory.
            </p>
            <input
              id="setting-reservation-ttl"
              v-model.number="settingsForm.reservation_ttl"
              type="number"
              min="1"
              max="240"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white"
            />
          </div>

          <!-- Opening Time -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label class="block text-sm font-bold text-slate-800 mb-1 flex items-center justify-between">
              <span>Daily Opening Time</span>
              <Clock :size="16" class="text-slate-400" />
            </label>
            <p class="text-xs text-slate-500 mb-3">
              Time the cafeteria counter unlocks for incoming student reservations.
            </p>
            <input
              id="setting-opening-time"
              v-model="settingsForm.opening_time"
              type="text"
              required
              placeholder="07:00"
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white font-mono"
            />
          </div>

          <!-- Closing Time -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label class="block text-sm font-bold text-slate-800 mb-1 flex items-center justify-between">
              <span>Daily Closing Time</span>
              <Clock :size="16" class="text-slate-400" />
            </label>
            <p class="text-xs text-slate-500 mb-3">
              Time service closes and active reservations expire for the day.
            </p>
            <input
              id="setting-closing-time"
              v-model="settingsForm.closing_time"
              type="text"
              required
              placeholder="20:00"
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white font-mono"
            />
          </div>

          <!-- Max Discount Quantity -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label class="block text-sm font-bold text-slate-800 mb-1">
              Max Discount Quantity / Order
            </label>
            <p class="text-xs text-slate-500 mb-3">
              Maximum subsidized discount servings allowed per single transaction.
            </p>
            <input
              id="setting-max-discount-qty"
              v-model.number="settingsForm.max_discount_qty"
              type="number"
              min="1"
              max="50"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white"
            />
          </div>

          <!-- Default Decay Rate -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label class="block text-sm font-bold text-slate-800 mb-1 flex items-center justify-between">
              <span>Default Dynamic Decay Rate</span>
              <span class="text-xs font-semibold text-slate-600">% / hr</span>
            </label>
            <p class="text-xs text-slate-500 mb-3">
              Baseline hourly price decay rate applied to surplus food items approaching closing.
            </p>
            <input
              id="setting-decay-rate"
              v-model.number="settingsForm.default_decay_rate"
              type="number"
              step="0.5"
              min="0"
              max="100"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white"
            />
          </div>
        </div>

        <!-- Form Actions -->
        <div class="pt-6 border-t border-slate-100 flex items-center justify-end">
          <button
            id="save-settings-btn"
            type="submit"
            :disabled="savingSettings"
            class="px-6 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-sm transition-colors disabled:opacity-50 flex items-center space-x-2"
          >
            <span v-if="savingSettings" class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
            <Save v-else :size="16" />
            <span>{{ savingSettings ? 'Saving Settings...' : 'Save Configuration' }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import {
  Users,
  Utensils,
  ShoppingBag,
  ShieldCheck,
  RefreshCw,
  AlertCircle,
  CheckCircle,
  Clock,
  Sliders,
  Save
} from 'lucide-vue-next'

const loadingMetrics = ref(false)
const loadingSettings = ref(false)
const savingSettings = ref(false)
const pageError = ref('')
const settingsError = ref('')
const successMessage = ref('')

const metrics = reactive({
  users: { total: 0, students: 0, vendors: 0, admins: 0 },
  menu: { total: 0, active: 0 },
  orders: { total: 0, active: 0, claimed: 0, cancelled: 0 },
  taxonomy: { ingredients: 0, pending_submissions: 0, critical_allergens: 0 }
})

const settingsForm = reactive({
  id: 1,
  reservation_ttl: 30,
  opening_time: '07:00',
  closing_time: '20:00',
  max_discount_qty: 5,
  default_decay_rate: 10.00,
  updated_at: ''
})

const fetchMetrics = async () => {
  loadingMetrics.value = true
  try {
    const res = await fetch('/api/admin/metrics')
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to fetch dashboard metrics.')
    }
    Object.assign(metrics, data)
  } catch (err) {
    console.error('Error fetching metrics:', err)
    pageError.value = err.message
  } finally {
    loadingMetrics.value = false
  }
}

const fetchSettings = async () => {
  loadingSettings.value = true
  try {
    const res = await fetch('/api/admin/settings')
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to fetch settings.')
    }
    if (data.settings) {
      Object.assign(settingsForm, data.settings)
    }
  } catch (err) {
    console.error('Error fetching settings:', err)
    settingsError.value = err.message
  } finally {
    loadingSettings.value = false
  }
}

const loadDashboardData = async () => {
  pageError.value = ''
  settingsError.value = ''
  await Promise.all([fetchMetrics(), fetchSettings()])
}

onMounted(() => {
  loadDashboardData()
})

const handleSaveSettings = async () => {
  settingsError.value = ''
  savingSettings.value = true
  try {
    const res = await fetch('/api/admin/settings', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        reservation_ttl: settingsForm.reservation_ttl,
        opening_time: settingsForm.opening_time,
        closing_time: settingsForm.closing_time,
        max_discount_qty: settingsForm.max_discount_qty,
        default_decay_rate: settingsForm.default_decay_rate
      })
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to update settings.')
    }

    if (data.settings) {
      Object.assign(settingsForm, data.settings)
    }

    successMessage.value = 'Global cafeteria settings saved successfully!'
    setTimeout(() => {
      successMessage.value = ''
    }, 4000)
  } catch (err) {
    console.error('Save settings error:', err)
    settingsError.value = err.message
  } finally {
    savingSettings.value = false
  }
}
</script>
