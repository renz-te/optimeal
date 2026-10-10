<template>
  <div class="p-8">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Live Orders & Queue</h1>
        <p class="text-slate-500 mt-1 font-medium">Manage pending pick-ups and active orders.</p>
      </div>
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
        <div>
          <div class="flex items-center space-x-3 mb-2">
            <span class="text-xl font-black" :class="order.status === 'refunded' || order.status === 'expired' || order.status === 'cancelled' ? 'line-through text-slate-400' : 'text-slate-800'">#{{ order.token || order.claim_token }}</span>
            <span v-if="order.status === 'refunded' || order.status === 'cancelled'" class="px-2 py-1 text-[10px] font-bold uppercase rounded-full bg-rose-100 text-rose-700 border border-rose-200">Refunded / Cancelled</span>
            <span v-else-if="order.status === 'expired'" class="px-2 py-1 text-[10px] font-bold uppercase rounded-full bg-slate-100 text-slate-700 border border-slate-200">Expired - Kitchen Loss</span>
            <span v-else class="px-2 py-1 text-[10px] font-bold uppercase rounded-full" :class="order.fulfillment_type === 'take_out' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
              {{ order.fulfillment_type === 'take_out' ? 'Take-Out (Packed)' : 'Dine-In (Tray)' }}
            </span>
          </div>
          <div class="text-sm text-slate-600 font-medium">
            {{ order.items.length }} Items &bull; ₱{{ (order.total || parseFloat(order.total_amount || 0)).toFixed(2) }}
          </div>
        </div>
        <div class="flex items-center gap-3 w-full md:w-auto mt-2 md:mt-0">
          <button v-if="activeTab === 'Pending Pickup'" @click="refundOrder(order)" class="bg-rose-50 text-rose-600 border border-rose-200 px-4 py-2 rounded-lg font-bold text-sm hover:bg-rose-100 transition-colors w-full md:w-auto">
            Cancel & Refund
          </button>
          <button v-if="activeTab === 'Pending Pickup'" @click="markClaimed(order)" class="bg-emerald-600 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-emerald-700 transition-colors w-full md:w-auto shadow-sm">
            Mark Claimed
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useSandbox } from '../../stores/sandbox'

const { sandboxOrders, sandboxMenu, activePreviewMode } = useSandbox()
const activeTab = ref('Pending Pickup')
const localOrders = ref([])

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

const updateOrderStatus = async (orderId, newStatus) => {
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
    const res = await fetch('/api/vendor/orders/update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, status: newStatus })
    })
    if (res.ok) {
      await fetchOrders()
    }
  } catch(e) {
    console.error('Failed to update order status:', e)
  }
}

const markClaimed = (order) => {
  updateOrderStatus(order.id, 'claimed')
}

const refundOrder = (order) => {
  updateOrderStatus(order.id, 'cancelled')
}
</script>
