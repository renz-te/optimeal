<template>
  <div class="p-8 pb-32 font-sans antialiased text-slate-900 max-w-6xl mx-auto h-full flex flex-col">
    <div class="flex justify-between items-center mb-8 flex-shrink-0">
      <div>
        <h2 class="text-3xl font-black text-slate-900 tracking-tight">Launch Daily Lineup</h2>
        <p class="text-slate-500 font-medium mt-1">Select dishes from the master catalog, set stock and pricing, and publish today's menu.</p>
      </div>
      <div class="flex items-center gap-3">
        <button @click="openPrepListModal" class="flex items-center space-x-2 bg-emerald-50 text-emerald-700 border border-emerald-200 px-4 py-2.5 rounded-lg font-bold hover:bg-emerald-100 transition-colors">
          <ClipboardList :size="18" />
          <span>Prep Plan ({{ prepPlanData.prep_list?.length || 0 }})</span>
        </button>
        <button @click="showCustomModal = true" class="flex items-center space-x-2 bg-slate-900 text-white px-5 py-2.5 rounded-lg font-bold shadow-md hover:bg-slate-800 transition-colors">
          <Plus :size="18" />
          <span>Add Custom Viand</span>
        </button>
      </div>
    </div>

    <!-- Planned Batch Servings Slider -->
    <div class="bg-white border border-slate-200 shadow-sm rounded-xl p-5 mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
      <div>
        <div class="flex items-center gap-2">
          <h3 class="text-base font-bold text-slate-900">Planned Batch Servings</h3>
          <span class="text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full">
            {{ batchPlannedServings }} portions
          </span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Scale kitchen prep list requirements dynamically across selected dishes.</p>
      </div>
      <div class="flex items-center gap-3 w-full md:w-auto">
        <span class="text-xs font-bold text-slate-400">10</span>
        <input 
          type="range" 
          min="10" 
          max="200" 
          step="5" 
          v-model.number="batchPlannedServings" 
          @input="onServingsSliderInput"
          class="w-full md:w-56 h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-emerald-600"
        />
        <span class="text-xs font-bold text-slate-400">200</span>
        <button 
          @click="openPrepListModal"
          class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-sm transition-all whitespace-nowrap"
        >
          <ClipboardList :size="14" />
          <span>View Prep List ({{ prepPlanData.prep_list?.length || 0 }})</span>
        </button>
      </div>
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
                <div class="flex items-center justify-center gap-1">
                  <button @click="openRecipeModal(item)" title="Recipe & Prep Ingredients" class="text-slate-400 hover:text-emerald-600 transition-colors p-1.5 rounded hover:bg-emerald-50">
                    <Utensils :size="16" />
                  </button>
                  <button @click="openEditModal(item)" title="Edit Dish Details" class="text-slate-400 hover:text-indigo-600 transition-colors p-1.5 rounded hover:bg-indigo-50">
                    <Edit2 :size="16" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Action Bar -->
    <div class="fixed bottom-0 left-0 md:left-64 right-0 p-4 bg-white/95 backdrop-blur border-t border-slate-200 z-30 flex flex-wrap gap-4 justify-between items-center shadow-[0_-10px_20px_-10px_rgba(0,0,0,0.05)]">
      <div class="flex flex-wrap items-center gap-4">
        <div class="text-sm font-bold text-slate-600">
          <span class="text-emerald-600 text-lg mr-1">{{ activeRoster.filter(r => r.selected).length }}</span> dishes selected
        </div>

        <!-- Food Safety Freshness Batch Controls -->
        <div class="flex items-center gap-3 bg-amber-50/80 border border-amber-200 rounded-xl px-3.5 py-1.5 text-xs shadow-sm">
          <span class="font-bold text-amber-900 flex items-center gap-1">
            <span>🛡️</span> Food Safety:
          </span>
          <div class="flex items-center gap-1.5">
            <label class="text-[11px] font-semibold text-slate-500">Cooked:</label>
            <input 
              type="datetime-local" 
              v-model="batchCookedAt" 
              @change="onCookedAtChange"
              class="px-2 py-1 bg-white border border-slate-300 rounded font-medium text-slate-800 text-xs outline-none focus:border-emerald-500"
            />
          </div>
          <div class="flex items-center gap-1.5">
            <label class="text-[11px] font-semibold text-slate-500">Sell-By (+4h):</label>
            <input 
              type="datetime-local" 
              v-model="batchSellBy" 
              class="px-2 py-1 bg-white border border-slate-300 rounded font-medium text-slate-800 text-xs outline-none focus:border-emerald-500"
            />
          </div>
        </div>
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

            <!-- AI Kill Switch Warning Banner -->
            <div v-if="aiDisabled" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-900 text-xs flex items-center gap-2 mb-2">
              <span class="text-base">⚠️</span>
              <span class="font-medium">{{ aiDisabledMessage || 'AI Generation is currently disabled by the Administrator.' }}</span>
            </div>

            <div class="flex justify-end pt-2 mb-2">
              <button 
                @click.prevent="enrichDish" 
                :disabled="isEnriching || aiDisabled" 
                :class="[
                  'text-xs font-bold px-4 py-2 rounded-lg border transition-colors flex items-center gap-1.5',
                  aiDisabled 
                    ? 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed opacity-60' 
                    : 'bg-indigo-50 text-indigo-600 border-indigo-200 hover:bg-indigo-100 disabled:opacity-50'
                ]"
              >
                 <span v-if="isEnriching" class="animate-spin text-indigo-500">⏳</span>
                 <span v-else>{{ aiDisabled ? '🔒' : '✨' }}</span>
                 {{ isEnriching ? 'Analyzing...' : (aiDisabled ? 'AI Disabled' : 'Auto-fill with AI') }}
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

    <!-- Recipe Modal -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showRecipeModal" class="fixed inset-0 bg-slate-900/60 z-40 backdrop-blur-sm" @click="closeRecipeModal"></div>
    </transition>

    <transition enter-active-class="transition transform duration-300 ease-out" leave-active-class="transition transform duration-300 ease-in" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
      <div v-if="showRecipeModal" class="fixed bottom-0 right-0 top-0 z-50 w-full max-w-xl bg-white shadow-2xl flex flex-col overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-xl font-black text-slate-900">Recipe: {{ recipeDish?.name }}</h3>
              <span class="text-xs font-bold text-slate-500 bg-slate-200 px-2 py-0.5 rounded">{{ recipeDish?.category }}</span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Configure batch ingredients, quantities, and verify live allergen mappings.</p>
          </div>
          <button @click="closeRecipeModal" class="text-slate-400 hover:text-slate-700 transition-colors"><X :size="20" /></button>
        </div>

        <div class="p-6 flex-1 overflow-y-auto space-y-6">
          <div class="flex items-center justify-between gap-4 p-4 bg-slate-50 border border-slate-200 rounded-xl">
            <div>
              <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1">Batch Yield Servings</label>
              <input type="number" v-model.number="recipeForm.yield_servings" min="1" class="w-24 px-3 py-1.5 border border-slate-300 rounded-lg font-bold text-slate-900 outline-none focus:border-emerald-500" />
            </div>

            <button 
              @click="suggestRecipe" 
              :disabled="isSuggestingRecipe"
              class="text-xs font-bold bg-indigo-50 text-indigo-600 px-3.5 py-2 rounded-lg border border-indigo-200 hover:bg-indigo-100 transition-colors flex items-center gap-1.5 disabled:opacity-50"
            >
              <Sparkles :size="14" :class="{'animate-spin': isSuggestingRecipe}" />
              <span>{{ isSuggestingRecipe ? 'Consulting AI...' : 'Suggest Recipe (AI)' }}</span>
            </button>
          </div>

          <!-- Live Allergen Preview -->
          <div class="p-4 rounded-xl border" :class="recipeForm.allergens.length > 0 ? 'bg-rose-50/50 border-rose-200' : 'bg-emerald-50/40 border-emerald-200'">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-xs font-black uppercase tracking-wider" :class="recipeForm.allergens.length > 0 ? 'text-rose-800' : 'text-emerald-800'">
                Live Allergen & Dietary Preview
              </h4>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="recipeForm.allergens.length > 0 ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'">
                {{ recipeForm.allergens.length > 0 ? `${recipeForm.allergens.length} Allergens Detected` : 'Allergen Safe' }}
              </span>
            </div>
            <div class="flex flex-wrap gap-1.5">
              <span 
                v-for="allg in recipeForm.allergens" 
                :key="allg" 
                class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full bg-rose-100 text-rose-800 border border-rose-200"
              >
                <AlertTriangle :size="12" />
                {{ allg }}
              </span>
              <span v-if="recipeForm.allergens.length === 0" class="text-xs text-emerald-700 font-medium">
                No critical allergens matched in current ingredient list.
              </span>
            </div>

            <div v-if="recipeForm.has_unmapped" class="mt-3 pt-2.5 border-t border-amber-200/60 flex items-center gap-2 text-xs font-semibold text-amber-800">
              <AlertTriangle :size="14" class="text-amber-600 shrink-0" />
              <span>Some ingredients are unmapped in the admin taxonomy and won't trigger allergen alerts.</span>
            </div>
          </div>

          <!-- Ingredients List -->
          <div>
            <div class="flex justify-between items-center mb-2">
              <label class="text-xs font-bold text-slate-600 uppercase tracking-wider">Recipe Ingredients</label>
              <button @click="addRecipeIngredientRow" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                <Plus :size="14" />
                <span>Add Ingredient</span>
              </button>
            </div>

            <div class="space-y-2">
              <div v-for="(ing, idx) in recipeForm.ingredients" :key="idx" class="flex items-center gap-2 bg-slate-50 p-2.5 rounded-lg border border-slate-200">
                <input 
                  type="text" 
                  v-model="ing.name" 
                  @input="onIngredientNameChange"
                  placeholder="Ingredient name (e.g. Garlic, Chicken Thigh)" 
                  class="flex-1 px-3 py-1.5 border border-slate-300 rounded font-medium text-sm outline-none focus:border-emerald-500" 
                />
                <input 
                  type="number" 
                  step="0.1" 
                  min="0.1" 
                  v-model.number="ing.quantity" 
                  class="w-20 px-2 py-1.5 border border-slate-300 rounded font-bold text-sm outline-none focus:border-emerald-500" 
                />
                <select 
                  v-model="ing.unit" 
                  class="w-20 px-2 py-1.5 border border-slate-300 rounded font-semibold text-xs bg-white outline-none focus:border-emerald-500"
                >
                  <option value="g">g</option>
                  <option value="kg">kg</option>
                  <option value="ml">ml</option>
                  <option value="l">l</option>
                  <option value="pc">pc</option>
                  <option value="tbsp">tbsp</option>
                  <option value="tsp">tsp</option>
                  <option value="cup">cup</option>
                </select>
                <button @click="removeRecipeIngredientRow(idx)" class="text-slate-400 hover:text-rose-600 p-1">
                  <Trash2 :size="16" />
                </button>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Preparation & Cooking Notes</label>
            <textarea 
              v-model="recipeForm.instructions" 
              rows="3" 
              placeholder="Optional prep steps, simmer times, batch handling notes..."
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-emerald-500 resize-none font-normal"
            ></textarea>
          </div>
        </div>

        <div class="p-6 border-t border-slate-100 bg-slate-50 flex justify-end space-x-3">
          <button @click="closeRecipeModal" class="px-5 py-2 text-slate-600 font-bold hover:bg-slate-200 rounded-lg transition-colors">Cancel</button>
          <button @click="saveRecipe" :disabled="isSavingRecipe" class="px-5 py-2 bg-emerald-600 text-white font-bold rounded-lg shadow-md hover:bg-emerald-700 transition-colors disabled:opacity-50">
            {{ isSavingRecipe ? 'Saving...' : 'Save Recipe' }}
          </button>
        </div>
      </div>
    </transition>

    <!-- Consolidated Prep Plan Modal -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showPrepModal" class="fixed inset-0 bg-slate-900/60 z-40 backdrop-blur-sm" @click="showPrepModal = false"></div>
    </transition>

    <transition enter-active-class="transition transform duration-300 ease-out" leave-active-class="transition transform duration-300 ease-in" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
      <div v-if="showPrepModal" class="fixed bottom-0 right-0 top-0 z-50 w-full max-w-2xl bg-white shadow-2xl flex flex-col overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-xl font-black text-slate-900">Consolidated Kitchen Prep Plan</h3>
              <span class="text-xs font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">{{ prepPlanData.total_servings || 0 }} portions</span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Aggregated raw inventory requirements scaled for today's selected lineup.</p>
          </div>
          <button @click="showPrepModal = false" class="text-slate-400 hover:text-slate-700 transition-colors"><X :size="20" /></button>
        </div>

        <div class="p-6 flex-1 overflow-y-auto space-y-6">
          <div class="grid grid-cols-3 gap-3">
            <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200">
              <div class="text-[11px] font-bold text-slate-400 uppercase">Selected Viands</div>
              <div class="text-xl font-black text-slate-900 mt-1">{{ prepPlanData.total_dishes || 0 }} dishes</div>
            </div>
            <div class="bg-emerald-50/50 p-3.5 rounded-xl border border-emerald-200">
              <div class="text-[11px] font-bold text-emerald-600 uppercase">Planned Servings</div>
              <div class="text-xl font-black text-emerald-800 mt-1">{{ prepPlanData.total_servings || 0 }} portions</div>
            </div>
            <div class="bg-indigo-50/50 p-3.5 rounded-xl border border-indigo-200">
              <div class="text-[11px] font-bold text-indigo-600 uppercase">Total Ingredients</div>
              <div class="text-xl font-black text-indigo-800 mt-1">{{ prepPlanData.prep_list?.length || 0 }} items</div>
            </div>
          </div>

          <div class="border border-slate-200 rounded-xl overflow-hidden">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-wider">
                <tr>
                  <th class="px-4 py-3">Ingredient</th>
                  <th class="px-4 py-3 text-right">Required Quantity</th>
                  <th class="px-4 py-3">Used In Dishes</th>
                  <th class="px-4 py-3 text-center">Taxonomy</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="!prepPlanData.prep_list || prepPlanData.prep_list.length === 0">
                  <td colspan="4" class="text-center py-8 text-slate-400 font-medium">
                    No ingredients in prep plan. Select dishes or add recipes to see requirements.
                  </td>
                </tr>
                <tr v-for="item in prepPlanData.prep_list" :key="item.name + item.dimension" class="hover:bg-slate-50/50">
                  <td class="px-4 py-3 font-bold text-slate-900">
                    {{ item.name }}
                  </td>
                  <td class="px-4 py-3 text-right font-black text-emerald-700">
                    {{ item.total_quantity }} {{ item.unit }}
                  </td>
                  <td class="px-4 py-3 text-xs text-slate-600">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="d in item.used_in_dishes" :key="d" class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-medium">
                        {{ d }}
                      </span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-center">
                    <span v-if="item.is_unmapped" class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200">
                      <AlertTriangle :size="10" />
                      Unmapped
                    </span>
                    <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                      Mapped
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="p-6 border-t border-slate-100 bg-slate-50 flex justify-end">
          <button @click="showPrepModal = false" class="px-5 py-2 bg-slate-900 text-white font-bold rounded-lg shadow hover:bg-slate-800 transition-colors">
            Close Prep Plan
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
import { Plus, Edit2, X, Utensils, ClipboardList, Sparkles, Trash2, AlertTriangle, Check } from 'lucide-vue-next'
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

// Phase D2: Recipe & Kitchen Prep State
const batchPlannedServings = ref(30)
const showPrepModal = ref(false)
const showRecipeModal = ref(false)
const recipeDish = ref(null)
const isSuggestingRecipe = ref(false)
const isSavingRecipe = ref(false)
const prepPlanData = ref({ total_dishes: 0, total_servings: 0, dishes: [], prep_list: [] })
let debounceTimer = null

const recipeForm = reactive({
  yield_servings: 10,
  instructions: '',
  ingredients: [],
  allergens: [],
  sensitivities: [],
  has_unmapped: false
})

const formatDateTimeLocal = (date) => {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const now = new Date()
const in4Hours = new Date(now.getTime() + 4 * 60 * 60 * 1000)

const batchCookedAt = ref(formatDateTimeLocal(now))
const batchSellBy = ref(formatDateTimeLocal(in4Hours))

const onCookedAtChange = () => {
  if (batchCookedAt.value) {
    const d = new Date(batchCookedAt.value)
    if (!isNaN(d.getTime())) {
      const d4 = new Date(d.getTime() + 4 * 60 * 60 * 1000)
      batchSellBy.value = formatDateTimeLocal(d4)
    }
  }
}

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
const aiDisabled = ref(false)
const aiDisabledMessage = ref('')

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

    if (res.status === 403) {
      aiDisabled.value = true
      aiDisabledMessage.value = data.error || 'AI Generation is currently disabled by the Administrator.'
      showToast(aiDisabledMessage.value, 'error')
      return
    }

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

const fetchVendorCatalog = async () => {
  try {
    const res = await fetch('/api/vendor/menu')
    if (res.ok) {
      const data = await res.json()
      masterCatalog.value = data
      initRoster()
    }
  } catch (err) {
    console.error('Failed to load vendor catalog:', err)
  }
}

const initRoster = () => {
  activeRoster.value = masterCatalog.value.map(dish => {
    return {
      ...dish,
      selected: dish.is_active === 1,
      stock: dish.stock !== undefined ? dish.stock : (dish.online_pool_qty !== undefined ? dish.online_pool_qty : 10),
      current_price: dish.current_price !== undefined ? dish.current_price : dish.base_price,
    }
  })
  fetchPrepPlan()
}

onMounted(() => {
  fetchVendorCatalog()
})

watch(masterCatalog, () => {
  initRoster()
}, { deep: true })

// Watch roster selections to update prep plan
watch(() => activeRoster.value.map(r => r.selected), () => {
  fetchPrepPlan()
}, { deep: true })

const onServingsSliderInput = () => {
  // Update local state instantly
  const selectedCount = activeRoster.value.filter(r => r.selected).length || 1
  const perDish = Math.max(1, Math.round(batchPlannedServings.value / selectedCount))
  activeRoster.value.forEach(item => {
    if (item.selected) {
      item.stock = perDish
    }
  })

  // Debounced API call to /api/vendor/prep-plan
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    fetchPrepPlan()
  }, 350)
}

const fetchPrepPlan = async () => {
  const selectedItems = activeRoster.value.filter(r => r.selected)
  if (selectedItems.length === 0) {
    prepPlanData.value = { total_dishes: 0, total_servings: 0, dishes: [], prep_list: [] }
    return
  }
  try {
    const payload = {
      items: selectedItems.map(i => ({
        menu_item_id: i.id,
        servings: i.stock || 10
      }))
    }
    const res = await fetch('/api/vendor/prep-plan', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    if (res.ok) {
      prepPlanData.value = await res.json()
    }
  } catch (err) {
    console.error('Failed to fetch prep plan', err)
  }
}

const openPrepListModal = () => {
  fetchPrepPlan()
  showPrepModal.value = true
}

const openRecipeModal = async (item) => {
  recipeDish.value = item
  recipeForm.yield_servings = 10
  recipeForm.instructions = ''
  recipeForm.ingredients = []
  recipeForm.allergens = []
  recipeForm.sensitivities = []
  recipeForm.has_unmapped = false
  showRecipeModal.value = true

  try {
    const res = await fetch(`/api/vendor/dish/recipe?menu_item_id=${item.id}`)
    if (res.ok) {
      const data = await res.json()
      recipeForm.yield_servings = data.yield_servings || 10
      recipeForm.instructions = data.instructions || ''
      recipeForm.ingredients = (data.ingredients || []).map(i => ({
        name: i.name,
        quantity: i.quantity,
        unit: i.unit,
        is_unmapped: i.is_unmapped
      }))
      recipeForm.allergens = data.allergens || []
      recipeForm.sensitivities = data.sensitivities || []
      recipeForm.has_unmapped = data.has_unmapped || false
    }
  } catch (err) {
    console.error('Failed to fetch recipe', err)
  }
}

const closeRecipeModal = () => {
  showRecipeModal.value = false
  recipeDish.value = null
}

const addRecipeIngredientRow = () => {
  recipeForm.ingredients.push({
    name: '',
    quantity: 100,
    unit: 'g',
    is_unmapped: false
  })
}

const removeRecipeIngredientRow = (idx) => {
  recipeForm.ingredients.splice(idx, 1)
  updateAllergenPreview()
}

const updateAllergenPreview = () => {
  const detected = []
  let unmapped = false
  const taxItems = masterTaxonomy.value || []
  recipeForm.ingredients.forEach(ing => {
    const name = (ing.name || '').trim().toLowerCase()
    if (!name) return
    const match = taxItems.find(t => t.name.toLowerCase() === name)
    if (match) {
      if (match.allergens && Array.isArray(match.allergens)) {
        detected.push(...match.allergens)
      }
    } else {
      unmapped = true
    }
  })
  recipeForm.allergens = Array.from(new Set(detected))
  recipeForm.has_unmapped = unmapped
}

const onIngredientNameChange = () => {
  updateAllergenPreview()
}

const suggestRecipe = async () => {
  if (!recipeDish.value) return
  isSuggestingRecipe.value = true
  try {
    const res = await fetch('/api/vendor/dish/recipe/suggest', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        dish_name: recipeDish.value.name,
        menu_item_id: recipeDish.value.id
      })
    })

    const data = await res.json()

    if (res.status === 403) {
      showToast(data.error || 'AI Recipe Generation is currently disabled by Administrator.', 'error')
      return
    }

    if (!res.ok || data.fallback || !data.data) {
      showToast(data.error || 'AI suggestion unavailable. Please enter ingredients manually.', 'error')
      return
    }

    const suggestion = data.data
    recipeForm.yield_servings = suggestion.yield_servings || 10
    recipeForm.instructions = suggestion.instructions || recipeForm.instructions
    recipeForm.ingredients = (suggestion.ingredients || []).map(i => ({
      name: i.name,
      quantity: i.quantity,
      unit: i.unit || 'g',
      is_unmapped: false
    }))
    updateAllergenPreview()
    showToast('Recipe suggested by AI! Review ingredients and click Save Recipe.', 'success')
  } catch (err) {
    showToast('AI suggestion unavailable. Please enter ingredients manually.', 'error')
  } finally {
    isSuggestingRecipe.value = false
  }
}

const saveRecipe = async () => {
  if (!recipeDish.value) return
  if (recipeForm.ingredients.length === 0) {
    showToast('Please add at least one ingredient.', 'error')
    return
  }

  isSavingRecipe.value = true
  try {
    const payload = {
      menu_item_id: recipeDish.value.id,
      yield_servings: recipeForm.yield_servings,
      instructions: recipeForm.instructions,
      ingredients: recipeForm.ingredients
    }
    const res = await fetch('/api/vendor/dish/recipe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    const data = await res.json()
    if (!res.ok) {
      showToast(data.error || 'Failed to save recipe.', 'error')
      return
    }

    showToast('Recipe saved successfully!', 'success')
    recipeForm.allergens = data.allergens_preview || []
    recipeForm.has_unmapped = data.has_unmapped || false
    closeRecipeModal()
    fetchPrepPlan()
  } catch (err) {
    showToast(`Network error: ${err.message}`, 'error')
  } finally {
    isSavingRecipe.value = false
  }
}

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
  aiDisabled.value = false
  aiDisabledMessage.value = ''
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
  if (!formData.name || !formData.name.trim()) {
    showToast('Please enter a dish name.', 'error')
    return
  }

  if (formData.base_price === null || formData.base_price === '' || isNaN(formData.base_price) || Number(formData.base_price) <= 0) {
    showToast('Please enter a valid base price.', 'error')
    return
  }

  if (formData.macros.calories !== null && formData.macros.calories !== '' && !formData.ai_confirmed) {
    showToast('Please review and confirm the ingredients and macros before saving.', 'error')
    return
  }

  const payload = {
    id: editItem.value ? editItem.value.id : null,
    name: formData.name.trim(),
    description: formData.description ? formData.description.trim() : '',
    category: formData.category,
    base_price: Number(formData.base_price),
    ingredientsString: formData.ingredientsString,
    macros: formData.macros,
    ai_confirmed: formData.ai_confirmed,
    portion: formData.portion
  }

  let finalAllergens = [...formData.allergens]
  let returnedId = null

  try {
    const res = await fetch('/api/vendor/dish', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    
    let data = {}
    try {
      data = await res.json()
    } catch (parseErr) {
      // Non-JSON response
    }

    if (!res.ok) {
      const errorMsg = data.error || `Server error (${res.status}): Failed to save dish.`
      showToast(errorMsg, 'error')
      return
    }

    if (!data.id && !editItem.value) {
      showToast('Server did not return a valid dish ID.', 'error')
      return
    }

    returnedId = data.id

    if (data.mapped_allergens && Array.isArray(data.mapped_allergens) && data.mapped_allergens.length > 0) {
      finalAllergens = [...new Set([...finalAllergens, ...data.mapped_allergens])]
    }
  } catch (err) {
    showToast(`Network error: ${err.message}`, 'error')
    return
  }

  const ingredients = formData.ingredientsString.split(',').map(s => s.trim()).filter(Boolean)
  const dishData = {
    name: formData.name.trim(),
    description: formData.description ? formData.description.trim() : '',
    category: formData.category,
    base_price: Number(formData.base_price),
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
    const rosterIdx = activeRoster.value.findIndex(d => d.id === editItem.value.id)
    if (rosterIdx !== -1) activeRoster.value[rosterIdx] = { ...activeRoster.value[rosterIdx], ...dishData }
  } else {
    const idToUse = returnedId
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
  showToast(editItem.value ? 'Dish updated successfully!' : 'Dish added successfully!', 'success')
}

const launchMenu = async () => {
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

  try {
    const res = await fetch('/api/vendor/menu/launch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        store_id: 1,
        cooked_at: batchCookedAt.value,
        sell_by: batchSellBy.value,
        items: selectedItems.map(item => ({
          menu_item_id: item.id,
          stock: item.stock,
          cooked_at: item.cooked_at || batchCookedAt.value,
          sell_by: item.sell_by || batchSellBy.value
        }))
      })
    })
    if (!res.ok) {
      const data = await res.json()
      console.warn('Backend launch menu failed:', data.error)
    }
  } catch (err) {
    console.warn('Backend launch menu error:', err)
  }

  showToast("Today's lineup has been published successfully.", "success")
  launchState.value = 'success'
  setTimeout(() => {
    launchState.value = 'idle'
  }, 2000)
}
</script>
