<template>
  <div class="space-y-8 pb-12 font-sans antialiased text-slate-900">
    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-5">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
          <Clock class="text-emerald-600" :size="28" />
          <span>My Order History</span>
        </h1>
        <p class="text-sm text-slate-500 mt-1">
          Track live cafeteria reservations, view claim tokens, or review past receipts.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          id="refresh-orders-btn"
          @click="fetchOrders"
          :disabled="loading"
          class="flex items-center gap-2 px-4 py-2 text-sm font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 active:scale-95 transition-all shadow-sm disabled:opacity-50"
          title="Refresh orders list"
        >
          <RefreshCw :size="16" :class="{ 'animate-spin': loading }" />
          <span>Refresh</span>
        </button>
        <router-link
          to="/customer/menu"
          class="flex items-center gap-2 px-4 py-2 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 active:scale-95 rounded-xl transition-all shadow-sm"
        >
          <Utensils :size="16" />
          <span>Browse Menu</span>
        </router-link>
      </div>
    </div>

    <!-- Alert / Toast Messages -->
    <div v-if="toastMessage" class="p-4 rounded-xl text-sm font-medium flex items-center justify-between transition-all shadow-sm"
         :class="toastType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'">
      <div class="flex items-center gap-2">
        <CheckCircle2 v-if="toastType === 'success'" :size="18" class="text-emerald-600 shrink-0" />
        <AlertTriangle v-else :size="18" class="text-rose-600 shrink-0" />
        <span>{{ toastMessage }}</span>
      </div>
      <button @click="toastMessage = ''" class="text-slate-400 hover:text-slate-600">
        <X :size="16" />
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && orders.length === 0" class="flex flex-col items-center justify-center py-20 text-slate-400">
      <RefreshCw :size="36" class="animate-spin text-emerald-600 mb-3" />
      <p class="text-base font-medium">Loading your orders...</p>
    </div>

    <!-- Main Content when Loaded -->
    <div v-else class="space-y-10">
      <!-- Section 1: Active Orders -->
      <section aria-labelledby="active-orders-heading">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2.5">
            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
            <h2 id="active-orders-heading" class="text-xl font-bold text-slate-900 tracking-tight">
              Active Reservations
            </h2>
            <span class="bg-emerald-100 text-emerald-800 text-xs font-bold px-2.5 py-0.5 rounded-full">
              {{ activeOrders.length }}
            </span>
          </div>
          <p class="text-xs text-slate-500 hidden sm:block">Present token or QR code at pickup counter</p>
        </div>

        <div v-if="activeOrders.length === 0" class="bg-white border border-dashed border-slate-300 rounded-2xl p-8 sm:p-12 text-center text-slate-500">
          <div class="w-16 h-16 bg-slate-50 border border-slate-200 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-400">
            <ShoppingBag :size="28" />
          </div>
          <h3 class="text-base font-bold text-slate-800">No active reservations right now</h3>
          <p class="text-sm text-slate-500 max-w-md mx-auto mt-1 mb-5">
            You don't have any pending or ready orders waiting to be claimed. Reserve a fresh plate from today's cafeteria menu!
          </p>
          <router-link
            to="/customer/menu"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white font-bold text-sm rounded-xl hover:bg-emerald-700 shadow-sm transition-all"
          >
            <Utensils :size="16" />
            <span>Order Food Now</span>
          </router-link>
        </div>

        <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div
            v-for="order in activeOrders"
            :key="'active-' + order.id"
            class="bg-white border border-slate-200 rounded-2xl shadow-sm hover:shadow-md transition-all overflow-hidden flex flex-col justify-between"
          >
            <!-- Card Header -->
            <div class="p-5 sm:p-6 border-b border-slate-100">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Order #{{ order.id }}</span>
                    <span
                      class="px-2.5 py-0.5 text-xs font-bold rounded-full uppercase tracking-wider"
                      :class="order.status === 'paid' ? 'bg-indigo-100 text-indigo-800' : 'bg-emerald-100 text-emerald-800'"
                    >
                      {{ order.status === 'paid' ? 'Paid Online' : 'Reserved (Cash at Counter)' }}
                    </span>
                  </div>
                  <div class="text-xs text-slate-500 mt-1 flex items-center gap-1.5">
                    <Calendar :size="13" />
                    <span>{{ formatDate(order.reserved_at) }}</span>
                  </div>
                </div>

                <!-- Fulfillment Badge & Quick Convert -->
                <div class="text-right flex flex-col items-end gap-2">
                  <span
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold"
                    :class="order.fulfillment_type === 'take_out' ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-700 border border-slate-200'"
                  >
                    <Package v-if="order.fulfillment_type === 'take_out'" :size="14" />
                    <Utensils v-else :size="14" />
                    <span>{{ order.fulfillment_type === 'take_out' ? 'Take-Out (Packed)' : 'Dine-In (Tray)' }}</span>
                  </span>

                  <button
                    v-if="order.fulfillment_type !== 'take_out'"
                    id="convert-takeout-header-btn"
                    @click="convertToTakeOut(order)"
                    :disabled="convertingToken === order.claim_token"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-amber-500 hover:bg-amber-600 active:scale-95 transition-all shadow-sm disabled:opacity-50"
                  >
                    <Package :size="13" />
                    <span>{{ convertingToken === order.claim_token ? 'Converting...' : 'Switch to Take-Out' }}</span>
                  </button>
                </div>
              </div>

              <!-- Prominent Centered Typography Block for Claim Token -->
              <!-- // Vendor-side QR scanner deferred to Phase 2. -->
              <div class="mt-5 p-5 bg-gradient-to-br from-emerald-50/70 via-white to-slate-50 border border-emerald-100 rounded-2xl text-center shadow-xs">
                <div class="text-[11px] font-extrabold uppercase tracking-widest text-emerald-700 flex items-center justify-center gap-1.5 mb-1">
                  <KeyRound :size="15" />
                  <span>Pickup Claim Token</span>
                </div>
                <div class="text-5xl sm:text-6xl font-black text-slate-900 tracking-wider font-mono select-all my-1 py-1">
                  #{{ order.claim_token }}
                </div>
                <p class="text-xs text-slate-500 font-medium">
                  Present this 4-digit token at the cafeteria counter to claim your meal
                </p>
              </div>

              <!-- Visual Status Stepper (Reserved → Confirmed → Preparing → Ready → Claimed) -->
              <div class="mt-5 pt-4 border-t border-slate-100">
                <div class="flex items-center justify-between relative px-2 sm:px-4">
                  <!-- Stepper Track -->
                  <div class="absolute left-6 right-6 top-3.5 -translate-y-1/2 h-0.5 bg-slate-200 z-0">
                    <div 
                      class="h-full bg-emerald-600 transition-all duration-500"
                      :style="{ width: getStepperProgressWidth(order.status) }"
                    ></div>
                  </div>

                  <!-- Stepper Nodes -->
                  <div 
                    v-for="(step, sIdx) in orderStatusSteps" 
                    :key="step.key"
                    class="relative z-10 flex flex-col items-center group cursor-default"
                  >
                    <div 
                      class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all shadow-xs"
                      :class="getStepCircleClass(order.status, sIdx)"
                    >
                      <Check v-if="isStepCompleted(order.status, sIdx)" :size="14" class="stroke-[3]" />
                      <span v-else>{{ sIdx + 1 }}</span>
                    </div>
                    <span 
                      class="text-[10px] sm:text-[11px] mt-1.5 font-bold transition-colors text-center"
                      :class="getStepLabelClass(order.status, sIdx)"
                    >
                      {{ step.label }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Items List Breakdown -->
            <div class="p-5 sm:p-6 flex-1 bg-slate-50/50">
              <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Order Items</h4>
              <ul class="divide-y divide-slate-100 text-sm">
                <li v-for="item in (order.items || [])" :key="'item-' + item.id" class="py-2.5 flex items-center justify-between gap-3">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center flex-shrink-0">
                      <img 
                        v-if="item.image_url" 
                        :src="getImageUrl(item.image_url)" 
                        loading="lazy" 
                        class="w-full h-full object-cover" 
                        :alt="item.item_name || 'Dish'" 
                      />
                      <UtensilsCrossed v-else class="w-4 h-4 text-slate-400 stroke-[1.5]" />
                    </div>
                    <span class="w-6 h-6 rounded-lg bg-emerald-100 text-emerald-800 text-xs font-bold flex items-center justify-center shrink-0">
                      {{ item.quantity }}x
                    </span>
                    <div>
                      <div class="font-bold text-slate-800">{{ item.item_name }}</div>
                      <div v-if="item.portion_preference && item.portion_preference !== 'no_preference'" class="text-[11px] text-slate-500 font-medium capitalize">
                        Pref: {{ item.portion_preference.replace('_', ' ') }}
                      </div>
                    </div>
                  </div>
                  <div class="text-right font-semibold tabular-nums text-slate-700">
                    ₱{{ (Number(item.unit_price_at_order) * Number(item.quantity)).toFixed(2) }}
                  </div>
                </li>
              </ul>
            </div>

            <!-- Card Footer: Total & Actions -->
            <div class="p-5 sm:p-6 border-t border-slate-100 bg-white">
              <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-slate-500">Total Amount</span>
                <span class="text-xl font-black text-emerald-600 tabular-nums">
                  ₱{{ Number(order.total_amount).toFixed(2) }}
                </span>
              </div>

              <!-- Action Row: Take-Out & Cancel -->
              <div class="flex flex-col sm:flex-row items-center gap-3">
                <div v-if="order.fulfillment_type !== 'take_out'" class="flex-1 w-full">
                  <button
                    id="convert-takeout-btn"
                    @click="convertToTakeOut(order)"
                    :disabled="convertingToken === order.claim_token"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 active:scale-95 text-white font-bold text-sm rounded-xl transition-all shadow-sm disabled:opacity-60"
                  >
                    <Package :size="16" />
                    <span>{{ convertingToken === order.claim_token ? 'Converting...' : 'Convert to Take-Out' }}</span>
                  </button>
                </div>
                <div v-else class="flex-1 w-full text-center py-2.5 bg-amber-50 border border-amber-200 rounded-xl text-xs font-bold text-amber-800 flex items-center justify-center gap-1.5">
                  <Package :size="14" />
                  <span>Marked as Take-Out. Staff will pack your meal.</span>
                </div>

                <!-- Cancel Order Button (visible ONLY when reserved/confirmed & before cutoff) -->
                <button
                  v-if="canCancelOrder(order)"
                  id="cancel-order-btn"
                  @click="promptCancelOrder(order)"
                  :disabled="cancellingOrderId === order.id"
                  class="w-full sm:w-auto px-4 py-2.5 bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 hover:border-rose-300 font-bold text-sm rounded-xl transition-all active:scale-95 shadow-xs flex items-center justify-center gap-1.5 disabled:opacity-60 shrink-0"
                  title="Cancel this order and release inventory"
                >
                  <XCircle :size="16" />
                  <span>{{ cancellingOrderId === order.id ? 'Cancelling...' : 'Cancel Order' }}</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Section 2: Past Orders -->
      <section aria-labelledby="past-orders-heading" class="pt-4 border-t border-slate-200">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2.5">
            <Archive class="text-slate-500" :size="20" />
            <h2 id="past-orders-heading" class="text-xl font-bold text-slate-900 tracking-tight">
              Past Orders
            </h2>
            <span class="bg-slate-100 text-slate-600 text-xs font-bold px-2.5 py-0.5 rounded-full">
              {{ pastOrders.length }}
            </span>
          </div>
          <p class="text-xs text-slate-500">Historical records & receipts</p>
        </div>

        <div v-if="pastOrders.length === 0" class="bg-white border border-slate-200 rounded-2xl p-8 text-center text-slate-500">
          <Receipt :size="32" class="mx-auto mb-2 text-slate-400" />
          <p class="text-sm font-semibold text-slate-700">No past orders in your history</p>
          <p class="text-xs text-slate-400 mt-0.5">When orders are claimed or completed, their itemized receipts will show up here.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="order in pastOrders"
            :key="'past-' + order.id"
            class="bg-white border border-slate-200 rounded-2xl p-5 hover:border-slate-300 transition-all shadow-sm"
          >
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
              <div class="flex items-center gap-3">
                <span class="font-mono font-bold text-sm text-slate-800">Order #{{ order.id }}</span>
                <span
                  class="px-2.5 py-0.5 text-xs font-bold rounded-full uppercase tracking-wider"
                  :class="getPastStatusBadgeClass(order.status)"
                >
                  {{ order.status }}
                </span>
                <span
                  class="text-xs px-2 py-0.5 rounded font-semibold text-slate-600 bg-slate-100 border border-slate-200 flex items-center gap-1"
                >
                  <Package v-if="order.fulfillment_type === 'take_out'" :size="12" />
                  <Utensils v-else :size="12" />
                  <span>{{ order.fulfillment_type === 'take_out' ? 'Take-Out' : 'Dine-In' }}</span>
                </span>
              </div>
              <div class="text-xs text-slate-500 flex items-center gap-1.5">
                <Calendar :size="14" />
                <span>{{ formatDate(order.reserved_at) }}</span>
              </div>
            </div>

            <!-- Itemized Receipt Summary -->
            <div class="pt-3 flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div class="flex-1">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Receipt Items</div>
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="item in (order.items || [])"
                    :key="'past-item-' + item.id"
                    class="inline-flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-lg text-xs font-medium text-slate-700"
                  >
                    <span class="w-4 h-4 rounded bg-slate-200/80 overflow-hidden flex items-center justify-center shrink-0">
                      <img v-if="item.image_url" :src="getImageUrl(item.image_url)" loading="lazy" class="w-full h-full object-cover" :alt="item.item_name || 'Dish'" />
                      <UtensilsCrossed v-else class="w-2.5 h-2.5 text-slate-400" />
                    </span>
                    <span class="font-bold text-emerald-700">{{ item.quantity }}x</span>
                    <span>{{ item.item_name }}</span>
                    <span class="text-slate-400 font-mono">₱{{ (Number(item.unit_price_at_order) * Number(item.quantity)).toFixed(2) }}</span>
                  </span>
                  <span v-if="!order.items || order.items.length === 0" class="text-xs text-slate-400 italic">
                    Items recorded on physical slip
                  </span>
                </div>
              </div>

              <div class="flex items-center justify-between md:justify-end gap-6 shrink-0 pt-2 md:pt-0 border-t md:border-t-0 border-slate-50">
                <div class="text-right">
                  <div class="text-xs text-slate-400 uppercase font-semibold">Total Paid</div>
                  <div class="text-lg font-black text-slate-900 tabular-nums font-mono">
                    ₱{{ Number(order.total_amount).toFixed(2) }}
                  </div>
                </div>
                <div v-if="order.claim_token" class="text-right pl-4 border-l border-slate-200">
                  <div class="text-[10px] text-slate-400 uppercase font-semibold">Token</div>
                  <div class="text-xs font-mono font-bold text-slate-600">
                    #{{ order.claim_token }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import {
  Clock, RefreshCw, Utensils, ShoppingBag, Package, Calendar,
  KeyRound, Receipt, Archive, CheckCircle2, AlertTriangle, X, UtensilsCrossed,
  Check, XCircle
} from 'lucide-vue-next'

const getImageUrl = (url) => {
  if (!url) return ''
  if (url.startsWith('http') || url.startsWith('/')) return url
  return '/' + url
}

const orders = ref([])
const loading = ref(false)
const convertingToken = ref(null)
const cancellingOrderId = ref(null)
const toastMessage = ref('')
const toastType = ref('success')
let pollInterval = null

// Status Stepper Definitions (Reserved -> Confirmed -> Preparing -> Ready -> Claimed)
const orderStatusSteps = [
  { key: 'reserved', label: 'Reserved' },
  { key: 'confirmed', label: 'Confirmed' },
  { key: 'preparing', label: 'Preparing' },
  { key: 'ready', label: 'Ready' },
  { key: 'claimed', label: 'Claimed' }
]

const getStatusStepIndex = (status) => {
  const s = (status || '').toLowerCase()
  if (s === 'reserved' || s === 'pending') return 0
  if (s === 'confirmed' || s === 'paid') return 1
  if (s === 'preparing') return 2
  if (s === 'ready') return 3
  if (s === 'claimed' || s === 'completed') return 4
  return 0
}

const isStepCompleted = (status, sIdx) => {
  return getStatusStepIndex(status) > sIdx
}

const getStepperProgressWidth = (status) => {
  const currentIdx = getStatusStepIndex(status)
  return `${(currentIdx / (orderStatusSteps.length - 1)) * 100}%`
}

const getStepCircleClass = (status, sIdx) => {
  const currentIdx = getStatusStepIndex(status)
  if (currentIdx > sIdx) {
    return 'bg-emerald-600 text-white border-2 border-emerald-600'
  }
  if (currentIdx === sIdx) {
    return 'bg-emerald-600 text-white ring-4 ring-emerald-100 border-2 border-emerald-600 animate-pulse'
  }
  return 'bg-white text-slate-400 border-2 border-slate-200'
}

const getStepLabelClass = (status, sIdx) => {
  const currentIdx = getStatusStepIndex(status)
  if (currentIdx === sIdx) return 'text-emerald-700 font-extrabold'
  if (currentIdx > sIdx) return 'text-slate-800'
  return 'text-slate-400'
}

// Cancellation condition: status is 'reserved' or 'confirmed' (or 'paid'/'pending') and now < cutoff
const canCancelOrder = (order) => {
  if (!order) return false
  const s = (order.status || '').toLowerCase()
  const isCancellable = ['reserved', 'confirmed', 'pending', 'paid'].includes(s)
  if (!isCancellable) return false

  if (order.ttl_expires_at) {
    const cutoff = new Date(order.ttl_expires_at).getTime()
    if (Date.now() >= cutoff) return false
  }
  return true
}

const promptCancelOrder = async (order) => {
  if (!confirm(`Are you sure you want to cancel Order #${order.id}? This will release the reserved meals back to cafeteria stock.`)) {
    return
  }
  cancellingOrderId.value = order.id
  try {
    const res = await fetch(`/api/reservations/${order.id}`, {
      method: 'DELETE'
    })
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to cancel reservation')
    }
    showToast(data.message || `Order #${order.id} cancelled successfully.`, 'success')
    await fetchOrders()
  } catch (err) {
    console.error('Cancel order error:', err)
    showToast(err.message || 'Failed to cancel reservation', 'error')
  } finally {
    cancellingOrderId.value = null
  }
}

// Split into active vs past orders:
// Active: reserved, pending, confirmed, paid, preparing, ready
// Past: claimed, completed, cancelled, expired
const activeOrders = computed(() => {
  return orders.value.filter(o => ['reserved', 'pending', 'confirmed', 'paid', 'preparing', 'ready'].includes(o.status))
})

const pastOrders = computed(() => {
  return orders.value.filter(o => ['claimed', 'completed', 'cancelled', 'expired'].includes(o.status))
})

const fetchOrders = async () => {
  loading.value = true
  try {
    const res = await fetch('/api/customer/orders')
    if (res.status === 401) {
      window.location.href = '/customer/login'
      return
    }
    if (!res.ok) {
      throw new Error('Failed to fetch order history')
    }
    const data = await res.json()
    orders.value = Array.isArray(data) ? data : []
  } catch (err) {
    console.error('Error fetching orders:', err)
    showToast(err.message || 'Could not load orders', 'error')
  } finally {
    loading.value = false
  }
}

const convertToTakeOut = async (order) => {
  convertingToken.value = order.claim_token
  try {
    const res = await fetch('/api/reservations/update-fulfillment', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: order.claim_token,
        fulfillment_type: 'take_out'
      })
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to convert order to take-out')
    }

    // Update local reactive state
    order.fulfillment_type = 'take_out'
    showToast(`Order #${order.id} successfully converted to Take-Out! Staff will pack it for you.`, 'success')
  } catch (err) {
    console.error('Conversion error:', err)
    showToast(err.message || 'Failed to update fulfillment', 'error')
  } finally {
    convertingToken.value = null
  }
}

const showToast = (message, type = 'success') => {
  toastMessage.value = message
  toastType.value = type
  setTimeout(() => {
    if (toastMessage.value === message) {
      toastMessage.value = ''
    }
  }, 4000)
}

const formatDate = (dateStr) => {
  if (!dateStr) return 'N/A'
  try {
    const date = new Date(dateStr)
    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    })
  } catch (e) {
    return dateStr
  }
}

const getPastStatusBadgeClass = (status) => {
  switch (status) {
    case 'claimed':
      return 'bg-emerald-100 text-emerald-800'
    case 'expired':
      return 'bg-amber-100 text-amber-800'
    case 'cancelled':
      return 'bg-rose-100 text-rose-800'
    default:
      return 'bg-slate-100 text-slate-700'
  }
}

onMounted(() => {
  fetchOrders()
  pollInterval = setInterval(fetchOrders, 20000)
})

onUnmounted(() => {
  if (pollInterval) {
    clearInterval(pollInterval)
    pollInterval = null
  }
})
</script>
