<template>
  <div class="max-w-2xl mx-auto p-6 bg-white border border-slate-200 rounded-xl space-y-6">
    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-6">Profile & Dietary Settings</h2>

    <!-- Profile Details -->
    <div class="space-y-4">
      <h3 class="text-lg font-bold text-slate-800">Profile Details</h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-bold text-slate-700 mb-1">Full Name</label>
          <input v-model="profile.name" type="text" class="w-full px-4 py-3 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all" readonly>
        </div>
        <div>
          <label class="block text-sm font-bold text-slate-700 mb-1">Email / Campus ID</label>
          <input v-model="profile.email" type="email" class="w-full px-4 py-3 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all bg-slate-50 text-slate-500" readonly>
        </div>
      </div>
    </div>

    <hr class="border-slate-100">

    <!-- Nutritional Targets -->
    <div class="space-y-4">
      <h3 class="text-lg font-bold text-slate-800">Nutritional Goals</h3>
      <p class="text-sm text-slate-600">Configure your daily caloric target to personalize your meal recommendations.</p>
      <div class="max-w-xs">
        <label class="block text-sm font-bold text-slate-700 mb-1">Target Daily Calories (kcal)</label>
        <input id="target-calories-input" v-model.number="targetCalories" type="number" min="500" max="6000" step="50"
               class="w-full px-4 py-3 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all font-semibold text-slate-800"
               placeholder="2000">
      </div>
    </div>

    <hr class="border-slate-100">

    <!-- Medical Allergens -->
    <div class="space-y-4">
      <h3 class="text-lg font-bold text-slate-800">Medical Allergens</h3>
      <p class="text-sm text-slate-600">Select any severe allergies. We will block these items from being added to your plate without explicit confirmation.</p>
      <div class="flex flex-wrap gap-2">
        <button v-for="allergen in masterTaxonomy.allergens" :key="allergen"
                :id="'allergen-' + allergen.toLowerCase().replace(/[^a-z0-9]/g, '-')"
                @click="toggleArr(selectedAllergens, allergen)"
                class="px-4 py-2 rounded-full border text-sm font-semibold transition-colors"
                :class="selectedAllergens.includes(allergen) ? 'bg-rose-50 border-rose-300 text-rose-800' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'">
          {{ allergen }}
        </button>
      </div>
    </div>

    <hr class="border-slate-100">

    <!-- Dietary Boundaries -->
    <div class="space-y-4">
      <h3 class="text-lg font-bold text-slate-800">Dietary Boundaries</h3>
      <p class="text-sm text-slate-600">Select any strict dietary choices. We will highlight items that conflict with these choices.</p>
      <div class="flex flex-wrap gap-2">
        <button v-for="boundary in masterTaxonomy.dietary" :key="boundary"
                @click="toggleArr(selectedBoundaries, boundary)"
                class="px-4 py-2 rounded-full border text-sm font-semibold transition-colors"
                :class="selectedBoundaries.includes(boundary) ? 'bg-indigo-50 border-indigo-300 text-indigo-800' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'">
          {{ boundary }}
        </button>
      </div>
    </div>

    <hr class="border-slate-100">

    <!-- Digestive Sensitivities -->
    <div class="space-y-4">
      <h3 class="text-lg font-bold text-slate-800">Digestive Sensitivities</h3>
      <p class="text-sm text-slate-600">Select items that upset your stomach. We will gently warn you, but won't block you from ordering.</p>
      <div class="flex flex-wrap gap-2">
        <button v-for="sensitivity in masterTaxonomy.sensitivities" :key="sensitivity"
                @click="toggleArr(selectedSensitivities, sensitivity)"
                class="px-4 py-2 rounded-full border text-sm font-semibold transition-colors"
                :class="selectedSensitivities.includes(sensitivity) ? 'bg-amber-50 border-amber-300 text-amber-800' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'">
          {{ sensitivity }}
        </button>
      </div>
    </div>

    <hr class="border-slate-100">

    <!-- Custom Watchlist -->
    <div class="space-y-4">
      <h3 class="text-lg font-bold text-slate-800">Custom Intolerance Watchlist</h3>
      <p class="text-sm text-slate-600">Type specific ingredients or phrases you want to avoid (e.g. "ginger", "msg", "bell pepper").</p>
      <div class="flex flex-wrap gap-2 mb-2">
        <span v-for="tag in customWatchlist" :key="tag" class="bg-slate-800 text-white text-xs font-bold px-3 py-1.5 rounded-full flex items-center shadow-sm">
          {{ tag }} <button @click="removeWatchlist(tag)" class="ml-2 text-slate-400 hover:text-white">&times;</button>
        </span>
      </div>
      <div class="flex items-center space-x-2">
        <input type="text" v-model="newWatchlistTag" @keyup.enter="addWatchlist" placeholder="Add custom trigger and press Enter..." class="flex-1 px-4 py-2 border border-slate-200 rounded-lg focus:border-emerald-500 outline-none text-sm font-medium bg-slate-50">
        <button @click="addWatchlist" class="bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-bold hover:bg-slate-300">Add</button>
      </div>
    </div>

    <hr class="border-slate-100">

    <!-- Save Button & Feedback -->
    <div class="space-y-3 pt-2">
      <div v-if="errorMessage" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center">
        <svg class="w-5 h-5 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        {{ errorMessage }}
      </div>

      <div class="flex items-center space-x-4">
        <button id="save-preferences-btn" @click="saveSettings" :disabled="saving" class="bg-emerald-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-emerald-700 shadow-sm transition-all active:scale-95 disabled:opacity-50 flex items-center">
          <span v-if="saving" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full mr-2"></span>
          Save Preferences
        </button>
        <span v-if="successMessage" class="text-emerald-600 font-medium text-sm flex items-center">
          <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
          {{ successMessage }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useSandbox } from '../../stores/sandbox'
import { useTaxonomy } from '../../stores/taxonomy'

const { activePreviewMode, simulatedDietaryFlags } = useSandbox()
const { masterTaxonomy } = useTaxonomy()

const isAdmin = computed(() => {
  try {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}')
    return user.role === 'admin'
  } catch (e) {
    return false
  }
})

const profile = ref({ name: '', email: '' })
const targetCalories = ref(2000)
const selectedAllergens = ref([])
const selectedBoundaries = ref([])
const selectedSensitivities = ref([])
const customWatchlist = ref([])
const newWatchlistTag = ref('')
const successMessage = ref('')
const errorMessage = ref('')
const loading = ref(false)
const saving = ref(false)

const loadProfile = async () => {
  if (isAdmin.value && activePreviewMode.value === 'customer') {
    profile.value.name = 'Simulated Sandbox User'
    profile.value.email = 'sandbox@optimeal.test'
    targetCalories.value = 2000
    const df = simulatedDietaryFlags.value || {}
    selectedAllergens.value = [...(df.allergens || [])]
    selectedBoundaries.value = [...(df.dietary || [])]
    selectedSensitivities.value = [...(df.sensitivities || [])]
    customWatchlist.value = [...(df.custom_watchlist || [])]
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    const res = await fetch('/api/customer/profile')
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.error || 'Failed to load profile')
    }
    const data = await res.json()
    profile.value.name = data.name || ''
    profile.value.email = data.email || ''
    targetCalories.value = data.target_calories || 2000
    selectedAllergens.value = data.allergens || []
    selectedBoundaries.value = data.dietary_boundaries || []
    selectedSensitivities.value = data.sensitivities || []
    customWatchlist.value = data.custom_watchlist || []
  } catch (err) {
    console.error('Error loading profile:', err)
    errorMessage.value = err.message
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadProfile()
})

const toggleArr = (arr, flag) => {
  const index = arr.indexOf(flag)
  if (index > -1) {
    arr.splice(index, 1)
  } else {
    arr.push(flag)
  }
}

const addWatchlist = () => {
  const val = newWatchlistTag.value.trim()
  if (val && !customWatchlist.value.includes(val)) {
    customWatchlist.value.push(val)
  }
  newWatchlistTag.value = ''
}

const removeWatchlist = (tag) => {
  customWatchlist.value = customWatchlist.value.filter(t => t !== tag)
}

const saveSettings = async () => {
  successMessage.value = ''
  errorMessage.value = ''
  saving.value = true

  const payload = {
    target_calories: targetCalories.value,
    allergens: [...selectedAllergens.value],
    dietary_boundaries: [...selectedBoundaries.value],
    sensitivities: [...selectedSensitivities.value],
    custom_watchlist: [...customWatchlist.value]
  }

  if (isAdmin.value && activePreviewMode.value === 'customer') {
    simulatedDietaryFlags.value = {
      allergens: payload.allergens,
      dietary: payload.dietary_boundaries,
      sensitivities: payload.sensitivities,
      custom_watchlist: payload.custom_watchlist
    }
    successMessage.value = 'Sandbox Preferences updated successfully.'
    saving.value = false
    setTimeout(() => { successMessage.value = '' }, 3000)
    return
  }

  try {
    const res = await fetch('/api/customer/profile', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to save preferences')
    }

    const stored = localStorage.getItem('optimeal_user')
    if (stored) {
      try {
        const parsed = JSON.parse(stored)
        parsed.dietary_flags = {
          allergens: payload.allergens,
          dietary: payload.dietary_boundaries,
          sensitivities: payload.sensitivities,
          custom_watchlist: payload.custom_watchlist
        }
        localStorage.setItem('optimeal_user', JSON.stringify(parsed))
      } catch (e) {}
    }

    successMessage.value = 'Preferences saved successfully.'
    setTimeout(() => { successMessage.value = '' }, 3000)
  } catch (err) {
    console.error('Error saving profile:', err)
    errorMessage.value = err.message
  } finally {
    saving.value = false
  }
}
</script>

