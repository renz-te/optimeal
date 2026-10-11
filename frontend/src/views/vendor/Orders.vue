<template>
  <div class="p-8">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Live Orders & Queue</h1>
        <p class="text-slate-500 mt-1 font-medium">Manage pending pick-ups and active orders.</p>
      </div>
    </div>

    <!-- Error message banner if action fails -->
    <div v-if="actionError" class="mb-6 p-4 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 text-sm flex items-center justify-between">
      <div class="flex items-center gap-2">
        <AlertTriangle class="w-4 h-4 text-rose-600 flex-shrink-0" />
        <span class="font-medium">{{ actionError }}</span>
      </div>
      <button @click="actionError = ''" class="text-rose-500 hover:text-rose-700 font-bold text-xs">Dismiss</button>
    </div>
    
    <div class="flex space-x-4 mb-6 border-b border-slate-200 overflow-x-auto hide-scrollbar">
      <button v-for="tab in ['Pending Pickup', 'Claimed', 'Refunded', 'Expired']" :key="tab" @click="activeTab = tab" class="pb-3 px-4 font-bold text-sm transition-colors whitespace-nowrap" :class="activeTab === tab ? 'border-b-2 border-emerald-500 text-emerald-700' : 'text-slate-500 hover:text-slate-800'">
        {{ tab }}
      </button>
    </div>

    <div class="space-y-4">
      <div v-if="filteredOrders.length === 0" class="text-center py-12 text-slate-400 font-medium">
        No orders in this view.
      </div>
      <div v-for="order in filteredOrders" :key="order.id" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4" :class="{'opacity-75 bg-slate-50': order.status === 'refunded' || order.status === 'expired'}">
        <div class="flex-1">
          <div class="flex flex-wrap items-center gap-2 mb-2">
            <span class="text-xl font-black" :class="order.status === 'refunded' || order.status === 'expired' || order.status === 'cancelled' ? 'line-through text-slate-400' : 'text-slate-800'">#{{ order.token || order.claim_token }}</span>
            <span v-if="order.customer_name" class="text-xs font-semibold text-slate-500">({{ order.customer_name }})</span>
            
            <span v-if="order.status === 'refunded' || order.status === 'cancelled'" class="px-2 py-1 text-[10px] font-bold uppercase rounded-full bg-rose-100 text-rose-700 border border-rose-200">Refunded / Cancelled</span>
            <span v-else-if="order.status === 'expired'" class="px-2 py-1 text-[10px] font-bold uppercase rounded-full bg-slate-100 text-slate-700 border border-slate-200">Expired - Kitchen Loss</span>
            <span v-else class="px-2 py-1 text-[10px] font-bold uppercase rounded-full" :class="order.fulfillment_type === 'take_out' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
              {{ order.fulfillment_type === 'take_out' ? 'Take-Out (Packed)' : 'Dine-In (Tray)' }}
            </span>

            <!-- Allergen Override Badge (Strict Lucide AlertTriangle without emojis) -->
            <span v-if="order.allergen_override" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-bold rounded-full bg-amber-50 text-amber-800 border border-amber-300">
              <AlertTriangle class="w-3.5 h-3.5 text-amber-600 flex-shrink-0" />
              <span>Allergen Override: {{ formatConflicts(order.allergen_conflicts) }}</span>
            </span>
          </div>

          <div class="text-sm text-slate-600 font-medium mb-3">
            {{ calculateTotalItems(order.items) }} Items &bull; ₱{{ (order.total || parseFloat(order.total_amount || 0)).toFixed(2) }}
          </div>

          <div v-if="order.items && order.items.length > 0" class="space-y-1.5 pt-2 border-t border-slate-100">
            <div v-for="(item, idx) in order.items" :key="idx" class="text-sm text-slate-800 font-bold flex items-center gap-2">
              <span class="text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded text-xs font-black">{{ item.quantity || 1 }}x</span>
              <span>{{ item.item_name || item.name || (item.item && item.item.name) }}</span>
              <span v-if="item.portion_preference && item.portion_preference !== 'no_preference'" class="text-xs font-medium text-slate-500 italic bg-slate-100 px-2 py-0.5 rounded-full capitalize">
                {{ formatPreference(item.portion_preference) }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3 w-full md:w-auto mt-2 md:mt-0 flex-shrink-0">
          <button v-if="activeTab === 'Pending Pickup'" @click="refundOrder(order)" class="bg-rose-50 text-rose-600 border border-rose-200 px-4 py-2 rounded-lg font-bold text-sm hover:bg-rose-100 transition-colors w-full md:w-auto">
            Cancel & Refund
          </button>
          <button v-if="activeTab === 'Pending Pickup'" @click="markClaimed(order)" class="bg-emerald-600 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-emerald-700 transition-colors w-full md:w-auto shadow-sm flex items-center justify-center gap-2">
            <AlertTriangle v-if="order.allergen_override" class="w-4 h-4 text-amber-300" />
            <span>Mark Claimed</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Staff Allergen Confirmation Modal -->
    <div v-if="showAllergenModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center flex-shrink-0">
            <AlertTriangle class="w-5 h-5 text-amber-600" />
          </div>
          <div class="flex-1">
            <h3 class="text-lg font-bold text-slate-900">Allergen Safety Confirmation</h3>
            <p class="text-xs text-slate-500 mt-0.5">Order #{{ pendingAllergenOrder?.claim_token || pendingAllergenOrder?.token }} ({{ pendingAllergenOrder?.customer_name }})</p>
          </div>
        </div>

        <div class="mt-4 p-3.5 bg-amber-50/70 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-2">
          <p class="font-bold">
            Customer overridden allergen conflicts:
            <span class="text-amber-800 underline font-black ml-1">
              {{ formatConflicts(pendingAllergenOrder?.allergen_conflicts) }}
            </span>
          </p>
          <p class="text-amber-800 leading-relaxed">
            This customer placed the reservation after overriding the safety alert. Please inspect the dish and confirm preparation details with kitchen staff prior to handing over this order.
          </p>
        </div>

        <div class="mt-5 flex items-start gap-3">
          <input 
            type="checkbox" 
            id="staffAckCheck" 
            v-model="staffAckChecked"
            class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
          />
          <label for="staffAckCheck" class="text-xs font-semibold text-slate-700 cursor-pointer select-none">
            I confirm staff has reviewed the allergen conflict and verified meal preparation.
          </label>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
          <button 
            type="button" 
            @click="closeAllergenModal" 
            class="px-4 py-2 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl text-sm font-bold transition-colors"
          >
            Cancel
          </button>
          <button 
            type="button" 
            :disabled="!staffAckChecked || isSubmitting"
            @click="confirmClaimWithOverride" 
            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:hover:bg-emerald-600 text-white rounded-xl text-sm font-bold shadow-sm transition-all"
          >
            {{ isSubmitting ? 'Processing...' : 'Confirm & Release' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { AlertTriangle } from 'lucide-vue-next'
import { useSandbox } from '../../stores/sandbox'

const { sandboxOrders, sandboxMenu, activePreviewMode } = useSandbox()
const activeTab = ref('Pending Pickup')
const localOrders = ref([])
const actionError = ref('')

// Allergen Modal State
const showAllergenModal = ref(false)
const pendingAllergenOrder = ref(null)
const staffAckChecked = ref(false)
const isSubmitting = ref(false)

const isAdmin = computed(() => {
  try {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}')
    return user.role === 'admin'
  } catch (e) {
    return false
  }
})

const isSandbox = computed(() => {
  return isAdmin.value && activePreviewMode.value === 'vendor'
})

const orders = computed(() => {
  return isSandbox.value ? sandboxOrders.value : localOrders.value
})

const filteredOrders = computed(() => {
  if (activeTab.value === 'Pending Pickup') return orders.value.filter(o => !o.claimed && o.status !== 'refunded' && o.status !== 'expired' && o.status !== 'cancelled' && o.status !== 'completed' && o.status !== 'claimed')
  if (activeTab.value === 'Claimed') return orders.value.filter(o => o.claimed || o.status === 'claimed')
  if (activeTab.value === 'Refunded') return orders.value.filter(o => o.status === 'refunded' || o.status === 'cancelled')
  if (activeTab.value === 'Expired') return orders.value.filter(o => o.status === 'expired')
  return []
})

const formatConflicts = (conflicts) => {
  if (!conflicts) return 'Unspecified'
  if (Array.isArray(conflicts)) return conflicts.join(', ')
  try {
    const parsed = JSON.parse(conflicts)
    if (Array.isArray(parsed)) return parsed.join(', ')
    return String(parsed)
  } catch (e) {
    return String(conflicts)
  }
}

const fetchOrders = async () => {
  if (isSandbox.value) return;
  try {
    const res = await fetch('/api/vendor/orders')
    if (res.ok) {
      localOrders.value = await res.json()
    }
  } catch(e) {
    console.error('Failed to fetch orders:', e)
  }
}

onMounted(() => {
  fetchOrders()
})

const updateOrderStatus = async (orderId, newStatus, confirmAllergen = false) => {
  actionError.value = ''
  if (isSandbox.value) {
    const order = sandboxOrders.value.find(o => o.id === orderId)
    if (order) {
      if (newStatus === 'claimed') order.claimed = true
      if (newStatus === 'cancelled') {
        order.status = 'refunded'
        order.items.forEach(c => {
          const menuItem = sandboxMenu.value.find(m => m.id === c.item.id)
          if (menuItem) menuItem.stock += c.quantity
        })
      }
    }
    return;
  }
  
  try {
    const payload = { 
      order_id: orderId, 
      status: newStatus 
    }
    if (confirmAllergen) {
      payload.confirm_allergen_override = true
    }

    const res = await fetch('/api/vendor/orders/update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })

    const data = await res.json()
    if (!res.ok) {
      actionError.value = data.error || 'Failed to update order status'
      throw new Error(actionError.value)
    }

    await fetchOrders()
  } catch(e) {
    console.error('Failed to update order status:', e)
    throw e
  }
}

const markClaimed = (order) => {
  // If order has active allergen override, require explicit staff confirmation
  if (order.allergen_override) {
    pendingAllergenOrder.value = order
    staffAckChecked.value = false
    showAllergenModal.value = true
    return
  }

  updateOrderStatus(order.id, 'claimed', false)
}

const confirmClaimWithOverride = async () => {
  if (!pendingAllergenOrder.value || !staffAckChecked.value) return
  isSubmitting.value = true
  try {
    await updateOrderStatus(pendingAllergenOrder.value.id, 'claimed', true)
    closeAllergenModal()
  } catch (e) {
    // Error already set in actionError
  } finally {
    isSubmitting.value = false
  }
}

const closeAllergenModal = () => {
  showAllergenModal.value = false
  pendingAllergenOrder.value = null
  staffAckChecked.value = false
}

const refundOrder = (order) => {
  updateOrderStatus(order.id, 'cancelled', false)
}

const calculateTotalItems = (items) => {
  if (!items || !Array.isArray(items)) return 0
  return items.reduce((total, item) => total + (Number(item.quantity) || 1), 0)
}

const formatPreference = (pref) => {
  if (!pref || pref === 'no_preference') return ''
  return pref.replace(/_/g, ' ')
}
</script>
