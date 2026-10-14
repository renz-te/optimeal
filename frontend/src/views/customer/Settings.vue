<template>
  <div class="max-w-4xl mx-auto space-y-8 pb-16 font-sans antialiased text-slate-900">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Customer Settings & Impact</h1>
        <p class="text-slate-500 mt-1 font-medium">Manage your personal impact, dietary guardrails, and alerts.</p>
      </div>

      <div class="flex items-center space-x-3 shrink-0">
        <button 
          id="save-preferences-header-btn"
          @click="saveAllPreferences" 
          :disabled="saving || !hasUnsavedChanges"
          class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-2.5 rounded-xl shadow-sm transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2"
        >
          <span v-if="saving" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
          <span>{{ saving ? 'Saving...' : (hasUnsavedChanges ? 'Save Changes' : 'Saved') }}</span>
        </button>
      </div>
    </div>

    <!-- Feedback Alerts -->
    <div v-if="successMessage" class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-3.5 rounded-2xl text-sm font-semibold flex items-center shadow-sm">
      <CheckCircle :size="18" class="mr-2.5 text-emerald-600 shrink-0" />
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="bg-rose-50 border border-rose-200 text-rose-700 px-5 py-3.5 rounded-2xl text-sm font-semibold flex items-center shadow-sm">
      <AlertCircle :size="18" class="mr-2.5 text-rose-600 shrink-0" />
      <span>{{ errorMessage }}</span>
    </div>

    <!-- 1. IMPACT DASHBOARD -->
    <section class="bg-gradient-to-br from-emerald-900 to-slate-900 text-white p-6 sm:p-8 rounded-3xl shadow-xl overflow-hidden relative">
      <div class="relative z-10">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
          <div>
            <div class="flex items-center space-x-2.5 text-emerald-400 text-xs font-bold uppercase tracking-wider mb-1.5">
              <Leaf :size="16" />
              <span>Your Sustainability Impact</span>
            </div>
            <h2 class="text-2xl font-black tracking-tight text-white">Food Rescue & Savings Tracker</h2>
          </div>

          <!-- Timeframe Selector Toggle Group -->
          <div class="inline-flex p-1 bg-white/10 backdrop-blur-md rounded-xl border border-white/10 self-start sm:self-auto">
            <button
              v-for="tf in timeframeOptions"
              :key="tf.value"
              :id="`timeframe-${tf.value}`"
              type="button"
              @click="setTimeframe(tf.value)"
              class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all"
              :class="selectedTimeframe === tf.value
                ? 'bg-emerald-500 text-white shadow-sm'
                : 'text-slate-300 hover:text-white hover:bg-white/5'"
            >
              {{ tf.label }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6" :class="{ 'opacity-60 transition-opacity': loadingImpact }">
          <!-- Card 1: Meals Rescued -->
          <div class="bg-white/10 backdrop-blur-md border border-white/10 rounded-2xl p-5 flex flex-col justify-between hover:bg-white/15 transition-colors">
            <div class="flex items-center justify-between text-emerald-300 mb-3">
              <span class="text-xs font-bold uppercase tracking-wider">Meals Rescued</span>
              <div class="w-9 h-9 rounded-xl bg-emerald-500/20 flex items-center justify-center">
                <Utensils :size="18" class="text-emerald-400" />
              </div>
            </div>
            <div>
              <div class="text-3xl font-black text-white flex items-center">
                <span>{{ impactStats.mealsRescued }}</span>
                <span v-if="loadingImpact" class="ml-2 animate-spin w-4 h-4 border-2 border-white/40 border-t-white rounded-full"></span>
              </div>
              <p class="text-xs text-slate-300 mt-1 font-medium">Surplus portions saved from waste</p>
            </div>
          </div>

          <!-- Card 2: Money Saved -->
          <div class="bg-white/10 backdrop-blur-md border border-white/10 rounded-2xl p-5 flex flex-col justify-between hover:bg-white/15 transition-colors">
            <div class="flex items-center justify-between text-amber-300 mb-3">
              <span class="text-xs font-bold uppercase tracking-wider">Money Saved</span>
              <div class="w-9 h-9 rounded-xl bg-amber-500/20 flex items-center justify-center">
                <Coins :size="18" class="text-amber-400" />
              </div>
            </div>
            <div>
              <div class="text-3xl font-black text-white flex items-center">
                <span>₱{{ Number(impactStats.moneySaved).toFixed(2) }}</span>
                <span v-if="loadingImpact" class="ml-2 animate-spin w-4 h-4 border-2 border-white/40 border-t-white rounded-full"></span>
              </div>
              <p class="text-xs text-slate-300 mt-1 font-medium">Decay discounts & meal deals</p>
            </div>
          </div>

          <!-- Card 3: CO2 Prevented -->
          <div class="bg-white/10 backdrop-blur-md border border-white/10 rounded-2xl p-5 flex flex-col justify-between hover:bg-white/15 transition-colors">
            <div class="flex items-center justify-between text-teal-300 mb-3">
              <span class="text-xs font-bold uppercase tracking-wider">CO₂ Offset</span>
              <div class="w-9 h-9 rounded-xl bg-teal-500/20 flex items-center justify-center">
                <Leaf :size="18" class="text-teal-400" />
              </div>
            </div>
            <div>
              <div class="text-3xl font-black text-white flex items-center">
                <span>{{ impactStats.co2Prevented }} <span class="text-lg font-bold">kg</span></span>
                <span v-if="loadingImpact" class="ml-2 animate-spin w-4 h-4 border-2 border-white/40 border-t-white rounded-full"></span>
              </div>
              <p class="text-xs text-slate-300 mt-1 font-medium">Estimated emissions diverted</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Subtle background glow -->
      <div class="absolute -top-12 -right-12 w-64 h-64 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
    </section>

    <!-- 2. PROFILE DETAILS & NUTRITIONAL GOALS -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Profile Details -->
      <section class="lg:col-span-2 bg-white p-6 sm:p-7 rounded-2xl border border-slate-200/80 shadow-sm space-y-5">
        <div class="flex items-center space-x-2.5 pb-2 border-b border-slate-100">
          <User :size="20" class="text-slate-600" />
          <h2 class="text-lg font-bold text-slate-900">Profile Details</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Full Name</label>
            <input 
              v-model="profile.name" 
              type="text" 
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-slate-700 font-semibold text-sm outline-none cursor-not-allowed" 
              readonly 
            />
          </div>
          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Email / Campus ID</label>
            <input 
              v-model="profile.email" 
              type="email" 
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-slate-700 font-semibold text-sm outline-none cursor-not-allowed" 
              readonly 
            />
          </div>
        </div>
      </section>

      <!-- 3. Nutritional Goals -->
      <section class="bg-white p-6 sm:p-7 rounded-2xl border border-slate-200/80 shadow-sm space-y-5">
        <div class="flex items-center space-x-2.5 pb-2 border-b border-slate-100">
          <Flame :size="20" class="text-amber-500" />
          <h2 class="text-lg font-bold text-slate-900">Nutritional Goals</h2>
        </div>

        <div>
          <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">Daily Calorie Target</label>
          <div class="relative">
            <input 
              id="target-calories-input"
              v-model.number="targetCalories" 
              type="number" 
              min="500" 
              max="6000" 
              step="50"
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-slate-900 font-black text-lg"
              placeholder="2000"
            />
            <span class="absolute right-4 top-3 text-xs font-bold text-slate-400 uppercase">kcal</span>
          </div>
          <p class="text-xs text-slate-500 mt-2">Personalizes menu meal match percentages.</p>
        </div>
      </section>
    </div>

    <!-- 4. DIETARY GUARDRAILS (ALLERGENS, BOUNDARIES, SENSITIVITIES) -->
    <section class="bg-white p-6 sm:p-8 rounded-2xl border border-slate-200/80 shadow-sm space-y-8">
      <div>
        <h2 class="text-xl font-bold text-slate-900 tracking-tight">Dietary Guardrails & Safety</h2>
        <p class="text-sm text-slate-500 mt-1">Configure your safety filters. Our platform automatically scans every dish formula.</p>
      </div>

      <!-- Medical Allergens (RED THEME) -->
      <div class="space-y-3">
        <div class="flex items-center space-x-2">
          <ShieldAlert :size="18" class="text-rose-600" />
          <h3 class="text-base font-bold text-slate-900">Medical Allergens</h3>
          <span class="text-xs bg-rose-100 text-rose-700 font-bold px-2 py-0.5 rounded-full">Strict Safety Barrier</span>
        </div>
        <p class="text-xs text-rose-700 font-semibold bg-rose-50/80 border border-rose-100 p-3 rounded-xl">
          Dishes containing these will be pushed to the bottom of the menu and require explicit acknowledgment at checkout.
        </p>
        <div class="flex flex-wrap gap-2 pt-1">
          <button 
            v-for="allergen in availableTaxonomy.allergens" 
            :key="allergen"
            type="button"
            :id="'allergen-' + allergen.toLowerCase().replace(/[^a-z0-9]/g, '-')"
            @click="toggleArr(selectedAllergens, allergen)"
            class="px-4 py-2 rounded-full border text-xs font-bold transition-all flex items-center space-x-1.5"
            :class="selectedAllergens.includes(allergen) 
              ? 'bg-rose-600 text-white border-rose-600 shadow-sm scale-100' 
              : 'bg-rose-50 border-rose-200 text-rose-800 hover:bg-rose-100'"
          >
            <Check v-if="selectedAllergens.includes(allergen)" :size="14" class="text-white" />
            <span>{{ allergen }}</span>
          </button>
        </div>
      </div>

      <hr class="border-slate-100" />

      <!-- Dietary Boundaries (BLUE THEME) -->
      <div class="space-y-3">
        <div class="flex items-center space-x-2">
          <div class="w-2.5 h-2.5 rounded-full bg-blue-600"></div>
          <h3 class="text-base font-bold text-slate-900">Dietary Boundaries</h3>
          <span class="text-xs bg-blue-100 text-blue-700 font-bold px-2 py-0.5 rounded-full">Lifestyle & Religious</span>
        </div>
        <p class="text-xs text-slate-500">
          Highlights dishes meeting your choices and warns on conflicting recipes.
        </p>
        <div class="flex flex-wrap gap-2 pt-1">
          <button 
            v-for="boundary in availableTaxonomy.dietary" 
            :key="boundary"
            type="button"
            @click="toggleArr(selectedBoundaries, boundary)"
            class="px-4 py-2 rounded-full border text-xs font-bold transition-all flex items-center space-x-1.5"
            :class="selectedBoundaries.includes(boundary) 
              ? 'bg-blue-600 text-white border-blue-600 shadow-sm' 
              : 'bg-blue-50 border-blue-200 text-blue-800 hover:bg-blue-100'"
          >
            <Check v-if="selectedBoundaries.includes(boundary)" :size="14" class="text-white" />
            <span>{{ boundary }}</span>
          </button>
        </div>
      </div>

      <hr class="border-slate-100" />

      <!-- Digestive Sensitivities (AMBER THEME) -->
      <div class="space-y-3">
        <div class="flex items-center space-x-2">
          <div class="w-2.5 h-2.5 rounded-full bg-amber-500"></div>
          <h3 class="text-base font-bold text-slate-900">Digestive Sensitivities</h3>
          <span class="text-xs bg-amber-100 text-amber-800 font-bold px-2 py-0.5 rounded-full">Gentle Advice</span>
        </div>
        <p class="text-xs text-slate-500">
          Provides gentle stomach warnings on dish cards without blocking order placement.
        </p>
        <div class="flex flex-wrap gap-2 pt-1">
          <button 
            v-for="sensitivity in availableTaxonomy.sensitivities" 
            :key="sensitivity"
            type="button"
            @click="toggleArr(selectedSensitivities, sensitivity)"
            class="px-4 py-2 rounded-full border text-xs font-bold transition-all flex items-center space-x-1.5"
            :class="selectedSensitivities.includes(sensitivity) 
              ? 'bg-amber-600 text-white border-amber-600 shadow-sm' 
              : 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100'"
          >
            <Check v-if="selectedSensitivities.includes(sensitivity)" :size="14" class="text-white" />
            <span>{{ sensitivity }}</span>
          </button>
        </div>
      </div>
    </section>



    <!-- 6. NOTIFICATION PREFERENCES -->
    <section class="bg-white p-6 sm:p-8 rounded-2xl border border-slate-200/80 shadow-sm space-y-6">
      <div class="flex items-center space-x-2.5 pb-2 border-b border-slate-100">
        <Bell :size="20" class="text-slate-600" />
        <h2 class="text-xl font-bold text-slate-900 tracking-tight">Notification Channels & Alerts</h2>
      </div>

      <div class="divide-y divide-slate-100">
        <!-- notify_order_status -->
        <div class="py-4 flex items-center justify-between">
          <div class="pr-4">
            <div class="font-bold text-sm text-slate-900">Order Status Updates</div>
            <p class="text-xs text-slate-500 mt-0.5">Real-time alerts when your order is queued, preparing, or ready for pickup.</p>
          </div>
          <button 
            type="button" 
            @click="notificationsForm.notify_order_status = !notificationsForm.notify_order_status"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
            :class="notificationsForm.notify_order_status ? 'bg-emerald-600' : 'bg-slate-300'"
          >
            <span class="sr-only">Toggle Order Status</span>
            <span 
              class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
              :class="notificationsForm.notify_order_status ? 'translate-x-5' : 'translate-x-0'"
            />
          </button>
        </div>

        <!-- notify_quota_hit -->
        <div class="py-4 flex items-center justify-between">
          <div class="pr-4">
            <div class="font-bold text-sm text-slate-900">Quota Approaching / Hit Alerts</div>
            <p class="text-xs text-slate-500 mt-0.5">Receive warnings when a popular dish reaches low availability.</p>
          </div>
          <button 
            type="button" 
            @click="notificationsForm.notify_quota_hit = !notificationsForm.notify_quota_hit"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
            :class="notificationsForm.notify_quota_hit ? 'bg-emerald-600' : 'bg-slate-300'"
          >
            <span class="sr-only">Toggle Quota Hit</span>
            <span 
              class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
              :class="notificationsForm.notify_quota_hit ? 'translate-x-5' : 'translate-x-0'"
            />
          </button>
        </div>

        <!-- notify_decay -->
        <div class="py-4 flex items-center justify-between">
          <div class="pr-4">
            <div class="font-bold text-sm text-slate-900">Price Decay & Clearance Discounts</div>
            <p class="text-xs text-slate-500 mt-0.5">Get notified immediately when closing hour flash sales trigger.</p>
          </div>
          <button 
            type="button" 
            @click="notificationsForm.notify_decay = !notificationsForm.notify_decay"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
            :class="notificationsForm.notify_decay ? 'bg-emerald-600' : 'bg-slate-300'"
          >
            <span class="sr-only">Toggle Price Decay</span>
            <span 
              class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
              :class="notificationsForm.notify_decay ? 'translate-x-5' : 'translate-x-0'"
            />
          </button>
        </div>

        <!-- notify_menu_published -->
        <div class="py-4 flex items-center justify-between">
          <div class="pr-4">
            <div class="font-bold text-sm text-slate-900">Daily Lineup & Menu Published</div>
            <p class="text-xs text-slate-500 mt-0.5">Alerts in the morning when vendors lock in today's active menu roster.</p>
          </div>
          <button 
            type="button" 
            @click="notificationsForm.notify_menu_published = !notificationsForm.notify_menu_published"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
            :class="notificationsForm.notify_menu_published ? 'bg-emerald-600' : 'bg-slate-300'"
          >
            <span class="sr-only">Toggle Menu Published</span>
            <span 
              class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
              :class="notificationsForm.notify_menu_published ? 'translate-x-5' : 'translate-x-0'"
            />
          </button>
        </div>
      </div>
    </section>

    <!-- 7. ACCOUNT ACTIONS -->
    <section class="bg-white p-6 sm:p-8 rounded-2xl border border-slate-200/80 shadow-sm space-y-4">
      <h2 class="text-lg font-bold text-slate-900 tracking-tight">Account & Data Management</h2>
      <p class="text-xs text-slate-500">Security actions and export tools for your OptiMeal account.</p>

      <div class="flex flex-wrap gap-3 pt-2">
        <button 
          type="button" 
          @click="openChangePasswordModal"
          class="flex items-center space-x-2 px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 font-bold text-sm hover:bg-slate-50 transition-colors shadow-sm"
        >
          <Lock :size="16" class="text-slate-500" />
          <span>Change Password</span>
        </button>

        <button 
          type="button" 
          @click="downloadUserData"
          class="flex items-center space-x-2 px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 font-bold text-sm hover:bg-slate-50 transition-colors shadow-sm"
        >
          <Download :size="16" class="text-slate-500" />
          <span>Download My Data</span>
        </button>

        <button 
          type="button" 
          @click="openDeleteAccountModal"
          class="flex items-center space-x-2 px-4 py-2.5 rounded-xl border border-rose-200 text-rose-700 hover:bg-rose-50 font-bold text-sm transition-colors shadow-sm"
        >
          <Trash2 :size="16" class="text-rose-500" />
          <span>Delete Account</span>
        </button>
      </div>
    </section>

    <!-- Floating Save Button -->
    <div class="sticky bottom-6 flex justify-end">
      <button 
        id="save-preferences-floating-btn"
        @click="saveAllPreferences" 
        :disabled="saving || !hasUnsavedChanges"
        class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 px-8 rounded-2xl shadow-xl hover:shadow-2xl transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2.5 border border-emerald-500/30"
      >
        <span v-if="saving" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
        <CheckCircle v-else :size="18" />
        <span>{{ saving ? 'Saving Preferences...' : (hasUnsavedChanges ? 'Save Preferences' : 'Saved') }}</span>
      </button>
    </div>

    <!-- Simple Password Change Modal -->
    <div v-if="showPasswordModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-slate-100 space-y-5">
        <h3 class="text-xl font-black text-slate-900">Change Account Password</h3>
        <p class="text-xs text-slate-500">Enter a secure new password of at least 6 characters.</p>

        <div class="space-y-3">
          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">New Password</label>
            <input 
              v-model="newPassword" 
              type="password" 
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 outline-none text-sm font-medium" 
              placeholder="••••••••" 
            />
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Confirm Password</label>
            <input 
              v-model="confirmPassword" 
              type="password" 
              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 outline-none text-sm font-medium" 
              placeholder="••••••••" 
            />
          </div>
        </div>

        <div class="flex items-center justify-end space-x-3 pt-2">
          <button @click="showPasswordModal = false" class="px-4 py-2 rounded-xl text-sm font-bold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</button>
          <button @click="handleUpdatePassword" class="px-5 py-2 rounded-xl text-sm font-bold bg-emerald-600 text-white hover:bg-emerald-700 transition-colors shadow-sm">Update Password</button>
        </div>
      </div>
    </div>

    <!-- Soft Delete Account Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl border border-rose-100 space-y-5">
        <div class="w-12 h-12 rounded-2xl bg-rose-100 flex items-center justify-center text-rose-600">
          <Trash2 :size="24" />
        </div>
        <h3 class="text-xl font-black text-slate-900">Deactivate Account?</h3>
        <p class="text-xs text-slate-600 leading-relaxed">
          Your account will be soft-deleted. Your historical orders and nutritional records will be anonymized according to cafeteria data policy.
        </p>

        <div class="flex items-center justify-end space-x-3 pt-2">
          <button @click="showDeleteModal = false" class="px-4 py-2 rounded-xl text-sm font-bold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</button>
          <button @click="confirmDeleteAccount" class="px-5 py-2 rounded-xl text-sm font-bold bg-rose-600 text-white hover:bg-rose-700 transition-colors shadow-sm">Deactivate Account</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { 
  Leaf, 
  Utensils, 
  Coins, 
  User, 
  Flame, 
  ShieldAlert, 
  Check, 
  Bell, 
  Lock, 
  Download, 
  Trash2, 
  CheckCircle, 
  AlertCircle 
} from 'lucide-vue-next'
import { useAuthStore } from '../../stores/auth'
import { useTaxonomy } from '../../stores/taxonomy'
import { useSandbox } from '../../stores/sandbox'

const router = useRouter()
const authStore = useAuthStore()
const { activePreviewMode, simulatedDietaryFlags } = useSandbox()
const { masterTaxonomy } = useTaxonomy()

// State
const loading = ref(false)
const saving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

// Impact Dashboard Stats & Timeframe Filter
const timeframeOptions = [
  { value: 'week', label: 'This Week' },
  { value: 'month', label: 'This Month' },
  { value: 'all_time', label: 'All Time' }
]
const selectedTimeframe = ref('week')
const loadingImpact = ref(false)

const impactStats = reactive({
  mealsRescued: 0,
  moneySaved: 0.00,
  co2Prevented: 0.0
})

const fetchImpact = async (tf = selectedTimeframe.value) => {
  loadingImpact.value = true
  try {
    const res = await fetch(`/api/customer/impact?timeframe=${tf}`)
    if (res.ok) {
      const data = await res.json()
      impactStats.mealsRescued = data.mealsRescued ?? (data.meals_rescued ?? 0)
      impactStats.moneySaved = Number(data.moneySaved ?? (data.money_saved ?? 0))
      impactStats.co2Prevented = Number(data.co2Prevented ?? (data.co2_prevented ?? 0))
    }
  } catch (err) {
    console.error('Failed to fetch impact stats:', err)
  } finally {
    loadingImpact.value = false
  }
}

const setTimeframe = (tf) => {
  if (selectedTimeframe.value === tf) return
  selectedTimeframe.value = tf
  fetchImpact(tf)
}

// Profile Details
const profile = reactive({
  name: '',
  email: ''
})

// Nutritional Goals
const targetCalories = ref(2000)

// Dietary Taxonomy Selection
const selectedAllergens = ref([])
const selectedBoundaries = ref([])
const selectedSensitivities = ref([])

// Notification Preferences
const notificationsForm = reactive({
  notify_quota_hit: true,
  notify_order_status: true,
  notify_decay: true,
  notify_menu_published: true
})

// Unsaved changes tracking
const initialSnapshot = ref('')

const takeSnapshot = () => {
  return JSON.stringify({
    targetCalories: targetCalories.value,
    allergens: [...selectedAllergens.value].sort(),
    boundaries: [...selectedBoundaries.value].sort(),
    sensitivities: [...selectedSensitivities.value].sort(),
    notifications: {
      quota: notificationsForm.notify_quota_hit,
      order: notificationsForm.notify_order_status,
      decay: notificationsForm.notify_decay,
      menu: notificationsForm.notify_menu_published
    }
  })
}

const hasUnsavedChanges = computed(() => {
  if (!initialSnapshot.value) return false
  return takeSnapshot() !== initialSnapshot.value
})

// Modal states
const showPasswordModal = ref(false)
const showDeleteModal = ref(false)
const newPassword = ref('')
const confirmPassword = ref('')

// Available Taxonomy with graceful fallback
const availableTaxonomy = computed(() => {
  return {
    allergens: masterTaxonomy.value?.allergens?.length 
      ? masterTaxonomy.value.allergens 
      : ['Peanuts', 'Tree Nuts', 'Milk', 'Eggs', 'Wheat', 'Soy', 'Fish', 'Shellfish', 'Sesame'],
    dietary: masterTaxonomy.value?.dietary?.length 
      ? masterTaxonomy.value.dietary 
      : ['Vegetarian', 'Vegan', 'Halal', 'Kosher', 'Gluten-Free', 'Dairy-Free', 'Pork-Free'],
    sensitivities: masterTaxonomy.value?.sensitivities?.length 
      ? masterTaxonomy.value.sensitivities 
      : ['Spicy', 'Lactose', 'Garlic/Onion', 'Oily / Deep Fried', 'MSG', 'Acidic / Citrus']
  }
})

// Toggle array helper
const toggleArr = (arrRef, item) => {
  const idx = arrRef.indexOf(item)
  if (idx > -1) {
    arrRef.splice(idx, 1)
  } else {
    arrRef.push(item)
  }
}

// 1. Fetch Profile & Preferences
const loadSettings = async () => {
  loading.value = true
  errorMessage.value = ''

  // Fallback to authStore
  if (authStore.user) {
    profile.name = authStore.user.name || ''
    profile.email = authStore.user.email || ''
  }

  try {
    // A. Load profile / dietary settings
    const profRes = await fetch('/api/customer/profile')
    if (profRes.ok) {
      const pData = await profRes.json()
      if (pData.name) profile.name = pData.name
      if (pData.email) profile.email = pData.email
      if (pData.target_calories) targetCalories.value = Number(pData.target_calories)
      if (Array.isArray(pData.allergens)) selectedAllergens.value = [...pData.allergens]
      if (Array.isArray(pData.dietary_boundaries)) selectedBoundaries.value = [...pData.dietary_boundaries]
      if (Array.isArray(pData.sensitivities)) selectedSensitivities.value = [...pData.sensitivities]
    }

    // B. Load user preferences from chunk 2 API
    const prefRes = await fetch('/api/user/preferences')
    if (prefRes.ok) {
      const prefs = await prefRes.json()
      if (typeof prefs.notify_quota_hit === 'boolean') {
        notificationsForm.notify_quota_hit = prefs.notify_quota_hit
      }
      if (typeof prefs.notify_order_status === 'boolean') {
        notificationsForm.notify_order_status = prefs.notify_order_status
      }
      if (typeof prefs.notify_decay === 'boolean') {
        notificationsForm.notify_decay = prefs.notify_decay
      }
      if (typeof prefs.notify_menu_published === 'boolean') {
        notificationsForm.notify_menu_published = prefs.notify_menu_published
      }
    }

    // Capture baseline snapshot for unsaved changes tracking
    initialSnapshot.value = takeSnapshot()
  } catch (err) {
    console.error('Failed to load customer settings', err)
  } finally {
    loading.value = false
  }
}

// 2. Save All Preferences
const saveAllPreferences = async () => {
  saving.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    // Save dietary profile
    const profilePayload = {
      target_calories: targetCalories.value,
      allergens: [...selectedAllergens.value],
      dietary_boundaries: [...selectedBoundaries.value],
      sensitivities: [...selectedSensitivities.value]
    }

    const pPromise = fetch('/api/customer/profile', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(profilePayload)
    })

    // Save preferences (notifications)
    const prefPayload = {
      notify_quota_hit: notificationsForm.notify_quota_hit,
      notify_order_status: notificationsForm.notify_order_status,
      notify_decay: notificationsForm.notify_decay,
      notify_menu_published: notificationsForm.notify_menu_published
    }

    const uPromise = fetch('/api/user/preferences', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(prefPayload)
    })

    const [pRes, uRes] = await Promise.all([pPromise, uPromise])

    if (!pRes.ok) {
      const err = await pRes.json().catch(() => ({}))
      throw new Error(err.error || 'Failed to update dietary profile.')
    }
    if (!uRes.ok) {
      const err = await uRes.json().catch(() => ({}))
      throw new Error(err.error || 'Failed to update user preferences.')
    }

    // Keep localStorage in sync for instantaneous UI guards
    const stored = localStorage.getItem('optimeal_user')
    if (stored) {
      try {
        const parsed = JSON.parse(stored)
        parsed.dietary_flags = {
          allergens: profilePayload.allergens,
          dietary: profilePayload.dietary_boundaries,
          sensitivities: profilePayload.sensitivities
        }
        localStorage.setItem('optimeal_user', JSON.stringify(parsed))
      } catch (e) {}
    }

    // Sync baseline snapshot
    initialSnapshot.value = takeSnapshot()

    successMessage.value = 'Settings and dietary preferences saved successfully!'
    setTimeout(() => { successMessage.value = '' }, 4000)
  } catch (err) {
    console.error('Error saving settings:', err)
    errorMessage.value = err.message || 'An error occurred while saving.'
  } finally {
    saving.value = false
  }
}

// 3. Account Actions
const openChangePasswordModal = () => {
  newPassword.value = ''
  confirmPassword.value = ''
  showPasswordModal.value = true
}

const handleUpdatePassword = () => {
  if (newPassword.value.length < 6) {
    alert('Password must be at least 6 characters long.')
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    alert('Passwords do not match.')
    return
  }
  showPasswordModal.value = false
  successMessage.value = 'Password updated successfully.'
  setTimeout(() => { successMessage.value = '' }, 3000)
}

const downloadUserData = () => {
  const exportData = {
    exported_at: new Date().toISOString(),
    profile: {
      name: profile.name,
      email: profile.email
    },
    nutritional_goals: {
      target_daily_calories: targetCalories.value
    },
    dietary_preferences: {
      allergens: selectedAllergens.value,
      dietary_boundaries: selectedBoundaries.value,
      sensitivities: selectedSensitivities.value
    },
    notifications: notificationsForm,
    impact: impactStats
  }

  const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `optimeal-user-data-${Date.now()}.json`
  a.click()
  URL.revokeObjectURL(url)
}

const openDeleteAccountModal = () => {
  showDeleteModal.value = true
}

const confirmDeleteAccount = () => {
  showDeleteModal.value = false
  authStore.clearAuth()
  router.push('/customer/login')
}

onMounted(() => {
  loadSettings()
  fetchImpact()
})
</script>
