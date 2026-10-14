<template>
  <div class="p-8 max-w-4xl mx-auto font-sans antialiased text-slate-900 pb-28">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Vendor Operations Hub</h1>
        <p class="text-slate-500 mt-1 font-medium">Monitor customer reservation quotas, review AI lineup suggestions, and manage live stock.</p>
      </div>
    </div>

    <!-- AI Lineup Suggestions / Quota Progress Panel (Phase C) -->
    <div class="mb-8 bg-gradient-to-br from-indigo-950 via-slate-900 to-slate-950 rounded-2xl p-6 text-white shadow-xl border border-indigo-500/20 relative overflow-hidden">
      <!-- Ambient background glow -->
      <div class="absolute -top-24 -right-24 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 relative z-10">
        <div>
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-500/20 text-indigo-400 border border-indigo-400/30">
              <Sparkles :size="16" />
            </span>
            <h2 class="text-xl font-black tracking-tight text-white">AI Lineup Suggestions</h2>
            <span v-if="quotaSuggestions.length > 0" class="px-2 py-0.5 rounded-full text-xs font-black bg-indigo-500 text-white shadow-xs">
              {{ quotaSuggestions.length }} Demand {{ quotaSuggestions.length === 1 ? 'Alert' : 'Alerts' }}
            </span>
          </div>
          <p class="text-slate-400 text-xs mt-1">
            Unpublished catalog viands with active customer pre-orders. The AI suggests, the vendor is the gatekeeper.
          </p>
        </div>
        <div class="text-xs text-slate-400 font-medium bg-white/5 border border-white/10 px-3 py-1.5 rounded-xl shrink-0 self-start sm:self-auto">
          Default Quota Target: <strong class="text-indigo-300 font-bold">{{ defaultQuota }} portions</strong>
        </div>
      </div>

      <!-- Suggestions Content -->
      <div v-if="loadingSuggestions" class="py-8 text-center text-slate-400 text-xs flex items-center justify-center gap-2 relative z-10">
        <Loader2 :size="16" class="animate-spin text-indigo-400" />
        <span>Analyzing customer pre-orders and reservations...</span>
      </div>

      <div v-else-if="quotaSuggestions.length === 0" class="py-6 px-4 rounded-xl bg-white/5 border border-white/10 text-center text-slate-400 text-xs relative z-10">
        <Check :size="20" class="mx-auto mb-1.5 text-emerald-400" />
        <p class="font-bold text-slate-200">No Pending Quota Suggestions</p>
        <p class="mt-0.5 text-slate-400">All catalog viands with active pre-orders are currently active in today's lineup.</p>
      </div>

      <div v-else class="space-y-3 relative z-10">
        <div 
          v-for="dish in quotaSuggestions" 
          :key="dish.id" 
          class="bg-white/10 backdrop-blur-md border border-white/10 hover:border-indigo-400/40 rounded-xl p-4 transition-all flex flex-col md:flex-row md:items-center justify-between gap-4"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="font-bold text-base text-white truncate">{{ dish.name }}</h3>
              <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">
                {{ dish.category }}
              </span>
              <span 
                v-if="dish.reserved_qty >= defaultQuota" 
                class="text-[10px] font-black uppercase px-2 py-0.5 rounded bg-amber-400/20 text-amber-300 border border-amber-400/40 flex items-center gap-1"
              >
                <AlertCircle :size="11" /> Quota Met ({{ dish.reserved_qty }}/{{ defaultQuota }})
              </span>
              <span 
                v-else 
                class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-indigo-400/20 text-indigo-300 border border-indigo-400/40"
              >
                Quota Progress ({{ dish.reserved_qty }}/{{ defaultQuota }})
              </span>
            </div>

            <!-- Progress Bar of reserved_qty / default_quota -->
            <div class="mt-3 max-w-md">
              <div class="flex justify-between text-[11px] font-semibold mb-1">
                <span class="text-slate-300">
                  Reserved: <strong class="text-white">{{ dish.reserved_qty }}</strong> / {{ defaultQuota }} portions
                </span>
                <span class="text-indigo-300 font-bold">
                  {{ Math.min(100, Math.round((dish.reserved_qty / defaultQuota) * 100)) }}%
                </span>
              </div>
              <div class="w-full bg-slate-800/80 rounded-full h-2.5 overflow-hidden border border-white/10">
                <div 
                  class="h-full rounded-full transition-all duration-500"
                  :class="dish.reserved_qty >= defaultQuota ? 'bg-gradient-to-r from-amber-400 to-amber-500' : 'bg-gradient-to-r from-indigo-500 to-emerald-400'"
                  :style="{ width: `${Math.min(100, Math.round((dish.reserved_qty / defaultQuota) * 100))}%` }"
                ></div>
              </div>
              <div class="text-[10px] text-slate-400 mt-1.5 flex items-center gap-2">
                <span>Suggested Initial Batch: <strong class="text-emerald-300 font-semibold">{{ calculateSuggestedPBS(dish) }} portions</strong></span>
                <span>•</span>
                <span class="text-slate-400">{{ walkinBufferPct }}% buffer included</span>
              </div>
            </div>
          </div>

          <!-- Add to Lineup Action -->
          <div class="flex items-center gap-3 shrink-0">
            <button 
              @click="addToLineup(dish)" 
              :disabled="addingDishId === dish.id"
              type="button"
              class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-emerald-950/40 active:scale-95 transition-all flex items-center gap-2 disabled:opacity-50 cursor-pointer"
            >
              <Loader2 v-if="addingDishId === dish.id" :size="14" class="animate-spin" />
              <Plus v-else :size="14" />
              <span>Add to Lineup</span>
            </button>
          </div>
        </div>
      </div>
    </div>
      
    <!-- Today's Live Stock Section -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
      <div class="flex justify-between items-center mb-5 pb-3 border-b border-slate-100">
        <div>
          <h2 class="text-xl font-bold text-slate-900">Today's Live Stock</h2>
          <p class="text-xs text-slate-500 mt-0.5">Manage real-time inventory and pool allocations for your published lineup.</p>
        </div>
        <span class="text-xs font-bold bg-slate-100 text-slate-700 px-3 py-1 rounded-full border border-slate-200">
          {{ inventory.length }} Active {{ inventory.length === 1 ? 'Dish' : 'Dishes' }}
        </span>
      </div>

      <div v-if="inventory.length === 0" class="text-slate-500 text-sm text-center py-12 bg-slate-50 rounded-lg border border-dashed border-slate-200 font-medium">
        No active dishes published today. Head to Master Catalog to launch your menu.
      </div>

      <div v-for="item in inventory" :key="item.id" class="mb-5 bg-slate-50 p-5 rounded-xl border border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex-1">
          <h3 class="font-bold text-slate-800 text-lg">{{ item.item_name }}</h3>
          <div class="mt-2 flex items-center space-x-2">
             <label class="flex items-center cursor-pointer">
               <input type="checkbox" v-model="item.available_online" class="w-4 h-4 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 mr-2">
               <span class="text-xs font-bold" :class="item.available_online ? 'text-emerald-700' : 'text-slate-500'">{{ item.available_online ? 'Available Online' : 'Sold Out Online' }}</span>
             </label>
          </div>
        </div>
        <div class="flex items-end space-x-4">
          <label class="block">
            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Walk-in Qty</span>
            <input type="number" v-model.number="item.walkin_pool_qty" class="block w-20 border-slate-300 shadow-sm rounded-lg focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm px-3 py-2 border outline-none font-bold" />
          </label>
          <label class="block">
            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">App Qty</span>
            <input type="number" v-model.number="item.online_pool_qty" class="block w-20 border-slate-300 shadow-sm rounded-lg focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm px-3 py-2 border outline-none font-bold" />
          </label>
          <button @click="saveInventory(item)" class="bg-emerald-600 text-white font-bold px-5 py-2.5 rounded-lg shadow-sm hover:bg-emerald-700 active:scale-95 transition-all h-[42px] flex items-center justify-center">
            Update
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Feedback Notification -->
    <transition
      enter-active-class="transform ease-out duration-300 transition"
      enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
      enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div 
        v-if="toast.show" 
        class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-lg shadow-xl text-sm font-medium"
        :class="toast.type === 'error' ? 'bg-rose-900 text-rose-100 border border-rose-700' : 'bg-emerald-900 text-emerald-100 border border-emerald-700'"
      >
        <span class="w-2 h-2 rounded-full animate-pulse" :class="toast.type === 'error' ? 'bg-rose-400' : 'bg-emerald-400'"></span>
        {{ toast.message }}
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Sparkles, Plus, Loader2, Check, AlertCircle } from 'lucide-vue-next'

const inventory = ref([])
const rawCatalog = ref([])
const loading = ref(false)
const loadingSuggestions = ref(false)
const addingDishId = ref(null)

const defaultQuota = ref(5)
const walkinBufferPct = ref(15)

const toast = reactive({
  show: false,
  message: '',
  type: 'success'
})

const showToast = (message, type = 'success') => {
  toast.message = message
  toast.type = type
  toast.show = true
  setTimeout(() => {
    toast.show = false
  }, 3500)
}

// Filter to dishes that are NOT currently active in today's lineup, but have active reservations
const quotaSuggestions = computed(() => {
  return rawCatalog.value.filter(dish => Number(dish.is_active) === 0 && Number(dish.reserved_qty) > 0)
})

const calculateSuggestedPBS = (dish) => {
  const reserved = Number(dish.reserved_qty) || 0
  const bufferPct = Number(walkinBufferPct.value) || 15
  const bufferQty = Math.max(2, Math.ceil(reserved * (bufferPct / 100)))
  return reserved + bufferQty
}

const fetchSettings = async () => {
  try {
    const res = await fetch('/api/vendor/settings')
    if (res.ok) {
      const data = await res.json()
      if (data.cafeteria) {
        if (data.cafeteria.default_quota !== undefined && data.cafeteria.default_quota !== null) {
          defaultQuota.value = Number(data.cafeteria.default_quota) || 5
        }
        if (data.cafeteria.walkin_buffer_pct !== undefined && data.cafeteria.walkin_buffer_pct !== null) {
          walkinBufferPct.value = Number(data.cafeteria.walkin_buffer_pct) || 15
        }
      }
    }
  } catch (err) {
    console.warn('Could not load vendor settings, using defaults:', err)
  }
}

const fetchLiveStock = async () => {
  loading.value = true
  loadingSuggestions.value = true
  try {
    const res = await fetch('/api/vendor/menu')
    if (res.ok) {
      const data = await res.json()
      rawCatalog.value = data
      inventory.value = data
        .filter(item => item.is_active === 1)
        .map(item => ({
          id: item.id,
          menu_item_id: item.id,
          item_name: item.name,
          walkin_pool_qty: item.walkin_pool_qty ?? 0,
          online_pool_qty: item.online_pool_qty ?? 0,
          available_online: (item.online_pool_qty ?? 0) > 0 && !item.delisted_from_app
        }))
    }
  } catch (err) {
    console.error('Failed to load vendor inventory:', err)
  } finally {
    loading.value = false
    loadingSuggestions.value = false
  }
}

onMounted(async () => {
  await Promise.all([fetchSettings(), fetchLiveStock()])
})

// Phase C Task 2: "Add to Lineup" Action via Phase B4.9 Batch API
const addToLineup = async (dish) => {
  addingDishId.value = dish.id
  const suggestedPBS = calculateSuggestedPBS(dish)
  try {
    const res = await fetch('/api/vendor/batches', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        menu_item_id: dish.id,
        state: 'fresh',
        quantity: suggestedPBS
      })
    })

    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      showToast(data.error || 'Failed to add dish to lineup', 'error')
      return
    }

    showToast(`Added ${dish.name} to today's roster (${suggestedPBS} portions)`, 'success')
    // Refresh catalog so the dish transitions from suggestions to active live inventory
    await fetchLiveStock()
  } catch (err) {
    showToast('Network error while adding dish to lineup', 'error')
  } finally {
    addingDishId.value = null
  }
}

const saveInventory = async (item) => {
  try {
    const res = await fetch('/api/vendor/inventory', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        menu_item_id: item.menu_item_id,
        walkin_pool_qty: item.walkin_pool_qty,
        online_pool_qty: item.available_online ? item.online_pool_qty : 0
      })
    })
    if (res.ok) {
      await fetchLiveStock()
    }
  } catch (err) {
    console.error('Failed to update inventory:', err)
  }
}
</script>
