<template>
  <div class="p-8">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Surplus Decay Rules</h1>
        <p class="text-slate-500 mt-1 font-medium">Automate end-of-day discounts and clearance limits.</p>
      </div>
    </div>

    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm mb-6">
      <h2 class="text-lg font-bold text-slate-800 mb-4">Canteen Closing Time</h2>
      <div class="flex items-center space-x-4">
        <input type="time" v-model="closingTime" class="px-4 py-2 border border-slate-300 rounded-lg focus:border-emerald-500 outline-none font-bold text-slate-700">
        <span class="text-sm text-slate-500">Unclaimed plates expire automatically after this time.</span>
      </div>
    </div>

    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm mb-6">
      <h2 class="text-lg font-bold text-slate-800 mb-2">Discount Schedule Tiers</h2>
      <p class="text-sm text-slate-500 mb-4">Discounts are STEPPED percentages based on <code class="bg-slate-100 px-1 py-0.5 rounded text-rose-600">base_price</code> (non-compounding).<br/>
      Formula: <code class="bg-slate-100 px-1 py-0.5 rounded font-mono text-xs">current_price = Math.round(base_price * (1 - activeDiscount / 100))</code></p>
      <div class="space-y-4">
        <div class="flex items-center justify-between p-4 bg-slate-50 border border-slate-100 rounded-lg">
          <div>
            <div class="font-bold text-slate-800">Tier 1: Early Clearance</div>
            <div class="text-sm text-slate-500">2 hours before closing</div>
          </div>
          <div class="flex items-center space-x-2">
            <input type="range" min="0" max="50" step="5" v-model="tier1Discount" class="w-32">
            <span class="font-bold text-emerald-700 w-12">{{ tier1Discount }}%</span>
          </div>
        </div>
        <div class="flex items-center justify-between p-4 bg-slate-50 border border-slate-100 rounded-lg">
          <div>
            <div class="font-bold text-slate-800">Tier 2: Final Clearance</div>
            <div class="text-sm text-slate-500">1 hour before closing</div>
          </div>
          <div class="flex items-center space-x-2">
            <input type="range" min="0" max="75" step="5" v-model="tier2Discount" class="w-32">
            <span class="font-bold text-emerald-700 w-12">{{ tier2Discount }}%</span>
          </div>
        </div>
      </div>
    </div>

    <div class="bg-rose-50 p-6 rounded-xl border border-rose-200 shadow-sm flex items-center justify-between">
      <div>
        <h2 class="text-lg font-bold text-rose-800 mb-1">Emergency 50% End-of-Day Clearance</h2>
        <p class="text-sm text-rose-600">Instantly apply a 50% discount to all remaining live stock to prevent food waste.</p>
      </div>
      <button @click="triggerEmergency" class="bg-rose-600 text-white px-6 py-3 rounded-lg font-bold shadow-md hover:bg-rose-700 active:scale-95 transition-all">
        Trigger Instant Clearance
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const closingTime = ref('20:00')
const tier1Discount = ref(25)
const tier2Discount = ref(40)

const triggerEmergency = () => {
  alert('Emergency clearance triggered. All prices slashed by 50%!')
}
</script>
