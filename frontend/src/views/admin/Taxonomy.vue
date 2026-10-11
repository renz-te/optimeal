<template>
  <div class="p-8 font-sans antialiased text-slate-900">
    <!-- Top Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Allergen Taxonomy & Ingredient Registry</h1>
        <p class="text-slate-500 mt-1 font-medium">
          Manage system ingredients and define mappings to critical medical allergens.
        </p>
      </div>
      <button
        id="btn-add-ingredient"
        @click="openAddModal"
        class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-lg font-bold shadow-sm transition-colors flex items-center justify-center space-x-2 shrink-0"
      >
        <Plus :size="18" />
        <span>Add Ingredient</span>
      </button>
    </div>

    <!-- Feedback Alerts -->
    <div v-if="pageError" class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm font-semibold flex items-center">
      <AlertCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ pageError }}</span>
    </div>

    <div v-if="successMessage" class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-sm font-semibold flex items-center">
      <CheckCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ successMessage }}</span>
    </div>

    <!-- Rule Callout Banner -->
    <div class="mb-8 bg-amber-50/80 border border-amber-200 rounded-2xl p-5 flex items-start space-x-4">
      <div class="p-2.5 bg-amber-100 text-amber-700 rounded-xl shrink-0 mt-0.5">
        <ShieldAlert :size="20" />
      </div>
      <div class="text-sm">
        <h3 class="font-bold text-amber-900">Safety Invariant Enforced</h3>
        <p class="text-amber-800 mt-0.5 leading-relaxed">
          Critical allergens come strictly from the ingredient-to-allergen lookup table in the live database. AI suggestions never bypass or invent allergen mappings.
        </p>
      </div>
    </div>

    <!-- Overview Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Registered Ingredients</p>
          <h3 class="text-2xl font-black text-slate-800 mt-1">{{ ingredients.length }}</h3>
        </div>
        <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center font-bold">
          Ing
        </div>
      </div>

      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Critical Allergens</p>
          <h3 class="text-2xl font-black text-rose-600 mt-1">{{ allergens.length }}</h3>
        </div>
        <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center font-bold">
          Allg
        </div>
      </div>

      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Digestive Sensitivities</p>
          <h3 class="text-2xl font-black text-indigo-600 mt-1">{{ sensitivities.length }}</h3>
        </div>
        <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center font-bold">
          Sens
        </div>
      </div>
    </div>

    <!-- Critical Allergens Master Badges -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-8">
      <h2 class="text-base font-bold text-slate-900 mb-3 flex items-center">
        <span class="w-2.5 h-2.5 bg-rose-500 rounded-full mr-2"></span>
        Master Critical Allergens (Database Source of Truth)
      </h2>
      <div v-if="loadingTaxonomy" class="text-slate-400 text-sm italic py-2">
        Loading taxonomy definitions...
      </div>
      <div v-else class="flex flex-wrap gap-2">
        <span
          v-for="allergen in allergens"
          :key="allergen.id"
          class="inline-flex items-center bg-rose-50 border border-rose-200 text-rose-700 px-3 py-1.5 rounded-full text-xs font-bold"
        >
          {{ allergen.name }}
          <span class="ml-1.5 text-[10px] px-1.5 py-0.5 bg-rose-200/60 rounded-full font-mono text-rose-800">#{{ allergen.id }}</span>
        </span>
      </div>
    </div>

    <!-- Ingredients Registry & Mappings Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <!-- Table Controls -->
      <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-slate-50/50">
        <div>
          <h2 class="font-bold text-slate-900">Ingredients & Allergen Mappings</h2>
          <p class="text-xs text-slate-500 mt-0.5">Showing {{ filteredIngredients.length }} of {{ ingredients.length }} ingredients</p>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          <!-- Search Input -->
          <div class="relative">
            <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search ingredients..."
              class="pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-lg text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none w-full sm:w-64"
            />
          </div>

          <!-- Allergen Filter -->
          <select
            v-model="filterAllergenId"
            class="px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm text-slate-700 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none"
          >
            <option value="">All Allergens</option>
            <option value="none">No Allergens (Safe)</option>
            <option v-for="a in allergens" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
        </div>
      </div>

      <!-- Table Body -->
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200">
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Ingredient Name</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Mapped Critical Allergens</th>
              <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loadingTaxonomy">
              <td colspan="3" class="px-6 py-8 text-center text-slate-500 font-medium">
                <span class="inline-block animate-spin w-5 h-5 border-2 border-emerald-600 border-t-transparent rounded-full mr-2 align-middle"></span>
                Loading ingredients from database...
              </td>
            </tr>
            <tr v-else-if="filteredIngredients.length === 0">
              <td colspan="3" class="px-6 py-8 text-center text-slate-400 font-medium italic">
                No ingredients found matching your search.
              </td>
            </tr>
            <tr
              v-for="item in filteredIngredients"
              :key="item.id"
              class="hover:bg-slate-50/50 transition-colors"
            >
              <td class="px-6 py-4 font-bold text-slate-900">
                {{ item.name }}
              </td>
              <td class="px-6 py-4">
                <div v-if="item.allergens && item.allergens.length > 0" class="flex flex-wrap gap-1.5">
                  <span
                    v-for="allergen in item.allergens"
                    :key="allergen"
                    class="inline-flex items-center bg-rose-50 border border-rose-200 text-rose-700 px-2.5 py-0.5 rounded-full text-xs font-bold"
                  >
                    {{ allergen }}
                  </span>
                </div>
                <div v-else class="text-xs text-slate-400 font-medium italic">
                  No critical allergens
                </div>
              </td>
              <td class="px-6 py-4 text-right">
                <button
                  @click="openEditModal(item)"
                  class="inline-flex items-center space-x-1 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors border border-slate-200 hover:border-emerald-200"
                >
                  <Edit2 :size="14" />
                  <span>Edit Mappings</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add / Edit Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Modal Header -->
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
          <div>
            <h3 class="text-xl font-bold text-slate-900">
              {{ editingId ? 'Edit Ingredient & Allergens' : 'Add New Ingredient' }}
            </h3>
            <p class="text-xs text-slate-500 mt-1">
              {{ editingId ? 'Update name or allergen mappings.' : 'Create an ingredient and map it to critical allergens.' }}
            </p>
          </div>
          <button @click="closeModal" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors">
            <X :size="20" />
          </button>
        </div>

        <!-- Modal Form -->
        <form @submit.prevent="handleSaveIngredient" class="p-6 overflow-y-auto space-y-6">
          <div v-if="modalError" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center">
            <AlertCircle :size="18" class="mr-2 shrink-0" />
            <span>{{ modalError }}</span>
          </div>

          <!-- Name Field -->
          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">
              Ingredient Name <span class="text-rose-500">*</span>
            </label>
            <input
              id="new-ingredient-name"
              v-model="form.name"
              type="text"
              required
              placeholder="e.g. Cashew Butter"
              class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all text-sm font-medium"
            />
          </div>

          <!-- Allergen Checkboxes -->
          <div>
            <div class="flex justify-between items-center mb-2">
              <label class="block text-sm font-bold text-slate-700">
                Critical Allergens Contained
              </label>
              <span class="text-xs text-slate-400 font-medium">Select all that apply</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 bg-slate-50/60 p-4 rounded-xl border border-slate-200">
              <label
                v-for="allergen in allergens"
                :key="allergen.id"
                :id="`allergen-option-${allergen.id}`"
                class="flex items-center space-x-2.5 p-2 rounded-lg cursor-pointer transition-colors border select-none"
                :class="form.allergen_ids.includes(allergen.id)
                  ? 'bg-rose-50/80 border-rose-300 text-rose-900 font-bold'
                  : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'"
              >
                <input
                  type="checkbox"
                  :value="allergen.id"
                  v-model="form.allergen_ids"
                  class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4 border-slate-300"
                />
                <span class="text-xs">{{ allergen.name }}</span>
              </label>
            </div>
          </div>

          <!-- Modal Actions -->
          <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
            <button
              type="button"
              @click="closeModal"
              class="px-5 py-2.5 rounded-lg border border-slate-200 font-bold text-sm text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Cancel
            </button>
            <button
              id="save-ingredient-btn"
              type="submit"
              :disabled="saving || !form.name.trim()"
              class="px-6 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-sm transition-colors disabled:opacity-50 flex items-center space-x-2"
            >
              <span v-if="saving" class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
              <span>{{ saving ? 'Saving...' : (editingId ? 'Save Changes' : 'Create Ingredient') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Plus, Edit2, X, Search, AlertCircle, CheckCircle, ShieldAlert } from 'lucide-vue-next'

const allergens = ref([])
const sensitivities = ref([])
const ingredients = ref([])
const loadingTaxonomy = ref(false)
const pageError = ref('')
const successMessage = ref('')

const searchQuery = ref('')
const filterAllergenId = ref('')

const showModal = ref(false)
const editingId = ref(null)
const saving = ref(false)
const modalError = ref('')
const form = reactive({
  name: '',
  allergen_ids: []
})

const fetchTaxonomy = async () => {
  loadingTaxonomy.value = true
  pageError.value = ''
  try {
    const res = await fetch('/api/admin/taxonomy')
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to load taxonomy data.')
    }
    allergens.value = data.allergens || []
    sensitivities.value = data.sensitivities || []
    ingredients.value = data.ingredients || []
  } catch (err) {
    console.error('Error fetching taxonomy:', err)
    pageError.value = err.message
  } finally {
    loadingTaxonomy.value = false
  }
}

onMounted(() => {
  fetchTaxonomy()
})

const filteredIngredients = computed(() => {
  let list = ingredients.value

  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase().trim()
    list = list.filter(item => item.name.toLowerCase().includes(q))
  }

  if (filterAllergenId.value !== '') {
    if (filterAllergenId.value === 'none') {
      list = list.filter(item => !item.allergen_ids || item.allergen_ids.length === 0)
    } else {
      const targetId = Number(filterAllergenId.value)
      list = list.filter(item => item.allergen_ids && item.allergen_ids.includes(targetId))
    }
  }

  return list
})

const openAddModal = () => {
  editingId.value = null
  modalError.value = ''
  form.name = ''
  form.allergen_ids = []
  showModal.value = true
}

const openEditModal = (item) => {
  editingId.value = item.id
  modalError.value = ''
  form.name = item.name
  form.allergen_ids = item.allergen_ids ? [...item.allergen_ids] : []
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  modalError.value = ''
}

const handleSaveIngredient = async () => {
  if (!form.name.trim()) return

  modalError.value = ''
  saving.value = true
  try {
    const payload = {
      name: form.name.trim(),
      allergen_ids: form.allergen_ids
    }
    if (editingId.value) {
      payload.id = editingId.value
    }

    const res = await fetch('/api/admin/ingredient', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to save ingredient.')
    }

    successMessage.value = `Ingredient "${form.name.trim()}" saved successfully!`
    setTimeout(() => {
      successMessage.value = ''
    }, 4000)

    closeModal()
    await fetchTaxonomy()
  } catch (err) {
    console.error('Save ingredient error:', err)
    modalError.value = err.message
  } finally {
    saving.value = false
  }
}
</script>
