<template>
  <div class="p-8 max-w-6xl mx-auto space-y-8 font-sans antialiased text-slate-900">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-5">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
          <Coins class="text-emerald-600" :size="32" />
          <span>Ingredient Price Catalog</span>
        </h1>
        <p class="text-slate-500 mt-1 font-medium">
          Manage raw purchasing costs, simulate inflation adjustments, and audit price version history.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <button
          @click="fetchData"
          :disabled="loading"
          class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 active:scale-95 transition-all shadow-sm disabled:opacity-50"
        >
          <RefreshCw :size="16" :class="{ 'animate-spin': loading }" />
          <span>Refresh</span>
        </button>

        <button
          @click="openAddModal"
          class="flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow-md hover:bg-emerald-700 active:scale-95 transition-all"
        >
          <Plus :size="16" />
          <span>Add Price</span>
        </button>
      </div>
    </div>

    <!-- Feedback Banners -->
    <div v-if="successMessage" class="p-4 rounded-xl text-sm font-semibold flex items-center justify-between bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-sm">
      <div class="flex items-center gap-2">
        <CheckCircle2 :size="18" class="text-emerald-600 shrink-0" />
        <span>{{ successMessage }}</span>
      </div>
      <button @click="successMessage = ''" class="text-emerald-600 hover:text-emerald-800">
        <X :size="16" />
      </button>
    </div>

    <div v-if="errorMessage" class="p-4 rounded-xl text-sm font-semibold flex items-center justify-between bg-rose-50 text-rose-800 border border-rose-200 shadow-sm">
      <div class="flex items-center gap-2">
        <AlertTriangle :size="18" class="text-rose-600 shrink-0" />
        <span>{{ errorMessage }}</span>
      </div>
      <button @click="errorMessage = ''" class="text-rose-600 hover:text-rose-800">
        <X :size="16" />
      </button>
    </div>

    <!-- Tab Navigation -->
    <div class="flex items-center border-b border-slate-200 space-x-8">
      <button
        @click="activeTab = 'catalog'"
        :class="[
          'pb-3 font-bold text-sm transition-colors border-b-2 flex items-center gap-2',
          activeTab === 'catalog'
            ? 'border-emerald-600 text-emerald-700'
            : 'border-transparent text-slate-500 hover:text-slate-700'
        ]"
      >
        <Utensils :size="16" />
        <span>Price Catalog ({{ filteredCatalog.length }})</span>
      </button>

      <button
        @click="activeTab = 'bulk'"
        :class="[
          'pb-3 font-bold text-sm transition-colors border-b-2 flex items-center gap-2',
          activeTab === 'bulk'
            ? 'border-emerald-600 text-emerald-700'
            : 'border-transparent text-slate-500 hover:text-slate-700'
        ]"
      >
        <TrendingUp :size="16" />
        <span>Bulk Price Adjustment</span>
      </button>

      <button
        @click="activeTab = 'history'"
        :class="[
          'pb-3 font-bold text-sm transition-colors border-b-2 flex items-center gap-2',
          activeTab === 'history'
            ? 'border-emerald-600 text-emerald-700'
            : 'border-transparent text-slate-500 hover:text-slate-700'
        ]"
      >
        <History :size="16" />
        <span>Audit & Rollback Log ({{ historyItems.length }})</span>
      </button>
    </div>

    <!-- TAB 1: CATALOG TABLE -->
    <div v-if="activeTab === 'catalog'" class="space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="relative w-full sm:w-80">
          <Search :size="16" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            v-model="searchQuery"
            placeholder="Search ingredients..."
            class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-xl text-sm outline-none focus:border-emerald-500 bg-white"
          />
        </div>
        <p class="text-xs text-slate-500 font-medium">
          Recipes dynamically query these raw unit costs to compute dish margin coverage and suggested price floors.
        </p>
      </div>

      <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50/80 border-b border-slate-200 text-[11px] font-black uppercase text-slate-500 tracking-wider">
              <th class="py-3 px-5">Ingredient Name</th>
              <th class="py-3 px-5">Unit Cost</th>
              <th class="py-3 px-5">Cost Unit</th>
              <th class="py-3 px-5">Last Updated</th>
              <th class="py-3 px-5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-sm font-medium">
            <tr v-if="filteredCatalog.length === 0">
              <td colspan="5" class="py-8 text-center text-slate-400 text-sm">
                No ingredients found in catalog. Click "Add Price" to add one.
              </td>
            </tr>
            <tr
              v-for="item in filteredCatalog"
              :key="item.id || item.ingredient_name"
              class="hover:bg-slate-50/60 transition-colors"
            >
              <td class="py-3 px-5 font-bold text-slate-900">
                {{ item.ingredient_name }}
              </td>
              <td class="py-3 px-5 font-black text-emerald-700">
                ₱{{ Number(item.cost || 0).toFixed(2) }}
              </td>
              <td class="py-3 px-5">
                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                  per {{ item.cost_unit || 'kg' }}
                </span>
              </td>
              <td class="py-3 px-5 text-xs text-slate-500 font-mono">
                {{ formatDate(item.updated_at) }}
              </td>
              <td class="py-3 px-5 text-right">
                <button
                  @click="openEditModal(item)"
                  class="px-3 py-1.5 text-xs font-bold text-emerald-700 hover:text-emerald-800 bg-emerald-50 hover:bg-emerald-100 rounded-lg transition-colors border border-emerald-200/60"
                >
                  Edit
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB 2: BULK ADJUSTMENT -->
    <div v-if="activeTab === 'bulk'" class="space-y-6">
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
        <div>
          <h2 class="text-base font-black text-slate-900 flex items-center gap-2">
            <TrendingUp :size="18" class="text-emerald-600" />
            <span>Apply Bulk Percentage Adjustment</span>
          </h2>
          <p class="text-xs text-slate-500 mt-1">
            Simulate or apply a raw ingredient cost adjustment across all ingredients or specific selected items.
            Percentage must be between -50% and +100%. Adjustments are logged with batch IDs and can be rolled back safely.
          </p>
        </div>

        <!-- Scope Selection -->
        <div class="space-y-3 pt-1">
          <label class="text-xs font-black uppercase tracking-wider text-slate-600 block">Adjustment Scope</label>
          <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 cursor-pointer bg-slate-50 hover:bg-slate-100 px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 transition-colors">
              <input type="radio" value="all" v-model="bulkScope" class="text-emerald-600 focus:ring-emerald-500" />
              <span>All Catalog Ingredients ({{ catalog.length }})</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer bg-slate-50 hover:bg-slate-100 px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 transition-colors">
              <input type="radio" value="selected" v-model="bulkScope" class="text-emerald-600 focus:ring-emerald-500" />
              <span>Specific Selected Ingredients ({{ selectedIngredientIds.length }} chosen)</span>
            </label>
          </div>

          <!-- Selection Box if scope is selected -->
          <div v-if="bulkScope === 'selected'" class="p-4 bg-slate-50 rounded-xl border border-slate-200 space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <button
                  type="button"
                  @click="selectAllForBulk"
                  class="text-xs text-emerald-700 font-bold hover:underline"
                >
                  Select All ({{ catalog.length }})
                </button>
                <span class="text-slate-300">|</span>
                <button
                  type="button"
                  @click="deselectAllForBulk"
                  class="text-xs text-rose-600 font-bold hover:underline"
                >
                  Deselect All
                </button>
              </div>

              <div class="relative w-full sm:w-64">
                <Search :size="14" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                  type="text"
                  v-model="bulkIngredientSearch"
                  placeholder="Filter ingredients..."
                  class="w-full pl-8 pr-3 py-1.5 border border-slate-300 rounded-lg text-xs bg-white focus:border-emerald-500 outline-none"
                />
              </div>
            </div>

            <div class="max-h-56 overflow-y-auto divide-y divide-slate-200/60 bg-white rounded-lg border border-slate-200">
              <div v-if="filteredBulkIngredients.length === 0" class="py-6 text-center text-xs text-slate-400">
                No matching ingredients in catalog.
              </div>
              <div
                v-for="ing in filteredBulkIngredients"
                :key="ing.id"
                class="flex items-center justify-between px-3.5 py-2 hover:bg-slate-50 cursor-pointer"
                @click="toggleIngredientSelection(ing.id)"
              >
                <label class="flex items-center gap-2.5 cursor-pointer text-xs font-medium text-slate-800" @click.stop>
                  <input
                    type="checkbox"
                    :value="ing.id"
                    v-model="selectedIngredientIds"
                    class="rounded text-emerald-600 focus:ring-emerald-500"
                  />
                  <span class="font-bold text-slate-900">{{ ing.ingredient_name }}</span>
                </label>
                <div class="text-xs font-mono text-slate-500">
                  ₱{{ Number(ing.cost || 0).toFixed(2) }} / {{ ing.cost_unit || 'kg' }}
                </div>
              </div>
            </div>
            <div class="text-[11px] font-bold text-slate-500 flex justify-end">
              {{ selectedIngredientIds.length }} of {{ catalog.length }} selected
            </div>
          </div>
        </div>

        <!-- Percentage Input and Action Buttons -->
        <div class="flex flex-col sm:flex-row sm:items-center gap-4 pt-2 border-t border-slate-100">
          <div class="flex flex-col gap-1">
            <div class="flex items-center gap-2">
              <label class="text-xs font-black uppercase tracking-wider text-slate-600">Adjustment %:</label>
              <input
                type="number"
                step="0.5"
                min="-50"
                max="100"
                v-model.number="bulkPercentage"
                placeholder="-50 to +100"
                class="w-36 px-3 py-2 border border-slate-300 rounded-xl font-bold text-sm outline-none focus:border-emerald-500 bg-white"
              />
            </div>
            <span class="text-[10px] text-slate-400 font-medium">Valid range: -50% to +100%</span>
          </div>

          <div class="flex items-center gap-3">
            <button
              @click="previewBulkAdjustment"
              :disabled="isPreviewing || bulkPercentage === null || bulkPercentage === ''"
              class="flex items-center gap-1.5 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs rounded-xl transition-all border border-slate-300 disabled:opacity-50"
            >
              <Eye :size="14" />
              <span>{{ isPreviewing ? 'Simulating...' : 'Preview Changes' }}</span>
            </button>

            <button
              @click="applyBulkAdjustment"
              :disabled="isApplying || bulkPercentage === null || bulkPercentage === '' || previewItems.length === 0"
              class="flex items-center gap-1.5 px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-md transition-all disabled:opacity-50"
            >
              <Check :size="14" />
              <span>{{ isApplying ? 'Applying Batch...' : 'Apply Adjustment' }}</span>
            </button>
          </div>
        </div>

        <!-- Preview Results Table -->
        <div v-if="previewItems.length > 0" class="pt-4 border-t border-slate-100 space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-black text-slate-700 uppercase tracking-wider">
              Preview Diff ({{ previewItems.length }} Ingredients Affected — Scope: {{ bulkScope }})
            </span>
            <span class="text-xs font-semibold text-slate-500">
              Adjustment rate: {{ bulkPercentage > 0 ? '+' : '' }}{{ bulkPercentage }}%
            </span>
          </div>

          <div class="max-h-80 overflow-y-auto rounded-xl border border-slate-200">
            <table class="w-full text-left border-collapse text-xs">
              <thead class="bg-slate-50 sticky top-0 border-b border-slate-200">
                <tr class="text-[10px] uppercase font-bold text-slate-500">
                  <th class="py-2.5 px-4">Ingredient</th>
                  <th class="py-2.5 px-4">Current Price</th>
                  <th class="py-2.5 px-4">New Price</th>
                  <th class="py-2.5 px-4">Unit</th>
                  <th class="py-2.5 px-4 text-right">Change</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium">
                <tr v-for="p in previewItems" :key="p.ingredient_name" class="hover:bg-slate-50/50">
                  <td class="py-2 px-4 font-bold text-slate-900">{{ p.ingredient_name }}</td>
                  <td class="py-2 px-4 text-slate-500">₱{{ Number(p.current_cost || p.old_cost).toFixed(2) }}</td>
                  <td class="py-2 px-4 font-black text-emerald-700">₱{{ Number(p.new_cost).toFixed(2) }}</td>
                  <td class="py-2 px-4 text-slate-500">per {{ p.cost_unit }}</td>
                  <td class="py-2 px-4 text-right font-bold" :class="p.new_cost >= (p.current_cost || p.old_cost) ? 'text-amber-600' : 'text-emerald-600'">
                    {{ p.new_cost >= (p.current_cost || p.old_cost) ? '+' : '' }}₱{{ (p.new_cost - (p.current_cost || p.old_cost)).toFixed(2) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 3: AUDIT & ROLLBACK HISTORY -->
    <div v-if="activeTab === 'history'" class="space-y-4">
      <div class="flex items-center justify-between">
        <p class="text-xs text-slate-500 font-medium">
          Comprehensive audit ledger of manual edits, bulk adjustments, and rollbacks.
        </p>
        <span class="text-xs font-semibold text-slate-400">
          Double-rollback defense active
        </span>
      </div>

      <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50/80 border-b border-slate-200 text-[11px] font-black uppercase text-slate-500 tracking-wider">
              <th class="py-3 px-4">Date / Time</th>
              <th class="py-3 px-4">Action</th>
              <th class="py-3 px-4">Batch ID</th>
              <th class="py-3 px-4">Ingredient</th>
              <th class="py-3 px-4">Old Cost</th>
              <th class="py-3 px-4">New Cost</th>
              <th class="py-3 px-4">Status</th>
              <th class="py-3 px-4 text-right">Rollback</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-xs font-medium">
            <tr v-if="historyItems.length === 0">
              <td colspan="8" class="py-8 text-center text-slate-400 text-sm">
                No price modifications recorded in history yet.
              </td>
            </tr>
            <tr
              v-for="hist in historyItems"
              :key="hist.id"
              class="hover:bg-slate-50/60 transition-colors"
            >
              <td class="py-3 px-4 font-mono text-slate-600">
                {{ formatDate(hist.created_at) }}
              </td>
              <td class="py-3 px-4">
                <span
                  class="inline-block px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider border"
                  :class="{
                    'bg-sky-50 text-sky-800 border-sky-200': hist.action === 'manual',
                    'bg-purple-50 text-purple-800 border-purple-200': hist.action === 'bulk',
                    'bg-amber-50 text-amber-800 border-amber-200': hist.action === 'rollback'
                  }"
                >
                  {{ hist.action }}
                </span>
              </td>
              <td class="py-3 px-4 font-mono text-slate-500">
                {{ hist.batch_id || '—' }}
              </td>
              <td class="py-3 px-4 font-bold text-slate-900">
                {{ hist.ingredient_name }}
              </td>
              <td class="py-3 px-4 text-slate-500 font-mono">
                ₱{{ Number(hist.old_cost || 0).toFixed(2) }}
              </td>
              <td class="py-3 px-4 font-bold text-slate-900 font-mono">
                ₱{{ Number(hist.new_cost || 0).toFixed(2) }}
              </td>
              <td class="py-3 px-4">
                <span
                  v-if="hist.is_rolled_back == 1"
                  class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200"
                >
                  Rolled Back
                </span>
                <span
                  v-else
                  class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"
                >
                  Active
                </span>
              </td>
              <td class="py-3 px-4 text-right">
                <button
                  v-if="hist.action !== 'rollback' && hist.is_rolled_back == 0"
                  @click="rollbackItem(hist)"
                  :disabled="rollingBackId === hist.id"
                  class="px-2.5 py-1 text-[11px] font-bold text-rose-700 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 rounded-lg transition-colors border border-rose-200 disabled:opacity-40"
                >
                  {{ rollingBackId === hist.id ? 'Rolling back...' : (hist.batch_id ? 'Rollback Batch' : 'Rollback') }}
                </button>
                <span v-else class="text-[11px] text-slate-400 font-medium italic">
                  {{ hist.is_rolled_back == 1 ? 'Already Rolled Back' : '—' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MODAL: ADD / EDIT SINGLE INGREDIENT PRICE -->
    <div
      v-if="showEditModal"
      class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4"
    >
      <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
          <h3 class="text-base font-black text-slate-900">
            {{ isEditing ? 'Edit Ingredient Price' : 'Add Ingredient Price' }}
          </h3>
          <button @click="closeEditModal" class="text-slate-400 hover:text-slate-600">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="saveSinglePrice" class="p-6 space-y-4">
          <div>
            <label class="block text-xs font-black uppercase text-slate-600 mb-1">Ingredient Name</label>
            <input
              type="text"
              v-model="editForm.ingredient_name"
              required
              :disabled="isEditing"
              placeholder="e.g. Chicken Breast, Garlic, Soy Sauce"
              class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm outline-none focus:border-emerald-500 disabled:bg-slate-100 disabled:text-slate-500 font-medium"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-black uppercase text-slate-600 mb-1">Unit Cost (₱)</label>
              <input
                type="number"
                step="0.01"
                min="0"
                v-model.number="editForm.cost"
                required
                placeholder="0.00"
                class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm font-bold outline-none focus:border-emerald-500"
              />
            </div>
            <div>
              <label class="block text-xs font-black uppercase text-slate-600 mb-1">Cost Unit</label>
              <select
                v-model="editForm.cost_unit"
                class="w-full px-3 py-2 border border-slate-300 rounded-xl text-sm font-semibold outline-none focus:border-emerald-500 bg-white"
              >
                <option value="kg">kg</option>
                <option value="g">g</option>
                <option value="l">l</option>
                <option value="ml">ml</option>
                <option value="pc">pc</option>
              </select>
            </div>
          </div>

          <div class="pt-4 border-t border-slate-100 flex justify-end space-x-3">
            <button
              type="button"
              @click="closeEditModal"
              class="px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="isSavingSingle"
              class="px-5 py-2 text-sm font-bold bg-emerald-600 text-white rounded-xl shadow hover:bg-emerald-700 transition-colors disabled:opacity-50"
            >
              {{ isSavingSingle ? 'Saving...' : 'Save Price' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL / DRAWER: AFFECTED DISHES IMPACT (C4) -->
    <div
      v-if="showAffectedModal"
      class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4"
    >
      <div class="bg-white rounded-2xl max-w-2xl w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[85vh]">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
          <div>
            <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
              <Utensils :size="18" class="text-emerald-600" />
              <span>Affected Dishes & Price Floor Impact</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
              Review menu items whose recipe costs were updated by this price adjustment.
            </p>
          </div>
          <button @click="showAffectedModal = false" class="text-slate-400 hover:text-slate-600">
            <X :size="20" />
          </button>
        </div>

        <div class="p-6 overflow-y-auto space-y-4">
          <!-- Policy Banner -->
          <div class="p-3.5 bg-sky-50 border border-sky-200 rounded-xl text-xs text-sky-900 flex items-start gap-2.5">
            <Info :size="16" class="text-sky-600 shrink-0 mt-0.5" />
            <div>
              <p class="font-bold">Price Floors Are Not Modified Automatically</p>
              <p class="text-sky-800 text-[11px] mt-0.5">
                Optimeal preserves vendor pricing sovereignty. Suggested price floors are calculated at 1.2x cost (capped at base price), but active price floors remain unchanged until manually applied in the recipe editor.
              </p>
            </div>
          </div>

          <div v-if="affectedDishes.length === 0" class="py-8 text-center text-slate-400 text-sm">
            No active recipes depend on the modified ingredients.
          </div>

          <div v-else class="rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
              <thead class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase font-bold text-slate-500">
                <tr>
                  <th class="py-2.5 px-4">Dish Name</th>
                  <th class="py-2.5 px-4">Base Price</th>
                  <th class="py-2.5 px-4">Active Floor</th>
                  <th class="py-2.5 px-4">Portion Cost</th>
                  <th class="py-2.5 px-4 text-right">Suggested Floor</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium">
                <tr v-for="dish in affectedDishes" :key="dish.dish_id" class="hover:bg-slate-50/50">
                  <td class="py-2.5 px-4 font-bold text-slate-900">
                    {{ dish.dish_name }}
                  </td>
                  <td class="py-2.5 px-4 text-slate-600 font-mono">
                    ₱{{ Number(dish.base_price || 0).toFixed(2) }}
                  </td>
                  <td class="py-2.5 px-4 text-slate-600 font-mono">
                    ₱{{ Number(dish.current_price_floor || 0).toFixed(2) }}
                  </td>
                  <td class="py-2.5 px-4 text-slate-700 font-mono">
                    ₱{{ Number(dish.cost_per_serving || 0).toFixed(2) }}
                  </td>
                  <td class="py-2.5 px-4 text-right font-black text-emerald-700 font-mono">
                    ₱{{ Number(dish.suggested_price_floor || 0).toFixed(2) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
          <button
            @click="showAffectedModal = false"
            class="px-5 py-2 text-xs font-bold bg-slate-800 text-white rounded-xl hover:bg-slate-900 transition-colors"
          >
            Acknowledge & Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import {
  Coins,
  RefreshCw,
  Plus,
  Utensils,
  TrendingUp,
  History,
  Search,
  CheckCircle2,
  AlertTriangle,
  X,
  Eye,
  Check,
  Info
} from 'lucide-vue-next'

const activeTab = ref('catalog')
const loading = ref(false)
const searchQuery = ref('')
const catalog = ref([])
const historyItems = ref([])

const successMessage = ref('')
const errorMessage = ref('')

// Single Edit / Add
const showEditModal = ref(false)
const isEditing = ref(false)
const isSavingSingle = ref(false)
const editForm = ref({
  ingredient_name: '',
  cost: 0,
  cost_unit: 'kg'
})

// Bulk Adjust
const bulkPercentage = ref(null)
const bulkScope = ref('all')
const selectedIngredientIds = ref([])
const bulkIngredientSearch = ref('')

const filteredBulkIngredients = computed(() => {
  const q = bulkIngredientSearch.value.trim().toLowerCase()
  if (!q) return catalog.value
  return catalog.value.filter(item =>
    (item.ingredient_name || '').toLowerCase().includes(q)
  )
})

const selectAllForBulk = () => {
  selectedIngredientIds.value = catalog.value.map(i => i.id)
}

const deselectAllForBulk = () => {
  selectedIngredientIds.value = []
}

const toggleIngredientSelection = (id) => {
  const idx = selectedIngredientIds.value.indexOf(id)
  if (idx > -1) {
    selectedIngredientIds.value.splice(idx, 1)
  } else {
    selectedIngredientIds.value.push(id)
  }
}
const isPreviewing = ref(false)
const isApplying = ref(false)
const previewItems = ref([])

// Affected Dishes Modal
const showAffectedModal = ref(false)
const affectedDishes = ref([])

// Rollback State
const rollingBackId = ref(null)

const filteredCatalog = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return catalog.value
  return catalog.value.filter(item =>
    (item.ingredient_name || '').toLowerCase().includes(q)
  )
})

const formatDate = (dt) => {
  if (!dt) return '—'
  const d = new Date(dt)
  return isNaN(d) ? dt : d.toLocaleString()
}

const fetchData = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const [catRes, histRes] = await Promise.all([
      fetch('/api/vendor/ingredient-costs'),
      fetch('/api/vendor/ingredient-costs/history')
    ])

    if (catRes.ok) {
      catalog.value = await catRes.json()
    }
    if (histRes.ok) {
      historyItems.value = await histRes.json()
    }
  } catch (err) {
    errorMessage.value = `Failed to load catalog: ${err.message}`
  } finally {
    loading.value = false
  }
}

const openAddModal = () => {
  isEditing.value = false
  editForm.value = { ingredient_name: '', cost: 0, cost_unit: 'kg' }
  showEditModal.value = true
}

const openEditModal = (item) => {
  isEditing.value = true
  editForm.value = {
    ingredient_name: item.ingredient_name,
    cost: Number(item.cost || 0),
    cost_unit: item.cost_unit || 'kg'
  }
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const saveSinglePrice = async () => {
  isSavingSingle.value = true
  errorMessage.value = ''
  try {
    const res = await fetch('/api/vendor/ingredient-costs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        items: [
          {
            ingredient_name: editForm.value.ingredient_name,
            cost: editForm.value.cost,
            cost_unit: editForm.value.cost_unit
          }
        ]
      })
    })

    const data = await res.json()
    if (!res.ok) {
      errorMessage.value = data.error || 'Failed to save price.'
      return
    }

    successMessage.value = `Price for "${editForm.value.ingredient_name}" updated successfully!`
    closeEditModal()
    await fetchData()

    if (data.affected_dishes && data.affected_dishes.length > 0) {
      affectedDishes.value = data.affected_dishes
      showAffectedModal.value = true
    }
  } catch (err) {
    errorMessage.value = `Network error: ${err.message}`
  } finally {
    isSavingSingle.value = false
  }
}

const previewBulkAdjustment = async () => {
  if (bulkPercentage.value === null || bulkPercentage.value === undefined || bulkPercentage.value === '') return

  if (bulkPercentage.value < -50 || bulkPercentage.value > 100) {
    errorMessage.value = 'Adjustment percentage must be strictly between -50% and +100%.'
    return
  }

  if (bulkScope.value === 'selected' && selectedIngredientIds.value.length === 0) {
    errorMessage.value = 'Please select at least one ingredient to adjust.'
    return
  }

  isPreviewing.value = true
  errorMessage.value = ''
  try {
    const payload = {
      percentage: bulkPercentage.value,
      scope: bulkScope.value,
      ingredient_ids: bulkScope.value === 'selected' ? selectedIngredientIds.value : []
    }
    const res = await fetch('/api/vendor/ingredient-costs/bulk-preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })

    const data = await res.json()
    if (!res.ok) {
      errorMessage.value = data.error || 'Failed to preview bulk adjustment.'
      previewItems.value = []
      return
    }

    previewItems.value = data.preview || data.items || []
  } catch (err) {
    errorMessage.value = `Network error: ${err.message}`
  } finally {
    isPreviewing.value = false
  }
}

const applyBulkAdjustment = async () => {
  if (bulkPercentage.value === null || bulkPercentage.value === undefined || bulkPercentage.value === '') return

  if (bulkPercentage.value < -50 || bulkPercentage.value > 100) {
    errorMessage.value = 'Adjustment percentage must be strictly between -50% and +100%.'
    return
  }

  if (bulkScope.value === 'selected' && selectedIngredientIds.value.length === 0) {
    errorMessage.value = 'Please select at least one ingredient to adjust.'
    return
  }

  const targetText = bulkScope.value === 'selected'
    ? `${selectedIngredientIds.value.length} selected ingredients`
    : `all ${catalog.value.length} catalog ingredients`

  if (!confirm(`Are you sure you want to apply a ${bulkPercentage.value}% price adjustment across ${targetText}?`)) {
    return
  }

  isApplying.value = true
  errorMessage.value = ''
  try {
    const payload = {
      percentage: bulkPercentage.value,
      scope: bulkScope.value,
      ingredient_ids: bulkScope.value === 'selected' ? selectedIngredientIds.value : []
    }
    const res = await fetch('/api/vendor/ingredient-costs/bulk-apply', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })

    const data = await res.json()
    if (!res.ok) {
      errorMessage.value = data.error || 'Failed to apply bulk adjustment.'
      return
    }

    successMessage.value = `Applied ${bulkPercentage.value}% adjustment to ${data.affected_count || data.count || 0} ingredients! Batch ID: ${data.batch_id}`
    previewItems.value = []
    bulkPercentage.value = null
    await fetchData()

    if (data.affected_dishes && data.affected_dishes.length > 0) {
      affectedDishes.value = data.affected_dishes
      showAffectedModal.value = true
    }
  } catch (err) {
    errorMessage.value = `Network error: ${err.message}`
  } finally {
    isApplying.value = false
  }
}

const rollbackItem = async (hist) => {
  const confirmText = hist.batch_id
    ? `Roll back bulk adjustment batch ${hist.batch_id}?`
    : `Roll back price change for ${hist.ingredient_name}?`

  if (!confirm(confirmText)) return

  rollingBackId.value = hist.id
  errorMessage.value = ''
  try {
    const payload = hist.batch_id
      ? { batch_id: hist.batch_id }
      : { history_id: hist.id }

    const res = await fetch('/api/vendor/ingredient-costs/rollback', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })

    const data = await res.json()
    if (!res.ok) {
      errorMessage.value = data.error || 'Failed to rollback price change.'
      return
    }

    successMessage.value = `Successfully rolled back ${data.rolled_back_count || 1} price record(s)!`
    await fetchData()

    if (data.affected_dishes && data.affected_dishes.length > 0) {
      affectedDishes.value = data.affected_dishes
      showAffectedModal.value = true
    }
  } catch (err) {
    errorMessage.value = `Network error: ${err.message}`
  } finally {
    rollingBackId.value = null
  }
}

onMounted(() => {
  fetchData()
})
</script>
