<template>
  <div v-if="isAdmin" class="fixed top-0 inset-x-0 z-[100] bg-slate-900 text-white px-4 py-2 flex items-center justify-between shadow-md border-b border-slate-700 h-14">
    <!-- Left: Status Indicator -->
    <div class="flex items-center space-x-3">
      <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-500/20 text-indigo-400 border border-indigo-500/30">
        <MonitorSmartphone :size="16" />
      </div>
      <div>
        <h2 class="text-xs font-black uppercase tracking-widest text-indigo-400">OptiMeal Sandbox</h2>
        <p class="text-[10px] text-slate-400 font-medium leading-tight">Isolated Preview State</p>
      </div>
    </div>

    <!-- Center: Mode Switcher -->
    <div class="flex items-center bg-slate-800 rounded-lg p-1 border border-slate-700">
      <button 
        @click="setMode('admin', '/admin/dashboard')"
        class="px-4 py-1.5 rounded-md text-xs font-bold transition-all flex items-center space-x-2"
        :class="activePreviewMode === 'admin' ? 'bg-indigo-500 text-white shadow-sm' : 'text-slate-400 hover:text-white hover:bg-slate-700'"
      >
        <LayoutDashboard :size="14" />
        <span>Admin Console</span>
      </button>
      <button 
        @click="setMode('vendor', '/vendor/dashboard')"
        class="px-4 py-1.5 rounded-md text-xs font-bold transition-all flex items-center space-x-2"
        :class="activePreviewMode === 'vendor' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-400 hover:text-white hover:bg-slate-700'"
      >
        <Store :size="14" />
        <span>Vendor Mode</span>
      </button>
      <button 
        @click="setMode('customer', '/customer/menu')"
        class="px-4 py-1.5 rounded-md text-xs font-bold transition-all flex items-center space-x-2"
        :class="activePreviewMode === 'customer' ? 'bg-emerald-500 text-white shadow-sm' : 'text-slate-400 hover:text-white hover:bg-slate-700'"
      >
        <User :size="14" />
        <span>Customer Mode</span>
      </button>
    </div>

    <!-- Right: Customer Preferences & Controls -->
    <div class="flex items-center space-x-3">
      <template v-if="activePreviewMode === 'customer'">
        <div class="relative flex items-center border border-slate-700 rounded-lg bg-slate-800 px-2 py-1">
          <Filter :size="12" class="text-slate-400 mr-2" />
          <select v-model="activePreset" @change="applyPreset" class="bg-transparent text-xs font-bold text-slate-200 outline-none w-48 cursor-pointer">
            <option value="default">Default (No Allergens)</option>
            <option value="juan">Peanuts + No Pork + Spicy</option>
            <option value="seafood">Shellfish & Seafood</option>
            <option value="veg">Strict Vegetarian</option>
          </select>
        </div>
        <button @click="router.push('/customer/settings')" class="p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 rounded-md transition-colors" title="Custom Settings">
          <Settings :size="16" />
        </button>
      </template>

      <div class="w-px h-6 bg-slate-700 mx-1"></div>

      <button @click="handleReset" class="p-1.5 text-rose-400 hover:text-white hover:bg-rose-500/20 rounded-md transition-colors flex items-center space-x-1" title="Reset Sandbox State">
        <RotateCcw :size="16" />
        <span class="text-[10px] font-bold hidden xl:inline-block">Reset</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { MonitorSmartphone, LayoutDashboard, Store, User, RotateCcw, Settings, Filter } from 'lucide-vue-next'
import { useSandbox } from '../stores/sandbox'

const router = useRouter()
const route = useRoute()
const { activePreviewMode, simulatedDietaryFlags, resetSandboxState } = useSandbox()

const activePreset = ref('default')

const isAdmin = computed(() => {
  try {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}')
    return user.role === 'admin'
  } catch (e) {
    return false
  }
})

// Auto-sync mode based on current route if refreshed
onMounted(() => {
  if (route.path.includes('/admin')) activePreviewMode.value = 'admin'
  else if (route.path.includes('/vendor')) activePreviewMode.value = 'vendor'
  else if (route.path.includes('/customer')) activePreviewMode.value = 'customer'
  
  matchPresetFromFlags()
})

const setMode = (mode, path) => {
  activePreviewMode.value = mode
  // Force sync immediately so Vue Router navigation guards allow the transition
  sessionStorage.setItem('sandbox_preview_mode', mode)
  router.push(path)
}

const matchPresetFromFlags = () => {
  const f = simulatedDietaryFlags.value
  const has = (arr, val) => arr && arr.includes(val)
  
  if (has(f.allergens, 'Peanuts') && has(f.dietary, 'No Pork') && has(f.sensitivities, 'Spicy / Capsaicin')) {
    activePreset.value = 'juan'
  } else if (has(f.allergens, 'Shellfish / Crustaceans')) {
    activePreset.value = 'seafood'
  } else if (has(f.dietary, 'Vegetarian / Plant-Based')) {
    activePreset.value = 'veg'
  } else if ((!f.allergens || f.allergens.length === 0) && (!f.dietary || f.dietary.length === 0) && (!f.sensitivities || f.sensitivities.length === 0)) {
    activePreset.value = 'default'
  } else {
    activePreset.value = 'custom' // Not explicitly in options, but keeps select valid
  }
}

const applyPreset = () => {
  const p = activePreset.value
  let flags = { allergens: [], sensitivities: [], dietary: [] }

  if (p === 'juan') {
    flags = { allergens: ['Peanuts'], dietary: ['No Pork'], sensitivities: ['Spicy / Capsaicin'] }
  } else if (p === 'seafood') {
    flags = { allergens: ['Shellfish / Crustaceans', 'Shrimp Paste (Bagoong)'], dietary: [], sensitivities: [] }
  } else if (p === 'veg') {
    flags = { allergens: [], dietary: ['Vegetarian / Plant-Based'], sensitivities: [] }
  }
  
  simulatedDietaryFlags.value = flags
}

const handleReset = () => {
  if (confirm('Reset all sandbox data (menu changes and orders)?')) {
    resetSandboxState()
    activePreset.value = 'default'
  }
}
</script>
