<template>
  <div class="p-8 font-sans antialiased text-slate-900">
    <!-- Header -->
    <div
      class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8"
    >
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">
          System Overview & Settings
        </h1>
        <p class="text-slate-500 mt-1 font-medium">
          Live platform metrics, AI engine telemetry, and global cafeteria
          parameters.
        </p>
      </div>

      <button
        id="btn-refresh-dashboard"
        @click="loadDashboardData"
        :disabled="loadingMetrics || loadingSettings"
        class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-4 py-2.5 rounded-lg font-bold shadow-sm transition-colors flex items-center justify-center space-x-2 shrink-0 self-start md:self-auto"
      >
        <RefreshCw
          :size="16"
          :class="{ 'animate-spin': loadingMetrics || loadingSettings }"
        />
        <span>Refresh Data</span>
      </button>
    </div>

    <!-- Alert Messages -->
    <div
      v-if="pageError"
      class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm font-semibold flex items-center"
    >
      <AlertCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ pageError }}</span>
    </div>

    <div
      v-if="successMessage"
      class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-sm font-semibold flex items-center"
    >
      <CheckCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ successMessage }}</span>
    </div>

    <!-- Live Platform Metrics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <!-- Card 1: Users -->
      <div
        class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between"
      >
        <div class="flex items-center justify-between mb-4">
          <span
            class="text-xs font-bold text-slate-400 uppercase tracking-wider"
            >Registered Accounts</span
          >
          <div
            class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center"
          >
            <Users :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.users?.total ?? 0 }}</span>
          </div>
          <div class="flex flex-wrap gap-1.5 mt-3">
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"
            >
              {{ metrics.users?.students ?? 0 }} Students
            </span>
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200"
            >
              {{ metrics.users?.vendors ?? 0 }} Staff
            </span>
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200"
            >
              {{ metrics.users?.admins ?? 0 }} Admins
            </span>
          </div>
        </div>
      </div>

      <!-- Card 2: Menu Dishes -->
      <div
        class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between"
      >
        <div class="flex items-center justify-between mb-4">
          <span
            class="text-xs font-bold text-slate-400 uppercase tracking-wider"
            >Menu Catalog</span
          >
          <div
            class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center"
          >
            <Utensils :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.menu?.total ?? 0 }}</span>
          </div>
          <p class="text-xs text-slate-500 mt-2 font-medium">
            <span class="font-bold text-emerald-600">{{
              metrics.menu?.active ?? 0
            }}</span>
            active & available dishes
          </p>
        </div>
      </div>

      <!-- Card 3: Orders & Reservations -->
      <div
        class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between"
      >
        <div class="flex items-center justify-between mb-4">
          <span
            class="text-xs font-bold text-slate-400 uppercase tracking-wider"
            >Orders & Holds</span
          >
          <div
            class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center"
          >
            <ShoppingBag :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.orders?.total ?? 0 }}</span>
          </div>
          <div class="flex flex-wrap gap-1.5 mt-3">
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200"
            >
              {{ metrics.orders?.active ?? 0 }} Active
            </span>
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"
            >
              {{ metrics.orders?.claimed ?? 0 }} Claimed
            </span>
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500"
            >
              {{ metrics.orders?.cancelled ?? 0 }} Cancelled
            </span>
          </div>
        </div>
      </div>

      <!-- Card 4: Taxonomy Health -->
      <div
        class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between"
      >
        <div class="flex items-center justify-between mb-4">
          <span
            class="text-xs font-bold text-slate-400 uppercase tracking-wider"
            >Taxonomy & Health</span
          >
          <div
            class="w-10 h-10 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center"
          >
            <ShieldCheck :size="20" />
          </div>
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">
            <span v-if="loadingMetrics" class="text-slate-300">...</span>
            <span v-else>{{ metrics.taxonomy?.ingredients ?? 0 }}</span>
          </div>
          <div class="mt-2">
            <span
              v-if="metrics.taxonomy?.pending_submissions > 0"
              class="inline-flex items-center text-xs font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200"
            >
              {{ metrics.taxonomy?.pending_submissions }} Pending Review
            </span>
            <span v-else class="text-xs text-slate-400 font-medium">
              Queue clear ({{ metrics.taxonomy?.critical_allergens ?? 10 }}
              Master Allergens)
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- AI ENGINE CONTROL & TELEMETRY PANEL -->
    <div
      class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-8"
    >
      <div
        class="p-6 border-b border-slate-100 bg-gradient-to-r from-purple-50/60 via-indigo-50/40 to-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
      >
        <div class="flex items-center space-x-3">
          <div class="p-2.5 bg-purple-100 text-purple-700 rounded-xl shadow-xs">
            <Sparkles :size="22" />
          </div>
          <div>
            <h2 class="text-lg font-bold text-slate-900 flex items-center">
              <span>AI Engine Control & Telemetry</span>
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">
              Global master kill-switch for dish generation and automated
              composition telemetry.
            </p>
          </div>
        </div>

        <!-- Master Status Badge -->
        <div>
          <span
            v-if="settingsForm.ai_enabled"
            class="px-3.5 py-1.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center"
          >
            <span
              class="w-2 h-2 rounded-full bg-emerald-500 mr-2 animate-pulse"
            ></span>
            AI Online & Enabled
          </span>
          <span
            v-else
            class="px-3.5 py-1.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-300 flex items-center"
          >
            <span class="w-2 h-2 rounded-full bg-rose-500 mr-2"></span>
            Kill Switch Active / AI Offline
          </span>
        </div>
      </div>

      <div class="p-6 lg:p-8 space-y-6">
        <!-- Master Toggle Row -->
        <div
          class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-4 bg-slate-50/80 rounded-xl border border-slate-200 gap-4"
        >
          <div>
            <h3 class="font-bold text-slate-900 text-sm">
              AI Recipe & Dish Generation Master Switch
            </h3>
            <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">
              When toggled off, all AI dish generation, enrichment, and
              ingredient breakdowns are blocked across the vendor terminal.
            </p>
          </div>

          <div class="flex items-center space-x-3 shrink-0">
            <span
              class="text-xs font-bold"
              :class="
                settingsForm.ai_enabled ? 'text-emerald-700' : 'text-slate-400'
              "
            >
              {{ settingsForm.ai_enabled ? "ENABLED" : "DISABLED" }}
            </span>
            <button
              id="toggle-ai-enabled"
              type="button"
              role="switch"
              :aria-checked="settingsForm.ai_enabled"
              @click="handleToggleAi"
              :disabled="togglingAi"
              class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:opacity-50"
              :class="
                settingsForm.ai_enabled ? 'bg-purple-600' : 'bg-slate-300'
              "
            >
              <span
                class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                :class="
                  settingsForm.ai_enabled ? 'translate-x-7' : 'translate-x-0'
                "
              />
            </button>
          </div>
        </div>

        <!-- Telemetry Metrics Grid -->
        <div>
          <h4
            class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3"
          >
            AI Engine Telemetry
          </h4>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <!-- Unconfirmed AI Dishes -->
            <div
              id="telemetry-unconfirmed-dishes"
              class="p-4 rounded-xl border flex flex-col justify-between"
              :class="
                (metrics.ai?.unconfirmed_dishes ?? 0) > 0
                  ? 'bg-amber-50/70 border-amber-200'
                  : 'bg-slate-50/60 border-slate-200'
              "
            >
              <div class="flex items-center justify-between mb-2">
                <span
                  class="text-xs font-bold"
                  :class="
                    (metrics.ai?.unconfirmed_dishes ?? 0) > 0
                      ? 'text-amber-800'
                      : 'text-slate-500'
                  "
                >
                  Unconfirmed AI Dishes
                </span>
                <span
                  class="text-[10px] px-2 py-0.5 rounded-full font-bold"
                  :class="
                    (metrics.ai?.unconfirmed_dishes ?? 0) > 0
                      ? 'bg-amber-200 text-amber-900'
                      : 'bg-slate-200 text-slate-600'
                  "
                >
                  Review Required
                </span>
              </div>
              <div
                class="text-2xl font-black"
                :class="
                  (metrics.ai?.unconfirmed_dishes ?? 0) > 0
                    ? 'text-amber-700'
                    : 'text-slate-800'
                "
              >
                {{ metrics.ai?.unconfirmed_dishes ?? 0 }}
              </div>
              <p class="text-[11px] text-slate-500 mt-1">
                Generated dishes awaiting staff confirmation
              </p>
            </div>

            <!-- Staff Confirmed Dishes -->
            <div
              id="telemetry-confirmed-dishes"
              class="p-4 rounded-xl border border-slate-200 bg-slate-50/60 flex flex-col justify-between"
            >
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500"
                  >Staff Confirmed Dishes</span
                >
                <span
                  class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-emerald-100 text-emerald-800"
                  >Verified</span
                >
              </div>
              <div class="text-2xl font-black text-slate-800">
                {{ metrics.ai?.confirmed_dishes ?? 0 }}
              </div>
              <p class="text-[11px] text-slate-500 mt-1">
                Dishes verified by cafeteria staff
              </p>
            </div>

            <!-- Cached Queries -->
            <div
              id="telemetry-cached-queries"
              class="p-4 rounded-xl border border-slate-200 bg-slate-50/60 flex flex-col justify-between"
            >
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500"
                  >AI Query Cache</span
                >
                <span
                  class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-indigo-100 text-indigo-800"
                  >Efficiency</span
                >
              </div>
              <div class="text-2xl font-black text-slate-800">
                {{ metrics.ai?.cached_queries ?? 0 }}
              </div>
              <p class="text-[11px] text-slate-500 mt-1">
                Cached queries saving LLM API costs
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Global Announcement Banner Management (Task 4) -->
    <div
      class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-8"
    >
      <div
        class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
      >
        <div class="flex items-center space-x-3">
          <div class="p-2 bg-amber-100 text-amber-800 rounded-xl">
            <Megaphone :size="20" />
          </div>
          <div>
            <h2 class="text-lg font-bold text-slate-900">
              Global Announcement Banner
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">
              Broadcast an urgent notification displayed across all student and
              vendor screens.
            </p>
          </div>
        </div>

        <span
          v-if="settingsForm.global_announcement"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200"
        >
          <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
          Active Broadcast
        </span>
        <span v-else class="text-xs font-medium text-slate-400">
          No announcement active
        </span>
      </div>

      <div class="p-6 space-y-4">
        <div>
          <label
            class="block text-xs font-bold uppercase text-slate-600 mb-1.5 flex items-center justify-between"
          >
            <span>Announcement Message</span>
            <span
              class="text-xs font-mono font-semibold"
              :class="
                announcementText.length >= 190
                  ? 'text-rose-600 font-bold'
                  : 'text-slate-400'
              "
            >
              {{ announcementText.length }}/200
            </span>
          </label>
          <input
            id="announcement-input"
            v-model="announcementText"
            type="text"
            maxlength="200"
            placeholder="e.g. Cafeteria will close at 18:00 today for disinfection. Please claim reservations early."
            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-medium text-slate-800 bg-white"
          />
        </div>

        <div class="flex items-center justify-between pt-2">
          <p class="text-xs text-slate-500">
            Visible at the top of customer menu and vendor portal until
            dismissed or cleared.
          </p>

          <div class="flex items-center gap-3">
            <button
              v-if="settingsForm.global_announcement"
              type="button"
              @click="handleClearAnnouncement"
              :disabled="savingAnnouncement"
              class="px-4 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50 rounded-xl border border-rose-200 transition-colors disabled:opacity-50"
            >
              Clear Banner
            </button>
            <button
              id="btn-publish-announcement"
              type="button"
              @click="handlePublishAnnouncement"
              :disabled="
                savingAnnouncement ||
                announcementText.trim() ===
                  (settingsForm.global_announcement || '')
              "
              class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs shadow-sm transition-colors disabled:opacity-50 flex items-center gap-1.5"
            >
              <Megaphone :size="14" />
              <span>{{
                savingAnnouncement ? "Publishing..." : "Publish Announcement"
              }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Global Operating Configuration Form -->
    <div
      class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden"
    >
      <div
        class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
      >
        <div class="flex items-center space-x-3">
          <div class="p-2 bg-emerald-100 text-emerald-800 rounded-xl">
            <Sliders :size="20" />
          </div>
          <div>
            <h2 class="text-lg font-bold text-slate-900">
              Global Cafeteria Operating Settings
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">
              Control reservation expiration TTL, operating hours, and decay
              parameters.
            </p>
          </div>
        </div>

        <span
          v-if="settingsForm.updated_at"
          class="text-xs text-slate-400 font-mono"
        >
          Last saved:
          {{ new Date(settingsForm.updated_at).toLocaleTimeString() }}
        </span>
      </div>

      <form @submit.prevent="handleSaveSettings" class="p-8 space-y-6">
        <div
          v-if="settingsError"
          class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center"
        >
          <AlertCircle :size="18" class="mr-2 shrink-0" />
          <span>{{ settingsError }}</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <!-- Reservation TTL -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label
              class="block text-sm font-bold text-slate-800 mb-1 flex items-center justify-between"
            >
              <span>Reservation Expiry TTL</span>
              <span
                class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded"
                >Minutes</span
              >
            </label>
            <p class="text-xs text-slate-500 mb-3">
              How long uncollected reserved meals are locked before returning to
              available inventory.
            </p>
            <input
              id="setting-reservation-ttl"
              v-model.number="settingsForm.reservation_ttl"
              type="number"
              min="1"
              max="240"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white"
            />
          </div>

          <!-- Opening Time -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label
              class="block text-sm font-bold text-slate-800 mb-1 flex items-center justify-between"
            >
              <span>Daily Opening Time</span>
              <Clock :size="16" class="text-slate-400" />
            </label>
            <p class="text-xs text-slate-500 mb-3">
              Time the cafeteria counter unlocks for incoming student
              reservations.
            </p>
            <input
              id="setting-opening-time"
              v-model="settingsForm.opening_time"
              type="text"
              required
              placeholder="07:00"
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white font-mono"
            />
          </div>

          <!-- Daily Closing Time (with Today Override) -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <div class="flex items-center justify-between mb-1">
              <label
                class="text-sm font-bold text-slate-800 flex items-center gap-2"
              >
                <span>Daily Closing Time</span>
                <Clock :size="16" class="text-slate-400" />
              </label>
              <label
                class="flex items-center gap-1.5 text-xs text-primary-600 font-semibold cursor-pointer select-none"
              >
                <input
                  type="checkbox"
                  v-model="settingsForm.override_for_today"
                  class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 h-3.5 w-3.5"
                />
                <span>Override for today only</span>
              </label>
            </div>

            <p class="text-xs text-slate-500 mb-3">
              {{
                settingsForm.override_for_today
                  ? "Temporary closing time for today only. Will reset automatically tomorrow."
                  : "Fixed cafeteria closing schedule (managed via system operations; read-only)."
              }}
            </p>

            <input
              id="setting-closing-time"
              type="time"
              v-model="activeClosingTimeModel"
              :disabled="!settingsForm.override_for_today"
              :class="[
                'w-full px-4 py-2.5 rounded-lg border outline-none text-base font-bold font-mono transition-colors',
                settingsForm.override_for_today
                  ? 'bg-white border-primary-400 text-slate-900 focus:ring-2 focus:ring-primary-500 cursor-text'
                  : 'bg-slate-100 border-slate-300 text-slate-500 cursor-not-allowed',
              ]"
            />

            <p
              v-if="settingsForm.override_for_today"
              class="text-[11px] text-amber-700 font-medium mt-2"
            >
              Note: Override is active for today. This will temporarily control
              cafeteria closing behavior.
            </p>
            <p v-else class="text-[11px] text-slate-400 font-medium mt-2">
              Note: Check "Override for today only" above to edit closing hours
              temporarily.
            </p>
          </div>
          <!-- Max Discount Quantity -->
          <div class="bg-slate-50/60 p-5 rounded-xl border border-slate-200">
            <label class="block text-sm font-bold text-slate-800 mb-1">
              Max Discount Quantity / Order
            </label>
            <p class="text-xs text-slate-500 mb-3">
              Maximum subsidized discount servings allowed per single
              transaction.
            </p>
            <input
              id="setting-max-discount-qty"
              v-model.number="settingsForm.max_discount_qty"
              type="number"
              min="1"
              max="50"
              required
              class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold text-slate-800 bg-white"
            />
          </div>
        </div>

        <!-- Form Actions -->
        <div
          class="pt-6 border-t border-slate-100 flex items-center justify-end"
        >
          <button
            id="save-settings-btn"
            type="submit"
            :disabled="savingSettings"
            class="px-6 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-sm transition-colors disabled:opacity-50 flex items-center space-x-2"
          >
            <span
              v-if="savingSettings"
              class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"
            ></span>
            <Save v-else :size="16" />
            <span>{{
              savingSettings ? "Saving Settings..." : "Save Configuration"
            }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import {
  Users,
  Utensils,
  ShoppingBag,
  ShieldCheck,
  RefreshCw,
  AlertCircle,
  CheckCircle,
  Clock,
  Sliders,
  Save,
  Sparkles,
  Megaphone,
} from "lucide-vue-next";

const loadingMetrics = ref(false);
const loadingSettings = ref(false);
const savingSettings = ref(false);
const savingAnnouncement = ref(false);
const togglingAi = ref(false);
const announcementText = ref("");
const pageError = ref("");
const settingsError = ref("");
const successMessage = ref("");

const metrics = reactive({
  users: { total: 0, students: 0, vendors: 0, admins: 0 },
  menu: { total: 0, active: 0 },
  orders: { total: 0, active: 0, claimed: 0, cancelled: 0 },
  taxonomy: { ingredients: 0, pending_submissions: 0, critical_allergens: 0 },
  ai: { unconfirmed_dishes: 0, confirmed_dishes: 0, cached_queries: 0 },
});

const settingsForm = reactive({
  id: 1,
  reservation_ttl: 30,
  opening_time: "07:00",
  closing_time: "20:00",
  override_closing_time: "20:00",
  override_for_today: false,
  max_discount_qty: 5,
  ai_enabled: true,
  global_announcement: null,
  updated_at: "",
});

const activeClosingTimeModel = computed({
  get() {
    return settingsForm.override_for_today
      ? settingsForm.override_closing_time
      : settingsForm.closing_time;
  },
  set(val) {
    if (settingsForm.override_for_today) {
      settingsForm.override_closing_time = val;
    }
  },
});

const fetchMetrics = async () => {
  loadingMetrics.value = true;
  try {
    const res = await fetch("/api/admin/metrics");
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to fetch dashboard metrics.");
    }
    Object.assign(metrics, data);
  } catch (err) {
    console.error("Error fetching metrics:", err);
    pageError.value = err.message;
  } finally {
    loadingMetrics.value = false;
  }
};

const fetchSettings = async () => {
  loadingSettings.value = true;
  try {
    const res = await fetch("/api/admin/settings");
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to fetch settings.");
    }
    const s = data.settings || data.data;
    if (s) {
      Object.assign(settingsForm, s);
      settingsForm.override_closing_time = s.override_closing_time
        ? s.override_closing_time.slice(0, 5)
        : s.closing_time || "20:00";
      settingsForm.override_for_today = Boolean(
        s.is_override_active ||
        (s.override_closing_date && s.override_closing_time),
      );
      announcementText.value = s.global_announcement || "";
    }
  } catch (err) {
    console.error("Error fetching settings:", err);
    settingsError.value = err.message;
  } finally {
    loadingSettings.value = false;
  }
};

const loadDashboardData = async () => {
  pageError.value = "";
  settingsError.value = "";
  await Promise.all([fetchMetrics(), fetchSettings()]);
};

onMounted(() => {
  loadDashboardData();
});

const handleToggleAi = async () => {
  togglingAi.value = true;
  const targetState = !settingsForm.ai_enabled;
  try {
    const res = await fetch("/api/admin/settings", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        ai_enabled: targetState,
      }),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to update AI state.");
    }

    if (data.settings) {
      Object.assign(settingsForm, data.settings);
    } else {
      settingsForm.ai_enabled = targetState;
    }

    successMessage.value = `AI Generation ${settingsForm.ai_enabled ? "enabled" : "disabled (kill switch engaged)"} successfully.`;
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);

    // Re-fetch metrics to refresh telemetry
    await fetchMetrics();
  } catch (err) {
    console.error("Error toggling AI:", err);
    pageError.value = err.message;
  } finally {
    togglingAi.value = false;
  }
};

const handleSaveSettings = async () => {
  settingsError.value = "";
  savingSettings.value = true;
  try {
    const res = await fetch("/api/admin/settings", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        reservation_ttl: settingsForm.reservation_ttl,
        opening_time: settingsForm.opening_time,
        max_discount_qty: settingsForm.max_discount_qty,
        ai_enabled: settingsForm.ai_enabled,
        override_for_today: settingsForm.override_for_today,
        override_closing_time: settingsForm.override_for_today
          ? settingsForm.override_closing_time
          : null,
      }),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to update settings.");
    }

    const s = data.settings || data.data;
    if (s) {
      Object.assign(settingsForm, s);
      settingsForm.override_closing_time = s.override_closing_time
        ? s.override_closing_time.slice(0, 5)
        : s.closing_time || "20:00";
      settingsForm.override_for_today = Boolean(
        s.is_override_active ||
        (s.override_closing_date && s.override_closing_time),
      );
    }

    successMessage.value = "Global cafeteria settings saved successfully!";
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);
  } catch (err) {
    console.error("Save settings error:", err);
    settingsError.value = err.message;
  } finally {
    savingSettings.value = false;
  }
};

const handlePublishAnnouncement = async () => {
  savingAnnouncement.value = true;
  pageError.value = "";
  try {
    const res = await fetch("/api/admin/settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        global_announcement: announcementText.value.trim(),
      }),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to publish announcement.");
    }

    if (data.settings) {
      Object.assign(settingsForm, data.settings);
      announcementText.value = data.settings.global_announcement || "";
    }

    successMessage.value = "Global announcement published successfully!";
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);
  } catch (err) {
    pageError.value = err.message;
  } finally {
    savingAnnouncement.value = false;
  }
};

const handleClearAnnouncement = async () => {
  savingAnnouncement.value = true;
  pageError.value = "";
  try {
    const res = await fetch("/api/admin/settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        global_announcement: "",
      }),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to clear announcement.");
    }

    if (data.settings) {
      Object.assign(settingsForm, data.settings);
      announcementText.value = "";
    }

    successMessage.value = "Global announcement cleared.";
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);
  } catch (err) {
    pageError.value = err.message;
  } finally {
    savingAnnouncement.value = false;
  }
};
</script>
