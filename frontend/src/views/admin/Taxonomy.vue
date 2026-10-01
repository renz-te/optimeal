<template>
  <div class="p-8">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Allergen Taxonomy</h1>
        <p class="text-slate-500 mt-1 font-medium">Manage global health metrics, medical allergens, and dietary boundaries.</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      
      <!-- Allergens List -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
        <div class="p-4 border-b border-slate-200 bg-slate-50">
          <h2 class="font-bold text-slate-800 flex items-center">
            <span class="w-2 h-2 bg-rose-500 rounded-full mr-2"></span> Medical Allergens
          </h2>
        </div>
        <div class="p-4 flex-1">
          <div class="flex flex-wrap gap-2 mb-4">
            <span v-for="tag in masterTaxonomy.allergens" :key="tag" class="inline-flex items-center bg-rose-50 border border-rose-200 text-rose-700 px-3 py-1 rounded-full text-xs font-bold">
              {{ tag }}
              <button @click="removeTag('allergens', tag)" class="ml-2 text-rose-400 hover:text-rose-600 focus:outline-none"><X :size="14" /></button>
            </span>
          </div>
          <div class="flex items-center space-x-2 mt-4">
            <input v-model="newAllergen" type="text" placeholder="Add new allergen..." @keyup.enter="handleAdd('allergens')" class="flex-1 px-3 py-1.5 border border-slate-300 rounded text-sm focus:border-emerald-500 focus:outline-none">
            <button @click="handleAdd('allergens')" class="bg-emerald-600 text-white px-3 py-1.5 rounded font-bold text-sm hover:bg-emerald-700">Add</button>
          </div>
        </div>
      </div>

      <!-- Boundaries List -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
        <div class="p-4 border-b border-slate-200 bg-slate-50">
          <h2 class="font-bold text-slate-800 flex items-center">
            <span class="w-2 h-2 bg-indigo-500 rounded-full mr-2"></span> Dietary Boundaries
          </h2>
        </div>
        <div class="p-4 flex-1">
          <div class="flex flex-wrap gap-2 mb-4">
            <span v-for="tag in masterTaxonomy.dietary" :key="tag" class="inline-flex items-center bg-indigo-50 border border-indigo-200 text-indigo-700 px-3 py-1 rounded-full text-xs font-bold">
              {{ tag }}
              <button @click="removeTag('dietary', tag)" class="ml-2 text-indigo-400 hover:text-indigo-600 focus:outline-none"><X :size="14" /></button>
            </span>
          </div>
          <div class="flex items-center space-x-2 mt-4">
            <input v-model="newBoundary" type="text" placeholder="Add new boundary..." @keyup.enter="handleAdd('dietary')" class="flex-1 px-3 py-1.5 border border-slate-300 rounded text-sm focus:border-emerald-500 focus:outline-none">
            <button @click="handleAdd('dietary')" class="bg-emerald-600 text-white px-3 py-1.5 rounded font-bold text-sm hover:bg-emerald-700">Add</button>
          </div>
        </div>
      </div>

      <!-- Sensitivities List -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
        <div class="p-4 border-b border-slate-200 bg-slate-50">
          <h2 class="font-bold text-slate-800 flex items-center">
            <span class="w-2 h-2 bg-amber-500 rounded-full mr-2"></span> Digestive Sensitivities
          </h2>
        </div>
        <div class="p-4 flex-1">
          <div class="flex flex-wrap gap-2 mb-4">
            <span v-for="tag in masterTaxonomy.sensitivities" :key="tag" class="inline-flex items-center bg-amber-50 border border-amber-200 text-amber-700 px-3 py-1 rounded-full text-xs font-bold">
              {{ tag }}
              <button @click="removeTag('sensitivities', tag)" class="ml-2 text-amber-400 hover:text-amber-600 focus:outline-none"><X :size="14" /></button>
            </span>
          </div>
          <div class="flex items-center space-x-2 mt-4">
            <input v-model="newSensitivity" type="text" placeholder="Add sensitivity..." @keyup.enter="handleAdd('sensitivities')" class="flex-1 px-3 py-1.5 border border-slate-300 rounded text-sm focus:border-emerald-500 focus:outline-none">
            <button @click="handleAdd('sensitivities')" class="bg-emerald-600 text-white px-3 py-1.5 rounded font-bold text-sm hover:bg-emerald-700">Add</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { X } from 'lucide-vue-next'
import { useTaxonomy } from '../../stores/taxonomy'

const { masterTaxonomy, addTag, removeTag } = useTaxonomy()

const newAllergen = ref('')
const newBoundary = ref('')
const newSensitivity = ref('')

const handleAdd = (group) => {
  let val = ''
  if (group === 'allergens') { val = newAllergen.value; newAllergen.value = '' }
  if (group === 'dietary') { val = newBoundary.value; newBoundary.value = '' }
  if (group === 'sensitivities') { val = newSensitivity.value; newSensitivity.value = '' }
  
  if (val.trim()) {
    addTag(group, val.trim())
  }
}
</script>
