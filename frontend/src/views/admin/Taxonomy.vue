<template>
  <div class="p-8 font-sans antialiased text-slate-900">
    <!-- Top Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Allergen Taxonomy & Ingredient Registry</h1>
        <p class="text-slate-500 mt-1 font-medium">
          Manage system ingredients, map critical allergens, and approve staff submissions.
        </p>
      </div>
      <button
        v-if="activeTab === 'registry'"
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
          Critical allergens come strictly from the ingredient-to-allergen lookup table in the live database. AI suggestions never bypass or invent allergen mappings. Unrecognized ingredients from cafeteria staff must be approved or mapped before allergens are evaluated.
        </p>
      </div>
    </div>

    <!-- Overview Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div
        @click="activeTab = 'registry'"
        class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between cursor-pointer hover:border-emerald-300 transition-all"
        :class="activeTab === 'registry' ? 'ring-2 ring-emerald-500/20' : ''"
      >
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Registered Ingredients</p>
          <h3 class="text-2xl font-black text-slate-800 mt-1">{{ ingredients.length }}</h3>
        </div>
        <div class="w-11 h-11 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center font-bold text-xs">
          Ing
        </div>
      </div>

      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Critical Allergens</p>
          <h3 class="text-2xl font-black text-rose-600 mt-1">{{ allergens.length }}</h3>
        </div>
        <div class="w-11 h-11 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center font-bold text-xs">
          Allg
        </div>
      </div>

      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Digestive Sensitivities</p>
          <h3 class="text-2xl font-black text-indigo-600 mt-1">{{ sensitivities.length }}</h3>
        </div>
        <div class="w-11 h-11 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center font-bold text-xs">
          Sens
        </div>
      </div>

      <div
        @click="activeTab = 'queue'"
        id="card-approval-queue"
        class="bg-white p-5 rounded-2xl border shadow-sm flex items-center justify-between cursor-pointer transition-all"
        :class="pendingQueue.length > 0
          ? 'border-amber-300 bg-amber-50/30 hover:border-amber-400 ring-2 ring-amber-500/20'
          : 'border-slate-200 hover:border-slate-300'"
      >
        <div>
          <p class="text-xs font-bold uppercase tracking-wider" :class="pendingQueue.length > 0 ? 'text-amber-700' : 'text-slate-400'">
            Pending Approvals
          </p>
          <h3 class="text-2xl font-black mt-1" :class="pendingQueue.length > 0 ? 'text-amber-600' : 'text-slate-800'">
            {{ pendingQueue.length }}
          </h3>
        </div>
        <div
          class="w-11 h-11 rounded-xl flex items-center justify-center font-bold text-xs"
          :class="pendingQueue.length > 0 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'"
        >
          <Clock :size="18" />
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center space-x-2 border-b border-slate-200 mb-8">
      <button
        id="tab-registry"
        @click="activeTab = 'registry'"
        class="pb-3.5 px-4 font-bold text-sm transition-all border-b-2 flex items-center space-x-2"
        :class="activeTab === 'registry'
          ? 'border-emerald-600 text-emerald-700'
          : 'border-transparent text-slate-500 hover:text-slate-800'"
      >
        <span>Ingredient Registry</span>
        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold">{{ ingredients.length }}</span>
      </button>

      <button
        id="tab-queue"
        @click="activeTab = 'queue'"
        class="pb-3.5 px-4 font-bold text-sm transition-all border-b-2 flex items-center space-x-2"
        :class="activeTab === 'queue'
          ? 'border-amber-600 text-amber-700'
          : 'border-transparent text-slate-500 hover:text-slate-800'"
      >
        <span>Approval Queue</span>
        <span
          id="queue-badge-count"
          class="text-xs px-2 py-0.5 rounded-full font-bold transition-all"
          :class="pendingQueue.length > 0 ? 'bg-amber-100 text-amber-800 border border-amber-300' : 'bg-slate-100 text-slate-600'"
        >
          {{ pendingQueue.length }}
        </span>
      </button>
    </div>

    <!-- TAB 1: INGREDIENT REGISTRY -->
    <div v-if="activeTab === 'registry'">
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
                id="search-ingredients-input"
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
    </div>

    <!-- TAB 2: APPROVAL QUEUE -->
    <div v-if="activeTab === 'queue'">
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-slate-50/50">
          <div>
            <h2 class="font-bold text-slate-900 text-lg flex items-center">
              <span>Pending Ingredient Approvals</span>
              <span v-if="pendingQueue.length > 0" class="ml-2.5 px-2.5 py-0.5 bg-amber-100 text-amber-800 rounded-full text-xs font-bold">
                {{ pendingQueue.length }} Pending
              </span>
            </h2>
            <p class="text-xs text-slate-500 mt-1">
              Unrecognized strings extracted during dish composition. Review and link to existing taxonomy or register as new ingredients with allergen mappings.
            </p>
          </div>

          <div class="flex items-center gap-3">
            <!-- Bulk Approve Action Button -->
            <button
              v-if="selectedSubmissions.length > 0"
              id="btn-bulk-approve"
              @click="handleBulkApprove"
              :disabled="bulkApproving"
              class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white rounded-lg text-xs font-bold transition-all shadow-sm flex items-center space-x-1.5 disabled:opacity-50"
            >
              <CheckCheck :size="15" />
              <span>{{ bulkApproving ? 'Approving...' : `Bulk Approve (${selectedSubmissions.length})` }}</span>
            </button>

            <button
              @click="fetchQueue"
              class="px-4 py-2 border border-slate-200 bg-white hover:bg-slate-50 rounded-lg text-xs font-bold text-slate-700 transition-colors"
            >
              Refresh Queue
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="w-12 px-4 py-4 text-center">
                  <input
                    type="checkbox"
                    id="checkbox-select-all"
                    :checked="isAllSelected"
                    :indeterminate.prop="isIndeterminate"
                    @change="toggleSelectAll"
                    :disabled="pendingQueue.length === 0"
                    class="w-4 h-4 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer disabled:opacity-50"
                    title="Select All Pending Submissions"
                  />
                </th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Unrecognized Raw String</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Date Submitted</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="loadingQueue">
                <td colspan="5" class="px-6 py-8 text-center text-slate-500 font-medium">
                  <span class="inline-block animate-spin w-5 h-5 border-2 border-amber-600 border-t-transparent rounded-full mr-2 align-middle"></span>
                  Checking for pending submissions...
                </td>
              </tr>
              <tr v-else-if="pendingQueue.length === 0">
                <td colspan="5" class="px-6 py-12 text-center text-slate-400 font-medium">
                  <div class="max-w-sm mx-auto flex flex-col items-center">
                    <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mb-3">
                      <CheckCircle :size="24" class="text-emerald-500" />
                    </div>
                    <p class="text-slate-700 font-bold">Queue is completely clear!</p>
                    <p class="text-xs text-slate-400 mt-1">All cafeteria staff ingredient submissions have been resolved.</p>
                  </div>
                </td>
              </tr>
              <tr
                v-for="sub in pendingQueue"
                :key="sub.id"
                :id="`submission-row-${sub.id}`"
                class="hover:bg-slate-50/50 transition-colors"
                :class="selectedSubmissions.includes(sub.id) ? 'bg-emerald-50/40' : ''"
              >
                <td class="px-4 py-4 text-center">
                  <input
                    type="checkbox"
                    :id="`checkbox-sub-${sub.id}`"
                    :value="sub.id"
                    v-model="selectedSubmissions"
                    class="w-4 h-4 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer"
                  />
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center space-x-2">
                    <span class="px-3 py-1 bg-amber-50 text-amber-900 border border-amber-200 rounded-lg font-mono text-sm font-bold">
                      {{ sub.raw_name }}
                    </span>
                    <span class="text-xs text-slate-400 font-mono">#{{ sub.id }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-xs text-slate-500 font-medium">
                  {{ formatDate(sub.created_at) }}
                </td>
                <td class="px-6 py-4">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">
                    Pending Review
                  </span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end space-x-2">
                    <!-- Approve as New Button -->
                    <button
                      :id="`btn-approve-new-${sub.id}`"
                      @click="openApproveModal(sub)"
                      class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition-colors shadow-sm flex items-center space-x-1"
                    >
                      <Plus :size="13" />
                      <span>Approve as New</span>
                    </button>

                    <!-- Link to Existing Button -->
                    <button
                      :id="`btn-link-existing-${sub.id}`"
                      @click="openLinkModal(sub)"
                      class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-bold transition-colors flex items-center space-x-1"
                    >
                      <Link2 :size="13" />
                      <span>Link to Existing</span>
                    </button>

                    <!-- Reject Button -->
                    <button
                      :id="`btn-reject-${sub.id}`"
                      @click="handleRejectSubmission(sub)"
                      class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                      title="Reject submission"
                    >
                      <Trash2 :size="15" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MODAL 1: ADD / EDIT INGREDIENT (Manual from Registry) -->
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

    <!-- MODAL 2: APPROVE SUBMISSION AS NEW INGREDIENT -->
    <div v-if="showApproveModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Approve Unrecognized Ingredient</h3>
            <p class="text-xs text-slate-500 mt-1">
              Add submitted string as a registered ingredient and assign its critical allergens.
            </p>
          </div>
          <button @click="showApproveModal = false" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="submitApproveAsNew" class="p-6 overflow-y-auto space-y-6">
          <div v-if="approveError" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center">
            <AlertCircle :size="18" class="mr-2 shrink-0" />
            <span>{{ approveError }}</span>
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">
              Ingredient Name <span class="text-rose-500">*</span>
            </label>
            <input
              id="approve-ingredient-name"
              v-model="approveForm.name"
              type="text"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all text-sm font-medium"
            />
          </div>

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
                :id="`queue-allergen-option-${allergen.id}`"
                class="flex items-center space-x-2.5 p-2 rounded-lg cursor-pointer transition-colors border select-none"
                :class="approveForm.allergen_ids.includes(allergen.id)
                  ? 'bg-rose-50/80 border-rose-300 text-rose-900 font-bold'
                  : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'"
              >
                <input
                  type="checkbox"
                  :value="allergen.id"
                  v-model="approveForm.allergen_ids"
                  class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4 border-slate-300"
                />
                <span class="text-xs">{{ allergen.name }}</span>
              </label>
            </div>
          </div>

          <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
            <button
              type="button"
              @click="showApproveModal = false"
              class="px-5 py-2.5 rounded-lg border border-slate-200 font-bold text-sm text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Cancel
            </button>
            <button
              id="btn-submit-approve"
              type="submit"
              :disabled="approving || !approveForm.name.trim()"
              class="px-6 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-sm transition-colors disabled:opacity-50 flex items-center space-x-2"
            >
              <span v-if="approving" class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
              <span>{{ approving ? 'Approving...' : 'Approve & Save' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MODAL 3: LINK TO EXISTING INGREDIENT -->
    <div v-if="showLinkModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Link to Existing Ingredient</h3>
            <p class="text-xs text-slate-500 mt-1">
              Map submission "<span class="font-bold text-slate-800">{{ currentSubmission?.raw_name }}</span>" to a catalog entry.
            </p>
          </div>
          <button @click="showLinkModal = false" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="submitLinkExisting" class="p-6 space-y-4">
          <div v-if="linkError" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center">
            <AlertCircle :size="18" class="mr-2 shrink-0" />
            <span>{{ linkError }}</span>
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Select Existing Ingredient</label>
            <select
              id="select-existing-ingredient"
              v-model="selectedExistingId"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none text-sm font-medium"
            >
              <option value="" disabled>Choose an ingredient...</option>
              <option v-for="ing in ingredients" :key="ing.id" :value="ing.id">
                {{ ing.name }} ({{ ing.allergens?.length ? ing.allergens.join(', ') : 'No Allergens' }})
              </option>
            </select>
          </div>

          <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
            <button
              type="button"
              @click="showLinkModal = false"
              class="px-5 py-2.5 rounded-lg border border-slate-200 font-bold text-sm text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Cancel
            </button>
            <button
              id="btn-submit-link"
              type="submit"
              :disabled="linking || !selectedExistingId"
              class="px-6 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm shadow-sm transition-colors disabled:opacity-50 flex items-center space-x-2"
            >
              <span v-if="linking" class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
              <span>{{ linking ? 'Linking...' : 'Confirm Link' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Plus, Edit2, X, Search, AlertCircle, CheckCircle, ShieldAlert, Clock, Link2, Trash2, CheckCheck } from 'lucide-vue-next'

const activeTab = ref('registry')

const allergens = ref([])
const sensitivities = ref([])
const ingredients = ref([])
const pendingQueue = ref([])
const selectedSubmissions = ref([])
const bulkApproving = ref(false)
const loadingTaxonomy = ref(false)
const loadingQueue = ref(false)
const pageError = ref('')
const successMessage = ref('')

const searchQuery = ref('')
const filterAllergenId = ref('')

// Manual Add/Edit Ingredient
const showModal = ref(false)
const editingId = ref(null)
const saving = ref(false)
const modalError = ref('')
const form = reactive({
  name: '',
  allergen_ids: []
})

// Queue: Approve as New Modal
const showApproveModal = ref(false)
const approving = ref(false)
const approveError = ref('')
const currentSubmission = ref(null)
const approveForm = reactive({
  name: '',
  allergen_ids: []
})

// Queue: Link to Existing Modal
const showLinkModal = ref(false)
const linking = ref(false)
const linkError = ref('')
const selectedExistingId = ref('')

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

const fetchQueue = async () => {
  loadingQueue.value = true
  try {
    const res = await fetch('/api/admin/taxonomy/queue')
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to load pending queue.')
    }
    pendingQueue.value = Array.isArray(data) ? data : []
    selectedSubmissions.value = []
  } catch (err) {
    console.error('Error fetching queue:', err)
  } finally {
    loadingQueue.value = false
  }
}

// Bulk Selection and Approval
const isAllSelected = computed(() => {
  return pendingQueue.value.length > 0 && selectedSubmissions.value.length === pendingQueue.value.length
})

const isIndeterminate = computed(() => {
  return selectedSubmissions.value.length > 0 && selectedSubmissions.value.length < pendingQueue.value.length
})

const toggleSelectAll = (e) => {
  if (e.target.checked) {
    selectedSubmissions.value = pendingQueue.value.map(s => s.id)
  } else {
    selectedSubmissions.value = []
  }
}

const handleBulkApprove = async () => {
  if (selectedSubmissions.value.length === 0) return
  if (!confirm(`Are you sure you want to approve ${selectedSubmissions.value.length} pending ingredient(s) in bulk?`)) {
    return
  }

  bulkApproving.value = true
  pageError.value = ''
  successMessage.value = ''

  try {
    const res = await fetch('/api/admin/taxonomy/bulk-approve', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ submission_ids: selectedSubmissions.value })
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to bulk approve ingredients.')
    }

    // Dynamically update table state without reloading
    const approvedSet = new Set(data.approved_ids || selectedSubmissions.value)
    pendingQueue.value = pendingQueue.value.filter(s => !approvedSet.has(s.id))
    selectedSubmissions.value = []

    successMessage.value = data.message || `Successfully approved ${approvedSet.size} ingredient(s).`
    setTimeout(() => {
      if (successMessage.value.startsWith('Successfully approved')) {
        successMessage.value = ''
      }
    }, 5000)

    // Synchronize taxonomy registry in the background
    await fetchTaxonomy()
  } catch (err) {
    console.error('Bulk approval error:', err)
    pageError.value = err.message || 'An error occurred during bulk approval.'
  } finally {
    bulkApproving.value = false
  }
}

onMounted(() => {
  fetchTaxonomy()
  fetchQueue()
})

const formatDate = (dateStr) => {
  if (!dateStr) return 'Just now'
  try {
    const d = new Date(dateStr)
    return d.toLocaleString()
  } catch (e) {
    return dateStr
  }
}

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

// APPROVE SUBMISSION LOGIC
const openApproveModal = (sub) => {
  currentSubmission.value = sub
  approveError.value = ''
  approveForm.name = sub.raw_name
  approveForm.allergen_ids = []
  showApproveModal.value = true
}

const submitApproveAsNew = async () => {
  if (!currentSubmission.value || !approveForm.name.trim()) return

  approveError.value = ''
  approving.value = true

  try {
    const res = await fetch('/api/admin/taxonomy/queue/resolve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        submission_id: currentSubmission.value.id,
        action: 'approve_as_new',
        name: approveForm.name.trim(),
        allergen_ids: approveForm.allergen_ids
      })
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to approve submission.')
    }

    successMessage.value = `Ingredient "${approveForm.name.trim()}" approved and added to registry!`
    setTimeout(() => {
      successMessage.value = ''
    }, 4000)

    showApproveModal.value = false
    await Promise.all([fetchQueue(), fetchTaxonomy()])
  } catch (err) {
    console.error('Approve error:', err)
    approveError.value = err.message
  } finally {
    approving.value = false
  }
}

// LINK TO EXISTING LOGIC
const openLinkModal = (sub) => {
  currentSubmission.value = sub
  linkError.value = ''
  selectedExistingId.value = ''
  showLinkModal.value = true
}

const submitLinkExisting = async () => {
  if (!currentSubmission.value || !selectedExistingId.value) return

  linkError.value = ''
  linking.value = true

  try {
    const res = await fetch('/api/admin/taxonomy/queue/resolve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        submission_id: currentSubmission.value.id,
        action: 'link_to_existing',
        existing_ingredient_id: selectedExistingId.value
      })
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to link submission.')
    }

    successMessage.value = `Submission linked to existing ingredient successfully!`
    setTimeout(() => {
      successMessage.value = ''
    }, 4000)

    showLinkModal.value = false
    await Promise.all([fetchQueue(), fetchTaxonomy()])
  } catch (err) {
    console.error('Link error:', err)
    linkError.value = err.message
  } finally {
    linking.value = false
  }
}

// REJECT LOGIC
const handleRejectSubmission = async (sub) => {
  if (!confirm(`Are you sure you want to reject submission "${sub.raw_name}"?`)) return

  try {
    const res = await fetch('/api/admin/taxonomy/queue/resolve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        submission_id: sub.id,
        action: 'reject'
      })
    })

    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to reject submission.')
    }

    successMessage.value = `Submission "${sub.raw_name}" rejected.`
    setTimeout(() => {
      successMessage.value = ''
    }, 4000)

    await fetchQueue()
  } catch (err) {
    console.error('Reject error:', err)
    pageError.value = err.message
  }
}
</script>
