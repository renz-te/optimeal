<template>
  <div class="p-8 pb-32 font-sans antialiased text-slate-900 max-w-6xl mx-auto h-full flex flex-col">
    <div class="flex justify-between items-center mb-8 flex-shrink-0">
      <div>
        <h2 class="text-3xl font-black text-slate-900 tracking-tight">Launch Daily Lineup</h2>
        <p class="text-slate-500 font-medium mt-1">Select dishes from the master catalog, set stock and pricing, and publish today's menu.</p>
      </div>
      <div class="flex items-center gap-3">
        <button 
          @click="openReheatStationModal" 
          class="flex items-center space-x-2 bg-amber-50 text-amber-800 border border-amber-300 px-4 py-2.5 rounded-lg font-bold hover:bg-amber-100 transition-colors shadow-xs"
        >
          <Flame :size="18" class="text-amber-600" />
          <span>Reheat Station ({{ carriedBatches.length }})</span>
        </button>
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
                <div class="flex items-center justify-between gap-3">
                  <div class="font-bold text-slate-900">{{ item.name }}</div>
                  <button 
                    @click.stop="openAddFreshModal(item)" 
                    type="button" 
                    title="Add a new fresh batch"
                    class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 transition-colors shrink-0"
                  >
                    <Plus :size="13" /> Add Fresh Batch
                  </button>
                </div>
                <div class="text-[11px] text-slate-500 truncate max-w-[200px]" :title="item.ingredients.join(', ')">{{ item.ingredients.join(', ') }}</div>

                <!-- Active Reservations & Compact Slot Summary (R2) -->
                <div v-if="(item.reserved_qty || 0) > 0" class="mt-1.5 space-y-1">
                  <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                      Reserved: {{ item.reserved_qty }}
                    </span>
                    <span v-if="item.slots_summary" class="text-[11px] font-medium text-slate-500">
                      ({{ item.slots_summary }})
                    </span>
                  </div>
                  <!-- Exceed plan warning verbatim -->
                  <div v-if="(item.reserved_qty || 0) > (item.stock || 0)" class="text-xs font-bold text-rose-700 bg-rose-50 border border-rose-200 rounded px-2 py-1 flex items-center gap-1.5">
                    <AlertTriangle class="w-3.5 h-3.5 text-rose-600 flex-shrink-0" />
                    <span>Reservations exceed plan: cooking {{ item.reserved_qty }} servings</span>
                  </div>
                </div>

                <!-- Nested Active Batches List (Phase B4.9) -->
                <div v-if="item.batches && item.batches.length > 0" class="mt-3 pt-2 border-t border-slate-100 space-y-1.5">
                  <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 flex items-center justify-between">
                    <span>Active Batches ({{ item.batches.length }})</span>
                    <span class="text-[10px] font-medium text-slate-400">Total remaining: {{ item.batches.reduce((sum, b) => sum + Number(b.remaining_stock), 0) }}</span>
                  </div>
                  <div 
                    v-for="b in item.batches" 
                    :key="b.id" 
                    class="flex items-center justify-between text-xs bg-slate-50 border border-slate-200/80 rounded-lg px-2.5 py-1.5 gap-2"
                  >
                    <div class="flex items-center gap-2">
                      <span 
                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-black uppercase tracking-wide"
                        :class="b.state === 'fresh' ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-amber-100 text-amber-800 border border-amber-300'"
                      >
                        {{ b.state === 'fresh' ? 'Fresh' : 'Reheated' }}
                      </span>
                      <span class="text-[11px] font-medium text-slate-600">
                        {{ b.state === 'fresh' ? 'Cooked: ' + formatBatchTime(b.cooked_at) : 'Reheated: ' + formatBatchTime(b.reheated_at) }}
                      </span>
                    </div>
                    
                    <div class="flex items-center gap-2">
                      <span 
                        class="text-[11px] font-semibold flex items-center gap-1"
                        :class="getSafeTimeInfo(b.sell_by).isExpired ? 'text-rose-600 font-bold' : 'text-slate-600'"
                      >
                        <Clock :size="12" class="text-slate-400" />
                        {{ getSafeTimeInfo(b.sell_by).label }}
                      </span>
                      <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-white text-slate-800 border border-slate-200">
                        {{ b.remaining_stock }} / {{ b.total_stock }}
                      </span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                  {{ item.category }}
                </span>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                  <input type="number" v-model.number="item.stock" min="1" :disabled="!item.selected" class="w-20 px-2 py-1.5 bg-white border border-slate-300 rounded font-bold text-slate-900 disabled:opacity-50 disabled:bg-slate-100 outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                  <span v-if="(item.reserved_qty || 0) > 0" class="text-xs font-semibold text-slate-500" :title="'Reserved: ' + item.reserved_qty">
                    / {{ item.reserved_qty }} res
                  </span>
                </div>
                <div v-if="(item.reserved_qty || 0) > (item.stock || 0)" class="text-[11px] font-bold text-rose-600 mt-1">
                  Cooking {{ item.reserved_qty }}
                </div>
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
          <span class="font-bold text-amber-900 flex items-center gap-1.5">
            <Shield class="w-4 h-4 text-emerald-600 inline" /> Food Safety:
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

      <button @click="launchMenu" :disabled="!activeRoster.some(r => r.selected) || launchState === 'success'" class="px-8 py-3 rounded-xl font-black text-lg shadow-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
              :class="launchState === 'success' ? 'bg-slate-700 text-white cursor-default' : 'bg-emerald-600 text-white hover:bg-emerald-700 hover:shadow-xl active:scale-95'">
        <Check v-if="launchState === 'success'" class="w-5 h-5" />
        <Rocket v-else class="w-5 h-5" />
        <span>{{ launchState === 'success' ? 'Lineup Active' : 'Launch Live Menu' }}</span>
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

            <!-- Dish Photo Upload & Preview -->
            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Dish Photo</label>
              <div class="flex items-center gap-4">
                <div class="w-20 h-20 rounded-xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center relative flex-shrink-0 shadow-xs">
                  <img 
                    v-if="formData.image_url" 
                    :src="getImageUrl(formData.image_url)" 
                    loading="lazy" 
                    class="w-full h-full object-cover" 
                    alt="Dish Preview" 
                  />
                  <div v-else class="flex flex-col items-center justify-center text-slate-400">
                    <Camera class="w-6 h-6 stroke-[1.5]" />
                    <span class="text-[9px] font-semibold mt-1">No Photo</span>
                  </div>
                  <div v-if="isUploadingImage" class="absolute inset-0 bg-white/80 backdrop-blur-xs flex items-center justify-center">
                    <Loader2 class="w-5 h-5 animate-spin text-emerald-600" />
                  </div>
                </div>

                <div class="flex-1 space-y-1.5">
                  <div class="flex items-center gap-2">
                    <label 
                      class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg border border-slate-300 transition-colors"
                      :class="isUploadingImage ? 'opacity-50 pointer-events-none' : ''"
                    >
                      <UploadCloud class="w-3.5 h-3.5 text-slate-500" />
                      <span>{{ formData.image_url ? 'Change Photo' : 'Upload Photo' }}</span>
                      <input 
                        type="file" 
                        ref="fileInputRef"
                        accept="image/jpeg,image/png,image/webp" 
                        class="hidden" 
                        @change="handleImageFileSelect" 
                      />
                    </label>
                    <button 
                      v-if="formData.image_url" 
                      type="button" 
                      @click="removeDishImage" 
                      class="px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 rounded-lg border border-rose-200 transition-colors flex items-center gap-1"
                    >
                      <Trash2 class="w-3.5 h-3.5" />
                      <span>Remove</span>
                    </button>
                  </div>
                  <p class="text-[11px] text-slate-400 leading-tight">
                    PNG, JPEG, or WebP. Max 2MB.
                  </p>
                  <p v-if="imageUploadError" class="text-[11px] text-rose-600 font-semibold">
                    {{ imageUploadError }}
                  </p>
                </div>
              </div>
            </div>

            <!-- AI Kill Switch Warning Banner -->
            <div v-if="aiDisabled" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-900 text-xs flex items-center gap-2 mb-2">
              <AlertTriangle class="w-4 h-4 text-amber-600 flex-shrink-0" />
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
                 <Loader2 v-if="isEnriching" class="w-4 h-4 animate-spin text-indigo-500" />
                 <template v-else>
                   <Lock v-if="aiDisabled" class="w-4 h-4" />
                   <Sparkles v-else class="w-4 h-4 text-amber-400" />
                 </template>
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
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 flex items-center justify-between">
                <span>Critical Allergens (Derived)</span>
                <span class="text-[10px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded">Read-Only</span>
              </label>
              <div v-if="formData.allergens && formData.allergens.length > 0" class="flex flex-wrap gap-1.5">
                <span v-for="tag in formData.allergens" :key="tag" 
                      class="inline-flex items-center gap-1 px-3 py-1 text-xs font-bold rounded-full bg-rose-100 text-rose-700 border border-rose-300">
                  <AlertTriangle :size="12" />
                  {{ tag }}
                </span>
              </div>
              <div v-else class="text-xs text-slate-400 italic py-1">
                No critical allergens mapped from current ingredients.
              </div>
              <p class="text-[11px] text-slate-500 mt-2 flex items-start gap-1.5 bg-slate-50 border border-slate-200 rounded-lg p-2.5">
                <Shield :size="14" class="text-slate-400 shrink-0 mt-0.5" />
                <span>Allergens are derived automatically from the ingredient list and recipe taxonomy to guarantee diner safety. To modify allergens, edit the dish ingredients or recipe items.</span>
              </p>
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
      <div v-if="showRecipeModal" class="fixed bottom-0 right-0 top-0 z-50 w-full max-w-2xl bg-white shadow-2xl flex flex-col overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-xl font-black text-slate-900">Recipe & Costing: {{ recipeDish?.name }}</h3>
              <span class="text-xs font-bold text-slate-500 bg-slate-200 px-2 py-0.5 rounded">{{ recipeDish?.category }}</span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Configure batch ingredients, quantities, unit costs, and target safe margins.</p>
          </div>
          <button @click="closeRecipeModal" class="text-slate-400 hover:text-slate-700 transition-colors"><X :size="20" /></button>
        </div>

        <div class="p-6 flex-1 overflow-y-auto space-y-5">
          <div class="flex items-center justify-between gap-4 p-4 bg-slate-50 border border-slate-200 rounded-xl">
            <div>
              <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1">Batch Yield Servings</label>
              <input type="number" v-model.number="recipeForm.yield_servings" @input="calculateFoodCost" min="1" class="w-24 px-3 py-1.5 border border-slate-300 rounded-lg font-bold text-slate-900 outline-none focus:border-emerald-500 bg-white" />
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

          <!-- Financials & Margin Engine Card -->
          <div class="p-4 bg-gradient-to-br from-slate-900 to-slate-800 text-white rounded-xl shadow-lg border border-slate-700">
            <div class="flex items-center justify-between mb-3 border-b border-slate-700/80 pb-2.5">
              <div class="flex items-center gap-2">
                <BarChart3 class="w-4 h-4 text-indigo-400 inline" />
                <h4 class="text-xs font-black uppercase tracking-wider text-slate-200">
                  Food Cost & Price Floor Engine (1.2x Floor)
                </h4>
              </div>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                1.2x Cost Protection
              </span>
            </div>

            <div class="grid grid-cols-3 gap-3 mb-4">
              <div class="bg-white/5 p-3 rounded-lg border border-white/10">
                <div class="text-[10px] font-bold text-slate-400 uppercase">Batch Food Cost</div>
                <div class="text-lg font-black text-white mt-0.5">₱{{ (recipeForm.total_batch_cost || 0).toFixed(2) }}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">For {{ recipeForm.yield_servings }} portions</div>
              </div>

              <div class="bg-white/5 p-3 rounded-lg border border-white/10">
                <div class="text-[10px] font-bold text-slate-400 uppercase">Cost / Serving</div>
                <div class="text-lg font-black text-amber-300 mt-0.5">₱{{ (recipeForm.cost_per_serving || 0).toFixed(2) }}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Per-portion raw cost</div>
              </div>

              <div class="bg-emerald-500/10 p-3 rounded-lg border border-emerald-500/30">
                <div class="text-[10px] font-bold text-emerald-400 uppercase">Suggested floor (cost x 1.2)</div>
                <div class="text-xl font-black text-emerald-400 mt-0.5">₱{{ (recipeForm.suggested_price_floor || 0).toFixed(2) }}</div>
                <div class="text-[10px] text-emerald-300/80 mt-0.5">Cost × 1.2 (Capped at Base)</div>
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-700/80 text-xs">
              <div class="text-slate-300 flex items-center gap-2 flex-wrap">
                <span>Current Floor: <span class="font-bold text-white">₱{{ recipeDish?.price_floor || recipeDish?.base_price || 0 }}</span></span>
                <span class="text-slate-400 text-[11px]">(Base: ₱{{ recipeDish?.base_price || 0 }})</span>
                <span v-if="maxDiscountPercent > 0" class="px-2 py-0.5 rounded bg-amber-400/20 text-amber-300 text-[11px] font-bold border border-amber-400/30">
                  Max Discount: {{ maxDiscountPercent }}%
                </span>
              </div>

              <button 
                @click="applySuggestedPriceFloor" 
                :disabled="(recipeForm.suggested_price_floor || 0) <= 0 || isApplyingFloor"
                class="px-3.5 py-1.5 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-black rounded-lg transition-all shadow-md active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5"
              >
                <span>Apply Suggested Floor (₱{{ (recipeForm.suggested_price_floor || 0).toFixed(2) }})</span>
              </button>
            </div>

            <!-- Single Ingredient Cost > 40% Warning Banner -->
            <div v-if="dominantIngredients.length > 0" class="mt-3 pt-2.5 border-t border-slate-700/80">
              <div class="p-2.5 bg-amber-500/15 border border-amber-500/30 rounded-lg text-xs space-y-1">
                <div class="flex items-center gap-1.5 text-amber-300 font-bold">
                  <AlertTriangle :size="14" class="text-amber-400 shrink-0" />
                  <span>Cost Concentration Warning:</span>
                </div>
                <div class="text-amber-200/90 text-[11px] space-y-0.5">
                  <div v-for="dIng in dominantIngredients" :key="dIng.name">
                    {{ dIng.name || 'Ingredient' }} is {{ Math.round(((dIng.item_cost || 0) / (recipeForm.total_batch_cost || 1)) * 100) }}% of batch cost. Check the quantity.
                  </div>
                </div>
              </div>
            </div>
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
              <span>Some ingredients are not in the allergen list. Students with allergies will be asked to review them before ordering.</span>
            </div>
          </div>

          <!-- Ingredients List with Unit Costing -->
          <div>
            <div class="flex justify-between items-center mb-2">
              <label class="text-xs font-bold text-slate-600 uppercase tracking-wider">Recipe Ingredients & Raw Costs</label>
              <button @click="addRecipeIngredientRow" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                <Plus :size="14" />
                <span>Add Ingredient</span>
              </button>
            </div>

            <div class="space-y-2.5">
              <div v-for="(ing, idx) in recipeForm.ingredients" :key="idx" class="bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-2">
                <div class="flex items-center gap-2">
                  <input 
                    type="text" 
                    v-model="ing.name" 
                    @input="onIngredientNameChange"
                    placeholder="Ingredient name (e.g. Chicken, Garlic)" 
                    class="flex-1 px-3 py-1.5 border border-slate-300 rounded font-medium text-sm outline-none focus:border-emerald-500 bg-white" 
                  />
                  <input 
                    type="number" 
                    step="0.1" 
                    min="0.1" 
                    v-model.number="ing.quantity" 
                    @input="calculateFoodCost"
                    class="w-20 px-2 py-1.5 border border-slate-300 rounded font-bold text-sm outline-none focus:border-emerald-500 bg-white" 
                  />
                  <select 
                    v-model="ing.unit" 
                    @change="calculateFoodCost"
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
                  <button @click="removeRecipeIngredientRow(idx)" class="text-slate-400 hover:text-rose-600 p-1.5 transition-colors">
                    <Trash2 :size="16" />
                  </button>
                </div>

                <!-- Catalog Price & Portioned Food Cost -->
                <div class="flex items-center justify-between gap-3 pt-1 border-t border-slate-200/60 text-xs">
                  <div class="flex items-center gap-2">
                    <span class="text-slate-400 font-bold">Catalog Price:</span>
                    <span v-if="(ing.unit_cost || 0) > 0" class="font-semibold text-slate-800">
                      ₱{{ Number(ing.unit_cost || 0).toFixed(2) }} / {{ ing.cost_unit || 'kg' }}
                    </span>
                    <span v-else class="text-amber-600 font-medium italic">
                      No price set
                    </span>
                    <router-link 
                      to="/vendor/prices" 
                      target="_blank" 
                      class="text-emerald-600 hover:text-emerald-700 underline font-semibold ml-1.5 inline-flex items-center gap-0.5"
                    >
                      Edit Price
                    </router-link>
                  </div>
                  <div class="text-right">
                    <span class="text-slate-400 text-[11px] mr-1">Portion Cost:</span>
                    <span class="font-black text-slate-900">₱{{ (ing.item_cost || 0).toFixed(2) }}</span>
                  </div>
                </div>
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
              <span class="text-xs font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">
                {{ prepPlanData.total_cook_servings ?? prepPlanData.total_servings ?? 0 }} portions to cook
              </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">Aggregated raw inventory requirements scaled for today's selected lineup.</p>
          </div>
          <button @click="showPrepModal = false" class="text-slate-400 hover:text-slate-700 transition-colors"><X :size="20" /></button>
        </div>

        <div class="p-6 flex-1 overflow-y-auto space-y-6">
          <div class="grid grid-cols-4 gap-3">
            <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200">
              <div class="text-[11px] font-bold text-slate-400 uppercase">Selected Viands</div>
              <div class="text-xl font-black text-slate-900 mt-1">{{ prepPlanData.total_dishes || 0 }} dishes</div>
            </div>
            <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200">
              <div class="text-[11px] font-bold text-slate-500 uppercase">Planned Servings</div>
              <div class="text-xl font-black text-slate-700 mt-1">{{ prepPlanData.total_planned_servings ?? prepPlanData.total_servings ?? 0 }} portions</div>
            </div>
            <div class="bg-emerald-50/50 p-3.5 rounded-xl border border-emerald-200">
              <div class="text-[11px] font-bold text-emerald-600 uppercase">Cook Servings</div>
              <div class="text-xl font-black text-emerald-800 mt-1">{{ prepPlanData.total_cook_servings ?? prepPlanData.total_servings ?? 0 }} portions</div>
            </div>
            <div class="bg-indigo-50/50 p-3.5 rounded-xl border border-indigo-200">
              <div class="text-[11px] font-bold text-indigo-600 uppercase">Total Ingredients</div>
              <div class="text-xl font-black text-indigo-800 mt-1">{{ prepPlanData.prep_list?.length || 0 }} items</div>
            </div>
          </div>

          <!-- Dishes with Reservations Exceeding Plan Warning Banner (R2 & R3) -->
          <div v-if="dishesExceedingPlan.length > 0" class="p-4 bg-rose-50 border border-rose-200 rounded-xl space-y-2">
            <div class="flex items-center gap-2 text-xs font-bold text-rose-800 uppercase tracking-wide">
              <AlertTriangle class="w-4 h-4 text-rose-600 flex-shrink-0" />
              <span>Reservations Exceed Planned Servings</span>
            </div>
            <div class="space-y-1.5">
              <div v-for="dish in dishesExceedingPlan" :key="dish.menu_item_id" class="flex items-center justify-between text-xs text-rose-900 bg-white/80 px-3 py-2 rounded-lg border border-rose-200/60">
                <span class="font-bold">{{ dish.name }}</span>
                <span class="font-bold text-rose-700 flex items-center gap-1.5">
                  <AlertTriangle class="w-3.5 h-3.5 text-rose-600 flex-shrink-0" />
                  Reservations exceed plan: cooking {{ dish.cook_servings }} servings
                </span>
              </div>
            </div>
          </div>

          <!-- Dishes without Recipe Warning Banner -->
          <div v-if="prepPlanData.needs_recipe && prepPlanData.needs_recipe.length > 0" class="p-4 bg-amber-50 border border-amber-200 rounded-xl space-y-2">
            <div class="flex items-center gap-2 text-xs font-bold text-amber-800 uppercase tracking-wide">
              <AlertTriangle class="w-4 h-4 text-amber-600 flex-shrink-0" />
              <span>Dishes Requiring Recipes</span>
            </div>
            <div class="space-y-1.5">
              <div v-for="dish in prepPlanData.needs_recipe" :key="dish.menu_item_id" class="flex items-center justify-between text-xs text-amber-900 bg-white/70 px-3 py-2 rounded-lg border border-amber-200/60">
                <span class="font-bold">{{ dish.name }}</span>
                <span class="font-semibold text-amber-700 flex items-center gap-1.5">
                  <AlertTriangle class="w-3.5 h-3.5 text-amber-600 flex-shrink-0" />
                  No recipe - not included in totals
                </span>
              </div>
            </div>
          </div>

          <div class="border border-slate-200 rounded-xl overflow-hidden">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-wider">
                <tr>
                  <th class="px-4 py-3">Ingredient</th>
                  <th class="px-4 py-3 text-right">Required Quantity</th>
                  <th class="px-4 py-3 text-right">Est. Cost</th>
                  <th class="px-4 py-3">Used In Dishes</th>
                  <th class="px-4 py-3 text-center">Taxonomy</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="!prepPlanData.prep_list || prepPlanData.prep_list.length === 0">
                  <td colspan="5" class="text-center py-8 text-slate-400 font-medium">
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
                  <td class="px-4 py-3 text-right font-mono">
                    <span v-if="item.has_price && item.estimated_cost !== null" class="font-bold text-slate-800">
                      ₱{{ Number(item.estimated_cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                    </span>
                    <span v-else class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                      <AlertTriangle :size="10" />
                      Missing price
                    </span>
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

        <div class="p-6 border-t border-slate-200 bg-slate-50 flex flex-col sm:flex-row items-center justify-between gap-4">
          <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 w-full sm:w-auto">
            <div class="flex items-baseline gap-2">
              <span class="text-xs font-bold uppercase tracking-wider text-slate-500">
                {{ prepPlanData.is_partial ? 'Partial Total:' : 'Est. Grand Total:' }}
              </span>
              <span class="text-2xl font-black text-slate-900 font-mono">
                ₱{{ Number(prepPlanData.grand_total || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
              </span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-xs font-medium text-slate-500">
                ({{ prepPlanData.coverage_label || '0 of 0 ingredients costed' }})
              </span>
              <span v-if="prepPlanData.is_partial" class="inline-flex items-center gap-1 text-xs font-bold text-amber-800 bg-amber-100 border border-amber-300 px-2.5 py-0.5 rounded-full">
                <AlertTriangle class="w-3.5 h-3.5 text-amber-600 flex-shrink-0" />
                Partial Total (Missing Prices)
              </span>
              <span v-else-if="prepPlanData.prep_list && prepPlanData.prep_list.length > 0" class="inline-flex items-center gap-1 text-xs font-bold text-emerald-800 bg-emerald-100 border border-emerald-300 px-2.5 py-0.5 rounded-full">
                <Check class="w-3.5 h-3.5 text-emerald-600 flex-shrink-0" />
                Fully Costed
              </span>
            </div>
          </div>
          <button @click="showPrepModal = false" class="w-full sm:w-auto px-5 py-2.5 bg-slate-900 text-white font-bold rounded-lg shadow hover:bg-slate-800 transition-colors">
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
    <!-- Add Fresh Batch Modal -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showAddFreshModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click="closeAddFreshModal">
        <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl overflow-hidden border border-slate-100" @click.stop>
          <div class="p-6 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
                <Plus :size="18" />
              </div>
              <div>
                <h3 class="text-lg font-black text-slate-900">Add Fresh Batch</h3>
                <p class="text-xs text-slate-500">{{ freshBatchDish?.name }}</p>
              </div>
            </div>
            <button @click="closeAddFreshModal" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100">
              <X :size="18" />
            </button>
          </div>

          <div class="p-6 space-y-4">
            <div class="bg-emerald-50/70 border border-emerald-200 rounded-xl p-3.5 text-xs text-emerald-900 space-y-1">
              <div class="font-bold flex items-center gap-1.5">
                <Shield :size="14" class="text-emerald-600" />
                Food Safety Parameters
              </div>
              <p class="text-[11px] text-emerald-800 leading-relaxed">
                Fresh batches have a safe holding window of {{ freshBatchDish?.hold_hours || 6 }} hours. Time-based dynamic pricing decay will activate automatically as sell-by cutoff approaches.
              </p>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                Servings / Quantity
              </label>
              <input 
                type="number" 
                v-model.number="freshBatchQty" 
                min="1" 
                max="500" 
                class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl font-bold text-slate-900 text-lg outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
              />
            </div>
          </div>

          <div class="p-6 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-3">
            <button 
              type="button" 
              @click="closeAddFreshModal" 
              class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-200 rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button 
              type="button" 
              @click="submitFreshBatch" 
              :disabled="isSubmittingBatch || freshBatchQty <= 0" 
              class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-md disabled:opacity-50 flex items-center gap-1.5 transition-all"
            >
              <Loader2 v-if="isSubmittingBatch" :size="14" class="animate-spin" />
              <span>Confirm & Add Batch</span>
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- Carried-Over Stock / Reheat Station Modal -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showReheatModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click="closeReheatStationModal">
        <div class="bg-white rounded-2xl max-w-2xl w-full shadow-2xl overflow-hidden border border-slate-100 max-h-[90vh] flex flex-col" @click.stop>
          <div class="p-6 bg-amber-50/80 border-b border-amber-200 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
              <div class="w-9 h-9 rounded-xl bg-amber-200 text-amber-800 flex items-center justify-center font-bold">
                <Flame :size="20" />
              </div>
              <div>
                <h3 class="text-xl font-black text-slate-900">Carried-Over Stock & Reheat Station</h3>
                <p class="text-xs text-amber-900 font-medium">Reheat carried stock for today with safe 3h hold & automatic 50% discount</p>
              </div>
            </div>
            <button @click="closeReheatStationModal" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-white/60">
              <X :size="20" />
            </button>
          </div>

          <div class="p-6 overflow-y-auto flex-1 space-y-4">
            <div v-if="carriedBatches.length === 0" class="text-center py-12 text-slate-400">
              <Flame :size="40" class="mx-auto mb-2 opacity-30 text-amber-600" />
              <p class="text-sm font-semibold text-slate-600">No carried-over stock available</p>
              <p class="text-xs text-slate-400 mt-1">Dishes saved overnight in cold storage will show up here.</p>
            </div>

            <div v-for="cb in carriedBatches" :key="cb.id" class="p-4 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <h4 class="font-bold text-slate-900 text-sm truncate">{{ cb.dish_name }}</h4>
                  <span 
                    v-if="cb.reheatable !== 0 && cb.reheatable !== false" 
                    class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300"
                  >
                    Reheatable
                  </span>
                  <span 
                    v-else 
                    class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300"
                  >
                    Non-Reheatable
                  </span>
                </div>
                <div class="text-xs text-slate-500 mt-1 flex items-center gap-3">
                  <span>Batch #{{ cb.id }}</span>
                  <span>•</span>
                  <span>Carried Stock: <strong class="text-slate-800">{{ cb.remaining_stock }}</strong> servings</span>
                  <span>•</span>
                  <span>Reheat window: <strong class="text-slate-800">{{ cb.reheat_hours || 3 }}h</strong></span>
                </div>
              </div>

              <div>
                <button 
                  v-if="cb.reheatable !== 0 && cb.reheatable !== false" 
                  @click="openReheatForm(cb)"
                  type="button" 
                  class="px-3.5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-bold shadow-xs flex items-center gap-1.5 transition-colors"
                >
                  <Flame :size="14" />
                  <span>Reheat</span>
                </button>
                <span v-else class="text-xs font-bold text-slate-400 italic">
                  Cannot Reheat
                </span>
              </div>
            </div>

            <!-- Inline / Sub-Modal Reheat Confirmation Form -->
            <div v-if="selectedBatchForReheat" class="p-5 bg-amber-50/60 border-2 border-amber-300 rounded-xl space-y-3 mt-4">
              <div class="flex items-center justify-between">
                <h5 class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                  <Flame :size="16" class="text-amber-600" />
                  Reheat Batch: {{ selectedBatchForReheat.dish_name }}
                </h5>
                <button @click="selectedBatchForReheat = null" class="text-slate-400 hover:text-slate-600">
                  <X :size="16" />
                </button>
              </div>

              <div class="text-xs text-slate-600">
                Available carried stock: <strong>{{ selectedBatchForReheat.remaining_stock }}</strong> portions.
                Reheated dishes will receive a <strong>{{ selectedBatchForReheat.reheat_hours || 3 }} hour</strong> safe consumption window and be presented to customers at <strong>50% OFF</strong>.
              </div>

              <div class="flex items-center gap-3 pt-1">
                <label class="text-xs font-bold text-slate-700">Portions to Reheat:</label>
                <input 
                  type="number" 
                  v-model.number="reheatQty" 
                  min="1" 
                  :max="selectedBatchForReheat.remaining_stock" 
                  class="w-24 px-3 py-1.5 bg-white border border-slate-300 rounded-lg font-bold text-slate-900 text-sm outline-none focus:border-amber-500"
                />
                <button 
                  type="button" 
                  @click="submitReheatBatch" 
                  :disabled="isSubmittingReheat || reheatQty <= 0 || reheatQty > selectedBatchForReheat.remaining_stock" 
                  class="px-4 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-lg shadow-sm disabled:opacity-50 flex items-center gap-1 transition-colors"
                >
                  <Loader2 v-if="isSubmittingReheat" :size="12" class="animate-spin" />
                  <span>Confirm Reheat</span>
                </button>
              </div>
            </div>
          </div>

          <div class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end">
            <button @click="closeReheatStationModal" class="px-5 py-2 bg-slate-900 text-white text-xs font-bold rounded-lg hover:bg-slate-800 transition-colors">
              Close Station
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive, watch, computed } from 'vue'
import { Plus, Edit2, X, Utensils, ClipboardList, Sparkles, Trash2, AlertTriangle, Check, Shield, Rocket, Lock, BarChart3, Loader2, Camera, UploadCloud, Flame, Clock } from 'lucide-vue-next'
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

// Phase B4.9 Batch Engine & Reheat Station State
const allVendorBatches = ref([])
const showAddFreshModal = ref(false)
const freshBatchDish = ref(null)
const freshBatchQty = ref(15)
const isSubmittingBatch = ref(false)

const showReheatModal = ref(false)
const selectedBatchForReheat = ref(null)
const reheatQty = ref(5)
const isSubmittingReheat = ref(false)

const carriedBatches = computed(() => {
  return allVendorBatches.value.filter(b => b.state === 'carried' && Number(b.remaining_stock) > 0)
})

// Phase D2: Recipe & Kitchen Prep State
const batchPlannedServings = ref(30)
const showPrepModal = ref(false)
const showRecipeModal = ref(false)
const recipeDish = ref(null)
const isSuggestingRecipe = ref(false)
const isSavingRecipe = ref(false)
const prepPlanData = ref({ total_dishes: 0, total_servings: 0, total_planned_servings: 0, total_cook_servings: 0, dishes: [], prep_list: [], grand_total: 0, is_partial: false, coverage_label: '0 of 0 ingredients costed', costed_count: 0 })
let debounceTimer = null

const dishesExceedingPlan = computed(() => {
  const result = []
  if (prepPlanData.value.dishes) {
    prepPlanData.value.dishes.forEach(d => {
      if ((Number(d.reserved_qty) || 0) > (Number(d.planned_servings) || 0)) {
        result.push(d)
      }
    })
  }
  if (prepPlanData.value.needs_recipe) {
    prepPlanData.value.needs_recipe.forEach(d => {
      if ((Number(d.reserved_qty) || 0) > (Number(d.planned_servings) || 0)) {
        result.push(d)
      }
    })
  }
  return result
})

const isApplyingFloor = ref(false)
const recipeForm = reactive({
  yield_servings: 10,
  instructions: '',
  ingredients: [],
  allergens: [],
  sensitivities: [],
  has_unmapped: false,
  total_batch_cost: 0,
  cost_per_serving: 0,
  suggested_price_floor: 0
})

const maxDiscountPercent = computed(() => {
  const base = recipeDish.value?.base_price || 0
  const floor = recipeForm.suggested_price_floor || 0
  if (base <= 0 || floor >= base) return 0
  return Math.max(0, Math.round(((base - floor) / base) * 100))
})

const dominantIngredients = computed(() => {
  const total = recipeForm.total_batch_cost || 0
  if (total <= 0) return []
  return recipeForm.ingredients.filter(ing => (ing.item_cost || 0) > (0.40 * total))
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
  isAllergenFree: false,
  image_url: ''
})

const fileInputRef = ref(null)
const isUploadingImage = ref(false)
const imageUploadError = ref('')

const getImageUrl = (url) => {
  if (!url) return ''
  if (url.startsWith('http') || url.startsWith('/')) return url
  return '/' + url
}

const removeDishImage = () => {
  formData.image_url = ''
  imageUploadError.value = ''
  if (fileInputRef.value) fileInputRef.value.value = ''
}

const handleImageFileSelect = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return

  imageUploadError.value = ''

  if (file.size > 2 * 1024 * 1024) {
    imageUploadError.value = 'File size exceeds 2MB limit.'
    if (fileInputRef.value) fileInputRef.value.value = ''
    return
  }

  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
  if (!allowedTypes.includes(file.type)) {
    imageUploadError.value = 'Only JPEG, PNG, or WebP images are allowed.'
    if (fileInputRef.value) fileInputRef.value.value = ''
    return
  }

  isUploadingImage.value = true
  try {
    const uploadData = new FormData()
    uploadData.append('image', file)

    const res = await fetch('/api/vendor/dish/image', {
      method: 'POST',
      body: uploadData
    })
    const data = await res.json()
    if (!res.ok) {
      imageUploadError.value = data.error || 'Failed to upload image.'
    } else {
      formData.image_url = data.image_url
    }
  } catch (err) {
    imageUploadError.value = 'Network error while uploading image.'
  } finally {
    isUploadingImage.value = false
    if (fileInputRef.value) fileInputRef.value.value = ''
  }
}

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

const fetchBatches = async () => {
  try {
    const res = await fetch('/api/vendor/batches')
    if (res.ok) {
      allVendorBatches.value = await res.json()
    }
  } catch (err) {
    console.error('Failed to load vendor batches:', err)
  }
}

const openAddFreshModal = (dish) => {
  freshBatchDish.value = dish
  freshBatchQty.value = 15
  showAddFreshModal.value = true
}

const closeAddFreshModal = () => {
  showAddFreshModal.value = false
  freshBatchDish.value = null
}

const submitFreshBatch = async () => {
  if (!freshBatchDish.value || freshBatchQty.value <= 0) return
  isSubmittingBatch.value = true
  try {
    const res = await fetch('/api/vendor/batches', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        menu_item_id: freshBatchDish.value.id,
        state: 'fresh',
        quantity: freshBatchQty.value
      })
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      showToast(data.error || 'Failed to add fresh batch', 'error')
      return
    }
    showToast('Fresh batch added successfully!', 'success')
    closeAddFreshModal()
    await Promise.all([fetchVendorCatalog(), fetchBatches()])
  } catch (err) {
    showToast('Network error while adding fresh batch', 'error')
  } finally {
    isSubmittingBatch.value = false
  }
}

const openReheatStationModal = () => {
  selectedBatchForReheat.value = null
  showReheatModal.value = true
  fetchBatches()
}

const closeReheatStationModal = () => {
  showReheatModal.value = false
  selectedBatchForReheat.value = null
}

const openReheatForm = (batch) => {
  selectedBatchForReheat.value = batch
  reheatQty.value = Math.min(Number(batch.remaining_stock), 10)
}

const submitReheatBatch = async () => {
  if (!selectedBatchForReheat.value || reheatQty.value <= 0) return
  isSubmittingReheat.value = true
  try {
    const res = await fetch('/api/vendor/batches', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        menu_item_id: selectedBatchForReheat.value.menu_item_id,
        parent_batch_id: selectedBatchForReheat.value.id,
        state: 'reheated',
        quantity: reheatQty.value
      })
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      showToast(data.error || 'Failed to reheat batch', 'error')
      return
    }
    showToast('Reheated batch created successfully with 50% discount active!', 'success')
    selectedBatchForReheat.value = null
    await Promise.all([fetchVendorCatalog(), fetchBatches()])
  } catch (err) {
    showToast('Network error while reheating batch', 'error')
  } finally {
    isSubmittingReheat.value = false
  }
}

const formatBatchTime = (datetimeStr) => {
  if (!datetimeStr) return 'N/A'
  try {
    const d = new Date(datetimeStr.replace(' ', 'T'))
    if (isNaN(d.getTime())) return datetimeStr
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true })
  } catch (e) {
    return datetimeStr
  }
}

const getSafeTimeInfo = (sellByStr) => {
  if (!sellByStr) return { isExpired: false, label: 'Safe' }
  try {
    const sellByDate = new Date(sellByStr.replace(' ', 'T'))
    const now = new Date()
    const diff = sellByDate.getTime() - now.getTime()
    if (diff <= 0) {
      return { isExpired: true, label: 'Expired' }
    }
    const totalMinutes = Math.floor(diff / 60000)
    const hours = Math.floor(totalMinutes / 60)
    const minutes = totalMinutes % 60
    if (hours > 0) {
      return { isExpired: false, label: `${hours}h ${minutes}m safe` }
    }
    return { isExpired: false, label: `${minutes}m safe` }
  } catch (e) {
    return { isExpired: false, label: 'Safe' }
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
      reserved_qty: Number(dish.reserved_qty) || 0,
      pickup_slots: dish.pickup_slots || [],
      slots_summary: dish.slots_summary || '',
      batches: dish.batches || []
    }
  })
  fetchPrepPlan()
}

onMounted(() => {
  fetchVendorCatalog()
  fetchBatches()
})

watch(masterCatalog, () => {
  initRoster()
}, { deep: true })

// Watch roster selections to update prep plan
watch(() => activeRoster.value.map(r => r.selected), () => {
  fetchPrepPlan()
}, { deep: true })

// Watch stock updates to debounce prep plan recalculation
watch(() => activeRoster.value.map(r => r.stock), () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    fetchPrepPlan()
  }, 350)
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
    prepPlanData.value = { total_dishes: 0, total_servings: 0, total_planned_servings: 0, total_cook_servings: 0, dishes: [], prep_list: [], grand_total: 0, is_partial: false, coverage_label: '0 of 0 ingredients costed', costed_count: 0 }
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
      // Synchronize latest reservation counts and summaries onto activeRoster
      const dishInfoMap = {}
      if (prepPlanData.value.dishes) {
        prepPlanData.value.dishes.forEach(d => { dishInfoMap[d.menu_item_id] = d })
      }
      if (prepPlanData.value.needs_recipe) {
        prepPlanData.value.needs_recipe.forEach(d => { dishInfoMap[d.menu_item_id] = d })
      }
      activeRoster.value.forEach(item => {
        if (dishInfoMap[item.id]) {
          item.reserved_qty = Number(dishInfoMap[item.id].reserved_qty) || 0
          item.pickup_slots = dishInfoMap[item.id].pickup_slots || []
          item.slots_summary = dishInfoMap[item.id].slots_summary || ''
        }
      })
    }
  } catch (err) {
    console.error('Failed to fetch prep plan', err)
  }
}

const openPrepListModal = () => {
  fetchPrepPlan()
  showPrepModal.value = true
}

const calculateIngredientItemCost = (qty, unit, cost, costUnit) => {
  if (!cost || cost <= 0 || !qty || qty <= 0) return 0
  const rUnit = (unit || '').toLowerCase().trim()
  const cUnit = (costUnit || '').toLowerCase().trim()

  if (rUnit === cUnit) {
    return qty * cost
  }

  // Mass
  if (['g', 'gram', 'grams'].includes(rUnit) && ['kg', 'kilo', 'kilogram'].includes(cUnit)) {
    return (qty / 1000) * cost
  }
  if (['kg', 'kilo', 'kilogram'].includes(rUnit) && ['g', 'gram', 'grams'].includes(cUnit)) {
    return (qty * 1000) * cost
  }

  // Volume
  if (['ml', 'milliliter'].includes(rUnit) && ['l', 'liter', 'liters'].includes(cUnit)) {
    return (qty / 1000) * cost
  }
  if (['l', 'liter', 'liters'].includes(rUnit) && ['ml', 'milliliter'].includes(cUnit)) {
    return (qty * 1000) * cost
  }

  // Count
  if (['pc', 'pcs', 'piece'].includes(rUnit) && ['pc', 'pcs', 'piece'].includes(cUnit)) {
    return qty * cost
  }

  return 0
}

const calculateFoodCost = () => {
  let totalBatch = 0
  recipeForm.ingredients.forEach(ing => {
    const cost = calculateIngredientItemCost(
      ing.quantity,
      ing.unit,
      ing.unit_cost,
      ing.cost_unit
    )
    ing.item_cost = Math.round(cost * 100) / 100
    totalBatch += cost
  })

  recipeForm.total_batch_cost = Math.round(totalBatch * 100) / 100
  const servings = Math.max(1, recipeForm.yield_servings || 1)
  const perServing = totalBatch / servings
  recipeForm.cost_per_serving = Math.round(perServing * 100) / 100
  const rawFloor = Math.round((perServing * 1.2) * 100) / 100
  const basePrice = recipeDish.value?.base_price ?? Infinity
  recipeForm.suggested_price_floor = Math.min(basePrice, rawFloor)
}

const vendorPriceCatalog = ref([])
const fetchVendorPrices = async () => {
  try {
    const res = await fetch('/api/vendor/ingredient-costs')
    if (res.ok) {
      vendorPriceCatalog.value = await res.json()
    }
  } catch (err) {
    console.error('Failed to load ingredient price catalog', err)
  }
}

const openRecipeModal = async (item) => {
  recipeDish.value = item
  recipeForm.yield_servings = 10
  recipeForm.instructions = ''
  recipeForm.ingredients = []
  recipeForm.allergens = []
  recipeForm.sensitivities = []
  recipeForm.has_unmapped = false
  recipeForm.total_batch_cost = 0
  recipeForm.cost_per_serving = 0
  recipeForm.suggested_price_floor = 0
  showRecipeModal.value = true

  try {
    await fetchVendorPrices()
    const res = await fetch(`/api/vendor/dish/recipe?menu_item_id=${item.id}`)
    if (res.ok) {
      const data = await res.json()
      recipeForm.yield_servings = data.yield_servings || 10
      recipeForm.instructions = data.instructions || ''
      recipeForm.total_batch_cost = data.total_batch_cost || 0
      recipeForm.cost_per_serving = data.cost_per_serving || 0
      recipeForm.suggested_price_floor = data.suggested_price_floor || 0
      recipeForm.ingredients = (data.ingredients || []).map(i => ({
        name: i.name,
        quantity: i.quantity,
        unit: i.unit,
        unit_cost: i.unit_cost || 0,
        cost_unit: i.cost_unit || (['g', 'kg'].includes((i.unit || '').toLowerCase()) ? 'kg' : (['ml', 'l'].includes((i.unit || '').toLowerCase()) ? 'l' : i.unit)),
        item_cost: i.item_cost || 0,
        is_unmapped: i.is_unmapped
      }))
      recipeForm.allergens = data.allergens || []
      recipeForm.sensitivities = data.sensitivities || []
      recipeForm.has_unmapped = data.has_unmapped || false
      calculateFoodCost()
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
    unit_cost: 0,
    cost_unit: 'kg',
    item_cost: 0,
    is_unmapped: false
  })
  calculateFoodCost()
}

const removeRecipeIngredientRow = (idx) => {
  recipeForm.ingredients.splice(idx, 1)
  updateAllergenPreview()
  calculateFoodCost()
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
  // Match newly typed ingredient against catalog prices
  recipeForm.ingredients.forEach(ing => {
    const name = (ing.name || '').trim().toLowerCase()
    if (name && vendorPriceCatalog.value.length > 0) {
      const match = vendorPriceCatalog.value.find(p => (p.ingredient_name || '').trim().toLowerCase() === name)
      if (match) {
        ing.unit_cost = Number(match.cost) || 0
        ing.cost_unit = match.cost_unit || ing.cost_unit || 'kg'
      }
    }
  })
  calculateFoodCost()
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
    recipeForm.ingredients = (suggestion.ingredients || []).map(i => {
      const match = (vendorPriceCatalog.value || []).find(p => (p.ingredient_name || '').trim().toLowerCase() === (i.name || '').trim().toLowerCase())
      return {
        name: i.name,
        quantity: i.quantity,
        unit: i.unit || 'g',
        unit_cost: match ? Number(match.cost) : 0,
        cost_unit: match ? match.cost_unit : (['g', 'kg'].includes((i.unit || '').toLowerCase()) ? 'kg' : (['ml', 'l'].includes((i.unit || '').toLowerCase()) ? 'l' : i.unit)),
        item_cost: 0,
        is_unmapped: false
      }
    })
    updateAllergenPreview()
    calculateFoodCost()
    showToast('Recipe suggested by AI! Review ingredients and costs.', 'success')
  } catch (err) {
    showToast('AI suggestion unavailable. Please enter ingredients manually.', 'error')
  } finally {
    isSuggestingRecipe.value = false
  }
}

const applySuggestedPriceFloor = async () => {
  if (!recipeDish.value || (recipeForm.suggested_price_floor || 0) <= 0) return
  isApplyingFloor.value = true
  try {
    const res = await fetch('/api/vendor/dish/price-floor', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        menu_item_id: recipeDish.value.id,
        price_floor: recipeForm.suggested_price_floor
      })
    })
    const data = await res.json()
    if (!res.ok) {
      showToast(data.error || 'Failed to apply price floor.', 'error')
      return
    }

    recipeDish.value.price_floor = recipeForm.suggested_price_floor
    const catItem = masterCatalog.value.find(d => d.id === recipeDish.value.id)
    if (catItem) catItem.price_floor = recipeForm.suggested_price_floor
    const rosItem = activeRoster.value.find(d => d.id === recipeDish.value.id)
    if (rosItem) rosItem.price_floor = recipeForm.suggested_price_floor

    showToast(`Price floor updated to ₱${recipeForm.suggested_price_floor.toFixed(2)} (1.2x cost floor applied)!`, 'success')
  } catch (err) {
    showToast(`Failed to update price floor: ${err.message}`, 'error')
  } finally {
    isApplyingFloor.value = false
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
      confirmed: true,
      ingredients: recipeForm.ingredients.map(i => ({
        name: i.name,
        quantity: i.quantity,
        unit: i.unit,
        unit_cost: i.unit_cost || 0,
        cost_unit: i.cost_unit || 'kg'
      }))
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
  formData.image_url = item.image_url || ''
  imageUploadError.value = ''
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
  formData.image_url = ''
  imageUploadError.value = ''
  if (fileInputRef.value) fileInputRef.value.value = ''
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
    portion: formData.portion,
    image_url: formData.image_url || null
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
        store_id: masterCatalog.value[0]?.store_id || 1,
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
