<template>
  <div class="sticky top-0 z-[100] bg-slate-950 text-white border-b border-slate-800 px-4 py-2 flex items-center justify-between text-xs font-sans">
    <!-- Left -->
    <div class="flex items-center space-x-3">
      <span class="font-black text-indigo-400 tracking-widest uppercase">OptiMeal Admin Sandbox</span>
      <span class="px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 border border-slate-700 font-bold tracking-wider">
        MODE: {{ activePreviewMode.toUpperCase() }}
      </span>
    </div>

    <!-- Center -->
    <div class="flex items-center space-x-2">
      <button 
        @click="activePreviewMode = 'admin'; $router.push('/admin/dashboard')"
        class="px-3 py-1 rounded transition-colors font-semibold"
        :class="activePreviewMode === 'admin' ? 'bg-indigo-500 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'"
      >
        [ Admin View ]
      </button>
      <button 
        @click="enterVendorMode()"
        class="px-3 py-1 rounded transition-colors font-semibold"
        :class="activePreviewMode === 'vendor' ? 'bg-amber-500 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'"
      >
        [ Vendor View ]
      </button>
      <button 
        @click="enterCustomerMode('clean')"
        class="px-3 py-1 rounded transition-colors font-semibold"
        :class="activePreviewMode === 'customer' ? 'bg-emerald-500 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'"
      >
        [ Customer View ]
      </button>

      <div class="w-px h-4 bg-slate-700 mx-2"></div>

      <select 
        v-if="activePreviewMode === 'customer'"
        v-model="selectedPreset"
        @change="handlePresetChange"
        class="bg-slate-900 border border-slate-700 text-slate-300 rounded px-2 py-1 outline-none focus:border-emerald-500"
      >
        <option value="clean">Clean / No Restrictions</option>
        <option value="peanuts_pork_spicy">Peanuts + No Pork + Spicy</option>
        <option value="shellfish_bagoong">Shellfish & Bagoong Allergy</option>
        <option value="vegetarian">Strict Vegetarian</option>
        <option value="custom" disabled>Custom Profile</option>
      </select>
    </div>

    <!-- Right -->
    <div>
      <button @click="exitSandbox" class="px-3 py-1 bg-rose-500/20 text-rose-400 hover:bg-rose-500 hover:text-white rounded border border-rose-500/50 transition-colors font-bold">
        [ Exit to Admin Console ]
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSandbox } from '../stores/sandbox'

const router = useRouter()
const { activePreviewMode, simulatedDietaryFlags, enterVendorMode, enterCustomerMode, exitSandbox, detectPreset } = useSandbox()

const selectedPreset = ref('clean')

const handlePresetChange = () => {
  if (selectedPreset.value !== 'custom') {
    enterCustomerMode(selectedPreset.value)
  }
}

const syncPreset = () => {
  selectedPreset.value = detectPreset(simulatedDietaryFlags.value)
}

onMounted(() => {
  syncPreset()
})

watch(simulatedDietaryFlags, () => {
  syncPreset()
}, { deep: true })
</script>
