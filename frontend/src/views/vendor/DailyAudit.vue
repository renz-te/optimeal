<template>
  <div class="p-8 pb-24 font-sans antialiased text-slate-900 max-w-7xl mx-auto space-y-8">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <div class="flex items-center gap-3">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Daily Audit Summary</h1>
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
            Today: {{ auditData.date || todayStr }}
          </span>
        </div>
        <p class="text-slate-500 mt-1 font-medium text-sm">
          Track end-of-day revenue from claimed orders, fulfillment metrics, and unsold surplus food waste.
        </p>
      </div>

      <button 
        @click="fetchAudit" 
        :disabled="isLoading"
        class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 hover:border-slate-300 text-slate-700 font-bold rounded-xl shadow-sm hover:shadow transition-all text-sm disabled:opacity-50"
      >
        <span :class="{'animate-spin': isLoading}">🔄</span>
        <span>{{ isLoading ? 'Refreshing...' : 'Refresh Audit' }}</span>
      </button>
    </div>

    <!-- Error Banner -->
    <div v-if="errorMessage" class="p-4 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 text-sm flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span>⚠️</span>
        <span class="font-medium">{{ errorMessage }}</span>
      </div>
      <button @click="errorMessage = ''" class="text-rose-500 hover:text-rose-700 font-bold text-xs">Dismiss</button>
    </div>

    <!-- Top Summary Metrics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
      <!-- Total Revenue -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div class="flex justify-between items-start">
          <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Sales Revenue</p>
            <h2 class="text-3xl font-black text-emerald-600 mt-2">
              ₱{{ formatMoney(auditData.summary?.total_revenue || 0) }}
            </h2>
          </div>
          <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-lg">
            💰
          </div>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-semibold text-slate-500">
          <span>From claimed orders</span>
          <span class="text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded-md">
            {{ auditData.summary?.claimed_orders || 0 }} claimed
          </span>
        </div>
      </div>

      <!-- Orders Volume -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div class="flex justify-between items-start">
          <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Orders Placed</p>
            <h2 class="text-3xl font-black text-slate-900 mt-2">
              {{ auditData.summary?.total_orders || 0 }}
            </h2>
          </div>
          <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-lg">
            📦
          </div>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-semibold text-slate-500">
          <span>Active / Pending</span>
          <span class="text-indigo-700 font-bold bg-indigo-50 px-2 py-0.5 rounded-md">
            {{ auditData.summary?.active_orders || 0 }} in queue
          </span>
        </div>
      </div>

      <!-- Spoilage & Cancellations -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div class="flex justify-between items-start">
          <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Spoilage & Losses</p>
            <h2 class="text-3xl font-black text-rose-600 mt-2">
              {{ (auditData.summary?.cancelled_orders || 0) + (auditData.summary?.expired_orders || 0) }}
            </h2>
          </div>
          <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-lg">
            🚫
          </div>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-semibold text-slate-500">
          <span>Cancelled: {{ auditData.summary?.cancelled_orders || 0 }}</span>
          <span class="text-rose-700 font-bold bg-rose-50 px-2 py-0.5 rounded-md">
            Expired: {{ auditData.summary?.expired_orders || 0 }}
          </span>
        </div>
      </div>

      <!-- Unsold Inventory (Waste) -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div class="flex justify-between items-start">
          <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Unsold Live Stock</p>
            <h2 class="text-3xl font-black text-amber-600 mt-2">
              {{ auditData.summary?.total_unsold_units || 0 }} <span class="text-sm font-medium text-slate-400">units</span>
            </h2>
          </div>
          <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-lg">
            ⚖️
          </div>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-semibold text-slate-500">
          <span>Est. Waste Value</span>
          <span class="text-amber-800 font-bold bg-amber-50 px-2 py-0.5 rounded-md">
            ₱{{ formatMoney(auditData.summary?.total_waste_value || 0) }}
          </span>
        </div>
      </div>
    </div>

    <!-- Section 1: Unsold Inventory & Holding Status Table -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
      <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 bg-slate-50/50">
        <div>
          <h3 class="text-lg font-black text-slate-900">Unsold Food Inventory & Holding Status</h3>
          <p class="text-xs font-medium text-slate-500">Active dishes currently on the line and remaining online stock pools.</p>
        </div>
        <span class="text-xs font-bold text-slate-500 bg-white border border-slate-200 px-3 py-1 rounded-lg">
          {{ (auditData.unsold_inventory || []).length }} Active Lineup Viands
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-wider">
            <tr>
              <th class="px-6 py-4">Viand / Dish</th>
              <th class="px-6 py-4">Category</th>
              <th class="px-6 py-4 text-center">Remaining Stock</th>
              <th class="px-6 py-4 text-right">Base Price</th>
              <th class="px-6 py-4 text-right">Potential Loss</th>
              <th class="px-6 py-4">Cooked At</th>
              <th class="px-6 py-4">Sell-By Time</th>
              <th class="px-6 py-4 text-center">Holding Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!auditData.unsold_inventory || auditData.unsold_inventory.length === 0">
              <td colspan="8" class="text-center py-10 text-slate-400 font-medium">
                No active dishes in today's lineup.
              </td>
            </tr>
            <tr v-for="item in auditData.unsold_inventory" :key="item.id" class="hover:bg-slate-50/50 transition-colors">
              <td class="px-6 py-4 font-bold text-slate-900">
                {{ item.name }}
              </td>
              <td class="px-6 py-4">
                <span class="px-2.5 py-1 text-xs font-bold rounded-md bg-slate-100 text-slate-600">
                  {{ item.category }}
                </span>
              </td>
              <td class="px-6 py-4 text-center font-bold text-slate-800">
                <span :class="{'text-rose-600 font-black': item.remaining_stock === 0}">
                  {{ item.remaining_stock }}
                </span>
                <span v-if="item.online_pool_qty !== undefined && item.walkin_pool_qty !== undefined" class="block text-[11px] font-normal text-slate-400">
                  ({{ item.online_pool_qty }} online &bull; {{ item.walkin_pool_qty }} walk-in)
                </span>
              </td>
              <td class="px-6 py-4 text-right font-medium text-slate-600">
                ₱{{ formatMoney(item.base_price) }}
              </td>
              <td class="px-6 py-4 text-right font-bold text-amber-700">
                ₱{{ formatMoney(item.estimated_waste_value) }}
              </td>
              <td class="px-6 py-4 text-xs font-medium text-slate-500">
                {{ formatDateTime(item.cooked_at) }}
              </td>
              <td class="px-6 py-4 text-xs font-medium text-slate-500">
                {{ formatDateTime(item.sell_by) }}
              </td>
              <td class="px-6 py-4 text-center">
                <span 
                  v-if="item.is_expired == 1" 
                  class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-700 border border-rose-200"
                >
                  ⚠️ Expired / Spoiled
                </span>
                <span 
                  v-else-if="item.sell_by" 
                  class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200"
                >
                  ✓ Safe Holding
                </span>
                <span 
                  v-else 
                  class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600"
                >
                  Unspecified
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Section 2: Today's Orders Ledger Table -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
      <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 bg-slate-50/50">
        <div>
          <h3 class="text-lg font-black text-slate-900">Today's Order Ledger</h3>
          <p class="text-xs font-medium text-slate-500">Real-time log of customer reservations, claimed orders, and cancellations.</p>
        </div>
        <span class="text-xs font-bold text-slate-500 bg-white border border-slate-200 px-3 py-1 rounded-lg">
          {{ (auditData.orders || []).length }} Transactions Today
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-wider">
            <tr>
              <th class="px-6 py-4">Order Token</th>
              <th class="px-6 py-4">Customer</th>
              <th class="px-6 py-4">Fulfillment</th>
              <th class="px-6 py-4">Time Placed</th>
              <th class="px-6 py-4 text-right">Amount</th>
              <th class="px-6 py-4 text-center">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!auditData.orders || auditData.orders.length === 0">
              <td colspan="6" class="text-center py-10 text-slate-400 font-medium">
                No orders recorded yet today.
              </td>
            </tr>
            <tr v-for="order in auditData.orders" :key="order.id" class="hover:bg-slate-50/50 transition-colors">
              <td class="px-6 py-4 font-mono font-black text-slate-900">
                #{{ order.claim_token || order.id }}
              </td>
              <td class="px-6 py-4 font-semibold text-slate-800">
                {{ order.customer_name || 'Guest Student' }}
              </td>
              <td class="px-6 py-4 text-xs font-bold">
                <span :class="order.fulfillment_type === 'take_out' ? 'text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200' : 'text-slate-600 bg-slate-100 px-2 py-0.5 rounded'">
                  {{ order.fulfillment_type === 'take_out' ? '🥡 Take-Out' : '🍽️ Dine-In' }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs font-medium text-slate-500">
                {{ formatDateTime(order.reserved_at) }}
              </td>
              <td class="px-6 py-4 text-right font-black text-slate-900">
                ₱{{ formatMoney(order.total_amount) }}
              </td>
              <td class="px-6 py-4 text-center">
                <span 
                  v-if="order.status === 'claimed'" 
                  class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200"
                >
                  ✓ Claimed
                </span>
                <span 
                  v-else-if="order.status === 'paid'" 
                  class="px-2.5 py-1 text-xs font-bold rounded-full bg-blue-100 text-blue-800 border border-blue-200"
                >
                  Paid
                </span>
                <span 
                  v-else-if="order.status === 'reserved'" 
                  class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200"
                >
                  Reserved
                </span>
                <span 
                  v-else-if="order.status === 'cancelled'" 
                  class="px-2.5 py-1 text-xs font-bold rounded-full bg-slate-100 text-slate-600"
                >
                  Cancelled
                </span>
                <span 
                  v-else-if="order.status === 'expired'" 
                  class="px-2.5 py-1 text-xs font-bold rounded-full bg-rose-100 text-rose-700 border border-rose-200"
                >
                  Expired
                </span>
                <span v-else class="px-2.5 py-1 text-xs font-bold rounded-full bg-slate-100 text-slate-600">
                  {{ order.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const isLoading = ref(false)
const errorMessage = ref('')
const todayStr = new Date().toISOString().split('T')[0]

const auditData = ref({
  date: todayStr,
  summary: {
    total_revenue: 0,
    total_orders: 0,
    claimed_orders: 0,
    cancelled_orders: 0,
    expired_orders: 0,
    active_orders: 0,
    total_unsold_units: 0,
    total_waste_value: 0,
    expired_dishes_count: 0
  },
  unsold_inventory: [],
  orders: []
})

const formatMoney = (val) => {
  const num = Number(val) || 0
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatDateTime = (dateStr) => {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return dateStr
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const fetchAudit = async () => {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const res = await fetch('/api/vendor/audit')
    if (!res.ok) {
      const errJson = await res.json().catch(() => ({}))
      throw new Error(errJson.error || `Server responded with status ${res.status}`)
    }
    const data = await res.json()
    auditData.value = data
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load daily audit.'
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  fetchAudit()
})
</script>
