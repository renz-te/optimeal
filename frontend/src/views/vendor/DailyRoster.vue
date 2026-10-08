<template>
  <div class="p-8 pb-32 font-sans antialiased text-slate-900 max-w-6xl mx-auto h-full flex flex-col">
    <div class="flex justify-between items-center mb-8 flex-shrink-0">
      <div>
        <h2 class="text-3xl font-black text-slate-900 tracking-tight">Launch Daily Lineup</h2>
        <p class="text-slate-500 font-medium mt-1">Select dishes from the master catalog, set stock and pricing, and publish today's menu.</p>
      </div>
      <button @click="showCustomModal = true" class="flex items-center space-x-2 bg-slate-900 text-white px-5 py-2.5 rounded-lg font-bold shadow-md hover:bg-slate-800 transition-colors">
        <Plus :size="18" />
        <span>Add Custom Viand</span>
      </button>
    </div>

    <!-- Roster Table -->
    <div class="bg-white border border-slate-200 shadow-sm rounded-xl overflow-hidden flex-1 flex flex-col">
      <div class="overflow-x-auto flex-1">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold sticky top-0 z-10">
            <tr>
              <th class="px-6 py-4 w-12 text-center">Inc</th>
              <th class="px-6 py-4">Dish Name</th>
              <th class="px-6 py-4">Category</th>
              <th class="px-6 py-4 w-32">Initial Stock</th>
              <th class="px-6 py-4 w-32">Current Price (₱)</th>
              <th class="px-6 py-4 w-20 text-center">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in activeRoster" :key="item.id" class="transition-colors hover:bg-slate-50/50" :class="item.selected ? 'bg-emerald-50/30' : ''">
              <td class="px-6 py-4 text-center">
                <input type="checkbox" v-model="item.selected" class="w-5 h-5 rounded text-emerald-600 focus:ring-emerald-500 cursor-pointer">
              </td>
              <td class="px-6 py-4">
                <div class="font-bold text-slate-900">{{ item.name }}</div>
                <div class="text-[11px] text-slate-500 truncate max-w-[200px]" :title="item.ingredients.join(', ')">{{ item.ingredients.join(', ') }}</div>
              </td>
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                  {{ item.category }}
                </span>
              </td>
              <td class="px-6 py-4">
                <input type="number" v-model.number="item.stock" min="1" :disabled="!item.selected" class="w-20 px-2 py-1.5 bg-white border border-slate-300 rounded font-bold text-slate-900 disabled:opacity-50 disabled:bg-slate-100 outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center space-x-2">
                  <input type="number" v-model.number="item.current_price" min="0" step="0.5" :disabled="!item.selected" class="w-20 px-2 py-1.5 bg-white border border-slate-300 rounded font-bold text-slate-900 disabled:opacity-50 disabled:bg-slate-100 outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                  <span class="text-xs text-slate-400 font-medium">(Base: ₱{{ item.base_price }})</span>
                </div>
              </td>
              <td class="px-6 py-4 text-center">
                <button @click="openEditModal(item)" class="text-slate-400 hover:text-indigo-600 transition-colors p-1 rounded hover:bg-indigo-50">
                  <Edit2 :size="16" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Action Bar -->
    <div class="fixed bottom-0 left-0 md:left-64 right-0 p-4 bg-white/80 backdrop-blur border-t border-slate-200 z-30 flex justify-between items-center shadow-[0_-10px_20px_-10px_rgba(0,0,0,0.05)]">
      <div class="text-sm font-bold text-slate-600">
        <span class="text-emerald-600 text-lg mr-1">{{ activeRoster.filter(r => r.selected).length }}</span> dishes selected for lineup
      </div>
      <button @click="launchMenu" :disabled="!activeRoster.some(r => r.selected) || launchState === 'success'" class="px-8 py-3 rounded-xl font-black text-lg shadow-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              :class="launchState === 'success' ? 'bg-slate-700 text-white cursor-default' : 'bg-emerald-600 text-white hover:bg-emerald-700 hover:shadow-xl active:scale-95'">
        {{ launchState === 'success' ? 'Lineup Active ✓' : 'Launch Live Menu 🚀' }}
      </button>
    </div>

    <!-- Edit/Add Slide-Over -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showCustomModal || editItem" class="fixed bottom-0 left-0 right-0 top-10 bg-black/40 z-30 backdrop-blur-sm" @click="closeModal"></div>
    </transition>
    
    <transition enter-active-class="transition transform duration-300 ease-out" leave-active-class="transition transform duration-300 ease-in" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
      <div v-if="showCustomModal || editItem" class="fixed bottom-0 right-0 top-10 z-40 w-full max-w-lg bg-white shadow-2xl flex flex-col overflow-hidden">
          
          <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
            <h3 class="text-xl font-bold text-slate-900">{{ editItem ? 'Edit Catalog Dish' : 'Add Custom Viand' }}</h3>
            <button @click="closeModal" class="text-slate-400 hover:text-slate-700 transition-colors"><X :size="20" /></button>
          </div>

          <div class="p-6 flex-1 overflow-y-auto space-y-6 hide-scrollbar">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Dish Name</label>
                <input type="text" v-model="formData.name" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-emerald-500 font-medium">
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Category</label>
                <select v-model="formData.category" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-emerald-500 font-medium bg-white">
                  <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Base Price (₱)</label>
                <input type="number" v-model.number="formData.base_price" min="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-emerald-500 font-medium">
              </div>
              <div v-if="!editItem">
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Description</label>
                <input type="text" v-model="formData.description" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-emerald-500 font-medium" placeholder="Short description...">
              </div>
            </div>

            <div class="flex justify-end pt-2 mb-2">
              <button @click.prevent="enrichDish" :disabled="isEnriching" class="text-xs font-bold bg-indigo-50 text-indigo-600 px-4 py-2 rounded-lg border border-indigo-200 hover:bg-indigo-100 transition-colors flex items-center gap-1.5 disabled:opacity-50">
                 <span v-if="isEnriching" class="animate-spin text-indigo-500">⏳</span>
                 <span v-else>✨</span>
                 {{ isEnriching ? 'Analyzing...' : 'Auto-fill with AI' }}
              </button>
            </div>

            <div class="grid grid-cols-5 gap-2 border border-slate-200 bg-slate-50 rounded-lg p-3">
              <div>
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1">Est. Calories</label>
                <input type="number" v-model.number="formData.macros.calories" class="w-full px-2 py-1.5 border border-slate-300 rounded-md outline-none focus:border-emerald-500 font-medium text-xs text-center bg-white">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1">Est. Protein (g)</label>
                <input type="number" v-model.number="formData.macros.protein_g" class="w-full px-2 py-1.5 border border-slate-300 rounded-md outline-none focus:border-emerald-500 font-medium text-xs text-center bg-white">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1">Est. Carbs (g)</label>
                <input type="number" v-model.number="formData.macros.carbs_g" class="w-full px-2 py-1.5 border border-slate-300 rounded-md outline-none focus:border-emerald-500 font-medium text-xs text-center bg-white">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1">Est. Fat (g)</label>
                <input type="number" v-model.number="formData.macros.fat_g" class="w-full px-2 py-1.5 border border-slate-300 rounded-md outline-none focus:border-emerald-500 font-medium text-xs text-center bg-white">
              </div>
              <div>
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1">Est. Sodium (mg)</label>
                <input type="number" v-model.number="formData.macros.sodium_mg" class="w-full px-2 py-1.5 border border-slate-300 rounded-md outline-none focus:border-emerald-500 font-medium text-xs text-center bg-white">
              </div>
            </div>
            <p class="text-[10px] text-slate-500 mb-4 mt-1 italic">Note: Macronutrients are AI-generated estimates based on standard portions. Please review and adjust if necessary.</p>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Ingredients (Comma separated)</label>
              <input type="text" v-model="formData.ingredientsString" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-emerald-500 font-medium text-sm" placeholder="e.g. Pork Belly, Soy Sauce, Garlic">
            </div>

            <div class="mt-4 mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
               <label class="flex items-start space-x-2 cursor-pointer">
                 <input type="checkbox" v-model="formData.ai_confirmed" class="mt-0.5 w-4 h-4 text-amber-600 rounded border-amber-300 focus:ring-amber-500 cursor-pointer">
                 <span class="text-xs font-bold text-amber-900">I have reviewed and confirm these ingredients and macro estimates.</span>
               </label>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-3 flex items-center justify-between">
                <span>Allergens (Medical)</span>
                <label class="flex items-center space-x-1 cursor-pointer normal-case tracking-normal">
                  <input type="checkbox" v-model="formData.isAllergenFree" @change="toggleAllergenFree" class="w-3.5 h-3.5 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer">
                  <span class="text-[11px] font-bold text-slate-600">Allergen-Free Recipe</span>
                </label>
              </label>
              <div class="flex flex-wrap gap-2" :class="formData.isAllergenFree ? 'opacity-50 pointer-events-none' : ''">
                <button v-for="tag in masterTaxonomy.allergens" :key="tag" 
                        @click="toggleTag('allergens', tag)"
                        class="px-3 py-1 text-xs font-bold rounded-full border transition-colors"
                        :class="formData.allergens.includes(tag) ? 'bg-rose-100 text-rose-700 border-rose-300' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'">
                  {{ tag }}
                </button>
              </div>
              <div class="flex items-center space-x-2 mt-3" :class="formData.isAllergenFree ? 'opacity-50 pointer-events-none' : ''">
                 <input type="text" v-model="newCustomAllergen" placeholder="+ Add missing allergen" @keyup.enter="addNewAllergen" class="text-xs px-3 py-1.5 border border-slate-300 rounded-lg w-48 focus:border-emerald-500 outline-none font-medium">
                 <button @click="addNewAllergen" class="text-xs font-bold bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg hover:bg-slate-300 transition-colors">Add</button>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Dietary Boundaries</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="tag in masterTaxonomy.dietary" :key="tag" 
                        @click="toggleTag('dietary', tag)"
                        class="px-3 py-1 text-xs font-bold rounded-full border transition-colors"
                        :class="formData.dietary.includes(tag) ? 'bg-indigo-100 text-indigo-700 border-indigo-300' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'">
                  {{ tag }}
                </button>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Digestive Sensitivities</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="tag in masterTaxonomy.sensitivities" :key="tag" 
                        @click="toggleTag('sensitivities', tag)"
                        class="px-3 py-1 text-xs font-bold rounded-full border transition-colors"
                        :class="formData.sensitivities.includes(tag) ? 'bg-amber-100 text-amber-700 border-amber-300' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'">
                  {{ tag }}
                </button>
              </div>
            </div>

            <div v-if="!editItem" class="pt-4 border-t border-slate-100">
              <label class="flex items-center space-x-2 cursor-pointer">
                <input type="checkbox" v-model="formData.saveToCatalog" class="w-4 h-4 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer">
                <span class="text-sm font-bold text-slate-700">Save to Master Catalog for future rotation</span>
              </label>
            </div>
          </div>

          <div class="p-6 border-t border-slate-100 bg-slate-50 flex justify-end space-x-3">
            <button @click="closeModal" class="px-5 py-2 text-slate-600 font-bold hover:bg-slate-200 rounded-lg transition-colors">Cancel</button>
            <button @click="saveDish" class="px-5 py-2 bg-slate-900 text-white font-bold rounded-lg shadow-md hover:bg-slate-800 transition-colors">
              {{ editItem ? 'Save Changes' : 'Add to Lineup' }}
            </button>
          </div>
        </div>
    </transition>

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
import { ref, onMounted, reactive, watch } from 'vue'
import { Plus, Edit2, X } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import { useCatalog } from '../../stores/dishCatalog'
import { useSandbox } from '../../stores/sandbox'
import { useTaxonomy } from '../../stores/taxonomy'

const router = useRouter()
const { masterCatalog } = useCatalog()
const { sandboxMenu } = useSandbox()
const { masterTaxonomy, addTag } = useTaxonomy()

const activeRoster = ref([])
const showCustomModal = ref(false)
const editItem = ref(null)
const newCustomAllergen = ref('')
const launchState = ref('idle')

const toast = ref({ show: false, message: '', type: 'success' })
const showToast = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3000)
}

const categories = ['Sabaw / Soup', 'Ulam / Stews', 'Prito / Fried', 'Gulay & Veg', 'Dessert / Snacks']

const formData = reactive({
  name: '',
  description: '',
  category: 'Ulam / Stews',
  base_price: 50,
  ingredientsString: '',
  macros: { calories: null, protein_g: null, carbs_g: null, fat_g: null, sodium_mg: null },
  ai_confirmed: false,
  allergens: [],
  dietary: [],
  sensitivities: [],
  saveToCatalog: false,
  isAllergenFree: false
})

const isEnriching = ref(false)

const enrichDish = async () => {
  if (!formData.name.trim()) {
    showToast('Please enter a dish name first.', 'error')
    return
  }

  isEnriching.value = true
  try {
    const res = await fetch('/api/vendor/enrich-dish', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: formData.name,
        description: formData.description
      })
    })

    const data = await res.json()

    if (!res.ok || data.fallback || data.error) {
      showToast(data.error || 'AI currently unavailable. Please enter macros manually.', 'error')
      return
    }

    formData.ingredientsString = (data.ingredients || []).join(', ')
    if (data.macros) {
      formData.macros = { ...data.macros }
    }
    showToast('Data auto-filled successfully!', 'success')

  } catch (err) {
    showToast('AI currently unavailable. Please enter macros manually.', 'error')
  } finally {
    isEnriching.value = false
  }
}

const initRoster = () => {
  // Try to match active sandbox menu to catalog
  const currentMenuIds = sandboxMenu.value.map(d => d.id)
  
  activeRoster.value = masterCatalog.value.map(dish => {
    const existing = sandboxMenu.value.find(m => m.id === dish.id)
    return {
      ...dish,
      selected: !!existing,
      stock: existing ? existing.stock : 10,
      current_price: existing ? existing.current_price : dish.base_price,
    }
  })
}

onMounted(() => {
  initRoster()
})

watch(masterCatalog, () => {
  initRoster()
}, { deep: true })

const openEditModal = (item) => {
  editItem.value = item
  formData.name = item.name
  formData.description = item.description
  formData.category = item.category
  formData.base_price = item.base_price
  formData.ingredientsString = (item.ingredients || []).join(', ')
  formData.macros = item.macros ? { ...item.macros } : { calories: null, protein_g: null, carbs_g: null, fat_g: null, sodium_mg: null }
  formData.ai_confirmed = item.ai_confirmed || false
  formData.allergens = [...(item.allergens || [])]
  formData.dietary = [...(item.dietary || [])]
  formData.sensitivities = [...(item.sensitivities || [])]
  formData.isAllergenFree = formData.allergens.length === 0
  showCustomModal.value = true
}

const toggleAllergenFree = () => {
  if (formData.isAllergenFree) {
    formData.allergens = []
  }
}

const closeModal = () => {
  showCustomModal.value = false
  editItem.value = null
  formData.name = ''
  formData.description = ''
  formData.base_price = 50
  formData.ingredientsString = ''
  formData.macros = { calories: null, protein_g: null, carbs_g: null, fat_g: null, sodium_mg: null }
  formData.ai_confirmed = false
  formData.allergens = []
  formData.dietary = []
  formData.sensitivities = []
  formData.saveToCatalog = false
  formData.isAllergenFree = false
}

const toggleTag = (group, tag) => {
  const idx = formData[group].indexOf(tag)
  if (idx > -1) formData[group].splice(idx, 1)
  else {
    formData[group].push(tag)
    if (group === 'allergens') formData.isAllergenFree = false
  }
}

const addNewAllergen = () => {
  const val = newCustomAllergen.value.trim()
  if (val) {
    addTag('allergens', val)
    if (!formData.allergens.includes(val)) {
      formData.allergens.push(val)
      formData.isAllergenFree = false
    }
    newCustomAllergen.value = ''
  }
}

const saveDish = async () => {
  if (formData.macros.calories !== null && formData.macros.calories !== '' && !formData.ai_confirmed) {
    showToast('Please review and confirm the ingredients and macros before saving.', 'error')
    return
  }

  const payload = {
    id: editItem.value ? editItem.value.id : null,
    name: formData.name,
    description: formData.description,
    category: formData.category,
    base_price: formData.base_price,
    ingredientsString: formData.ingredientsString,
    macros: formData.macros,
    ai_confirmed: formData.ai_confirmed,
    portion: formData.portion
  }

  let finalAllergens = [...formData.allergens]
  let returnedId = null
  let isOffline = false

  try {
    const res = await fetch('/api/vendor/dish', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    
    if (!res.ok) throw new Error('Failed to save dish')
    const data = await res.json()
    returnedId = data.id
    
    if (data.mapped_allergens && data.mapped_allergens.length > 0) {
      finalAllergens = [...new Set([...finalAllergens, ...data.mapped_allergens])]
    }
  } catch (err) {
    isOffline = true
  }

  const ingredients = formData.ingredientsString.split(',').map(s => s.trim()).filter(Boolean)
  const dishData = {
    name: formData.name,
    description: formData.description,
    category: formData.category,
    base_price: formData.base_price,
    ingredients,
    allergens: finalAllergens,
    dietary: [...formData.dietary],
    sensitivities: [...formData.sensitivities],
    macros: formData.macros,
    ai_confirmed: formData.ai_confirmed,
    decay: 30
  }

  if (editItem.value) {
    const idx = masterCatalog.value.findIndex(d => d.id === editItem.value.id)
    if (idx !== -1) masterCatalog.value[idx] = { ...masterCatalog.value[idx], ...dishData }
  } else {
    const idToUse = returnedId || Date.now()
    if (formData.saveToCatalog) {
      masterCatalog.value.push({ ...dishData, id: idToUse })
    } else {
      activeRoster.value.unshift({
        ...dishData,
        id: idToUse,
        selected: true,
        stock: 10,
        current_price: dishData.base_price
      })
    }
  }

  closeModal()
  
  if (isOffline) {
    showToast('Backend unreachable. Dish saved locally (Offline Mode).', 'error')
  }
}

const launchMenu = () => {
  const selectedItems = activeRoster.value.filter(r => r.selected)
  
  const newMenu = selectedItems.map(item => ({
    id: item.id,
    name: item.name,
    description: item.description,
    category: item.category,
    base_price: item.base_price,
    current_price: item.current_price,
    stock: item.stock,
    decay: item.decay || 30,
    ingredients: item.ingredients,
    macros: item.macros,
    ai_confirmed: item.ai_confirmed,
    allergens: item.allergens,
    dietary: item.dietary,
    sensitivities: item.sensitivities
  }))
  
  sandboxMenu.value = newMenu
  showToast("Today's lineup has been published successfully.", "success")
  launchState.value = 'success'
  setTimeout(() => {
    launchState.value = 'idle'
  }, 2000)
}
</script>
