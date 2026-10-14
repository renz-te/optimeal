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

    <!-- Tab Navigation -->
    <div class="flex items-center space-x-2 border-b border-slate-200 mb-8 pb-px overflow-x-auto hide-scrollbar">
      <button
        type="button"
        id="tab-btn-overview"
        @click="activeTab = 'overview'"
        class="px-5 py-3 font-bold text-sm rounded-t-xl transition-all border-b-2 flex items-center gap-2 cursor-pointer shrink-0"
        :class="
          activeTab === 'overview'
            ? 'border-emerald-600 text-emerald-800 bg-emerald-50/50'
            : 'border-transparent text-slate-500 hover:text-slate-800 hover:bg-slate-50'
        "
      >
        <LayoutDashboard :size="16" />
        <span>Overview</span>
      </button>

      <button
        type="button"
        id="tab-btn-operations"
        @click="activeTab = 'operations'"
        class="px-5 py-3 font-bold text-sm rounded-t-xl transition-all border-b-2 flex items-center gap-2 cursor-pointer shrink-0"
        :class="
          activeTab === 'operations'
            ? 'border-emerald-600 text-emerald-800 bg-emerald-50/50'
            : 'border-transparent text-slate-500 hover:text-slate-800 hover:bg-slate-50'
        "
      >
        <Clock :size="16" />
        <span>Operations</span>
      </button>

      <button
        type="button"
        id="tab-btn-flags"
        @click="activeTab = 'flags'"
        class="px-5 py-3 font-bold text-sm rounded-t-xl transition-all border-b-2 flex items-center gap-2 cursor-pointer shrink-0"
        :class="
          activeTab === 'flags'
            ? 'border-emerald-600 text-emerald-800 bg-emerald-50/50'
            : 'border-transparent text-slate-500 hover:text-slate-800 hover:bg-slate-50'
        "
      >
        <Sliders :size="16" />
        <span>Feature Flags</span>
      </button>

      <button
        type="button"
        id="tab-btn-taxonomy"
        @click="activeTab = 'taxonomy'"
        class="px-5 py-3 font-bold text-sm rounded-t-xl transition-all border-b-2 flex items-center gap-2 cursor-pointer shrink-0"
        :class="
          activeTab === 'taxonomy'
            ? 'border-emerald-600 text-emerald-800 bg-emerald-50/50'
            : 'border-transparent text-slate-500 hover:text-slate-800 hover:bg-slate-50'
        "
      >
        <ListTree :size="16" />
        <span>Allergen Taxonomy</span>
      </button>
    </div>

    <!-- Platform Overview Section -->
    <div v-show="activeTab === 'overview'" class="space-y-8 mb-8">
      <!-- Live Platform Metrics Grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
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
            v-if="settingsForm.enable_ai_recipe_generation"
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
        <!-- Master Status Row (Read-Only Status Badge) -->
        <div
          class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-4 bg-slate-50/80 rounded-xl border border-slate-200 gap-4"
        >
          <div>
            <h3 class="font-bold text-slate-900 text-sm">
              AI Recipe & Dish Generation Status
            </h3>
            <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">
              When toggled off, all AI dish generation, enrichment, and
              ingredient breakdowns are blocked across the vendor terminal. Manage this setting in the Feature Flags tab.
            </p>
          </div>

          <div class="flex items-center space-x-3 shrink-0">
            <span
              id="ai-recipe-status-badge"
              class="px-3.5 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider flex items-center"
              :class="
                settingsForm.enable_ai_recipe_generation
                  ? 'bg-emerald-100 text-emerald-800 border border-emerald-300'
                  : 'bg-rose-100 text-rose-800 border border-rose-300'
              "
            >
              <span
                class="w-2 h-2 rounded-full mr-2"
                :class="
                  settingsForm.enable_ai_recipe_generation
                    ? 'bg-emerald-500 animate-pulse'
                    : 'bg-rose-500'
                "
              ></span>
              {{ settingsForm.enable_ai_recipe_generation ? "Active" : "Disabled" }}
            </span>
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
    </div>

    <!-- Operations Section -->
    <div v-show="activeTab === 'operations'" class="space-y-8 mb-8">
      <!-- Alert / Notice inside Settings -->
      <div
        v-if="settingsError"
        class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm font-semibold flex items-center"
      >
        <AlertCircle :size="18" class="mr-2 shrink-0" />
        <span>{{ settingsError }}</span>
      </div>

      <!-- PANEL 1: OPERATING HOURS -->
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-emerald-100 text-emerald-800 rounded-xl">
              <Clock :size="20" />
            </div>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Operating Hours & Schedule</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Baseline daily operating schedule and temporary emergency closing time overrides.
              </p>
            </div>
          </div>

          <div v-if="settingsForm.effective_closing_time" class="flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500">Effective Closing:</span>
            <span
              class="px-2.5 py-1 rounded-full text-xs font-bold font-mono"
              :class="overrideClosingTimeEnabled ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'bg-slate-100 text-slate-800 border border-slate-200'"
            >
              {{ settingsForm.effective_closing_time }}
              <span v-if="overrideClosingTimeEnabled" class="ml-1 text-[10px] font-extrabold uppercase text-amber-700">(Override Active)</span>
            </span>
          </div>
        </div>

        <div class="p-6 space-y-6">
          <!-- Baseline Times (Read-only / Baseline Display) -->
          <div>
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Current Baseline Hours</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
              <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/60">
                <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Daily Opening Time</div>
                <div class="text-2xl font-black font-mono text-slate-800">{{ settingsForm.opening_time || '07:00' }}</div>
                <p class="text-[11px] text-slate-400 mt-1">Standard counter open time</p>
              </div>

              <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/60">
                <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Daily Base Closing Time</div>
                <div class="text-2xl font-black font-mono text-slate-800">{{ settingsForm.closing_time || '20:00' }}</div>
                <p class="text-[11px] text-slate-400 mt-1">Configured baseline closing hour</p>
              </div>

              <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/60">
                <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Reservation Expiry TTL</div>
                <div class="text-2xl font-black font-mono text-slate-800">{{ settingsForm.reservation_ttl || 30 }} <span class="text-sm font-semibold text-slate-500">mins</span></div>
                <p class="text-[11px] text-slate-400 mt-1">Auto-cancel uncollected reservations</p>
              </div>
            </div>
          </div>

          <!-- Override Closing Time Toggle & Conditional Input Fields -->
          <div
            class="p-5 rounded-xl border transition-all"
            :class="overrideClosingTimeEnabled ? 'bg-amber-50/40 border-amber-300 ring-1 ring-amber-200' : 'bg-slate-50/80 border-slate-200'"
          >
            <div class="flex items-start justify-between gap-4">
              <div class="space-y-1">
                <div class="flex items-center gap-2">
                  <label for="override-closing-toggle" class="font-bold text-sm text-slate-900 cursor-pointer select-none">
                    Override Closing Time
                  </label>
                  <span
                    v-if="overrideClosingTimeEnabled"
                    class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 border border-amber-300"
                  >
                    Override Active
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">
                  Temporarily enforce an alternative closing time with a scheduled expiration date.
                </p>
              </div>

              <input
                id="override-closing-toggle"
                type="checkbox"
                v-model="overrideClosingTimeEnabled"
                class="mt-1 h-5 w-5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
              />
            </div>

            <!-- Revealed Fields when Checked -->
            <transition
              enter-active-class="transition duration-200 ease-out"
              enter-from-class="transform opacity-0 -translate-y-2"
              enter-to-class="transform opacity-100 translate-y-0"
              leave-active-class="transition duration-150 ease-in"
              leave-from-class="transform opacity-100 translate-y-0"
              leave-to-class="transform opacity-0 -translate-y-2"
            >
              <div
                v-if="overrideClosingTimeEnabled"
                class="mt-5 pt-5 border-t border-amber-200/80 grid grid-cols-1 sm:grid-cols-2 gap-4"
              >
                <div>
                  <label
                    for="override-closing-time"
                    class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center justify-between"
                  >
                    <span>Override Closing Time (HH:MM)</span>
                    <Clock :size="14" class="text-amber-600" />
                  </label>
                  <input
                    id="override-closing-time"
                    v-model="settingsForm.override_closing_time"
                    type="time"
                    required
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white"
                  />
                  <p class="text-[11px] text-slate-500 mt-1">Temporary closing hour for student ordering and surplus clearance.</p>
                </div>

                <div>
                  <label
                    for="override-expires-at"
                    class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 flex items-center justify-between"
                  >
                    <span>Override Expiration Date</span>
                    <Calendar :size="14" class="text-amber-600" />
                  </label>
                  <input
                    id="override-expires-at"
                    v-model="settingsForm.override_expires_at"
                    type="date"
                    required
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white"
                  />
                  <p class="text-[11px] text-slate-500 mt-1">Date through which this override will remain in effect.</p>
                </div>
              </div>
            </transition>

            <!-- Save Operating Hours Action Button -->
            <div class="mt-6 pt-6 border-t border-slate-200/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div class="text-xs text-slate-500">
                <span v-if="settingsForm.updated_at" class="font-mono">
                  Last persisted: {{ new Date(settingsForm.updated_at).toLocaleTimeString() }}
                </span>
                <span v-else>Click save to apply changes to operating hours.</span>
              </div>

              <button
                id="save-operations-btn"
                type="button"
                @click="handleSaveConfiguration"
                :disabled="savingSettings"
                class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition-all active:scale-95 disabled:opacity-50 flex items-center justify-center space-x-2 cursor-pointer"
              >
                <span
                  v-if="savingSettings"
                  class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"
                ></span>
                <Save v-else :size="16" />
                <span>{{ savingSettings ? "Saving Settings..." : "Save Operating Hours" }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- PANEL 3: GLOBAL ANNOUNCEMENTS -->
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-amber-100 text-amber-800 rounded-xl">
              <Megaphone :size="20" />
            </div>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Global Announcements</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Broadcast urgent notices or system updates across student and vendor portals.
              </p>
            </div>
          </div>

          <span
            v-if="settingsForm.global_announcement"
            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-300"
          >
            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
            Active Broadcast
          </span>
          <span v-else class="text-xs font-medium text-slate-400">No active broadcast</span>
        </div>

        <div class="p-6 space-y-5">
          <!-- Active Broadcast Status (if present) -->
          <div
            v-if="settingsForm.global_announcement"
            class="p-4 rounded-xl border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
            :class="{
              'bg-rose-50 border-rose-300 text-rose-950': settingsForm.announcement_severity === 'critical',
              'bg-amber-50 border-amber-300 text-amber-950': settingsForm.announcement_severity === 'warning',
              'bg-blue-50 border-blue-300 text-blue-950': !settingsForm.announcement_severity || settingsForm.announcement_severity === 'info'
            }"
          >
            <div class="flex items-start gap-3">
              <AlertTriangle v-if="settingsForm.announcement_severity === 'critical'" :size="20" class="text-rose-600 shrink-0 mt-0.5" />
              <AlertCircle v-else-if="settingsForm.announcement_severity === 'warning'" :size="20" class="text-amber-600 shrink-0 mt-0.5" />
              <Info v-else :size="20" class="text-blue-600 shrink-0 mt-0.5" />
              <div>
                <div class="flex items-center gap-2 mb-0.5">
                  <span class="text-xs font-black uppercase tracking-wider">
                    {{ settingsForm.announcement_severity || 'info' }} Priority Broadcast
                  </span>
                  <span v-if="settingsForm.announcement_expires_at" class="text-[11px] opacity-75 font-mono">
                    Expires: {{ new Date(settingsForm.announcement_expires_at).toLocaleString() }}
                  </span>
                </div>
                <p class="text-sm font-semibold leading-relaxed">{{ settingsForm.global_announcement }}</p>
              </div>
            </div>

            <button
              type="button"
              @click="handleClearAnnouncement"
              :disabled="savingAnnouncement"
              class="px-3.5 py-1.5 text-xs font-bold rounded-lg border bg-white shadow-xs hover:bg-slate-50 transition-colors shrink-0 disabled:opacity-50"
              :class="{
                'text-rose-700 border-rose-300': settingsForm.announcement_severity === 'critical',
                'text-amber-800 border-amber-300': settingsForm.announcement_severity === 'warning',
                'text-blue-800 border-blue-300': !settingsForm.announcement_severity || settingsForm.announcement_severity === 'info'
              }"
            >
              Clear Broadcast
            </button>
          </div>

          <!-- Compose Announcement Form -->
          <div class="space-y-4">
            <div>
              <label for="announcement-message" class="block text-xs font-bold uppercase text-slate-600 mb-1.5 flex items-center justify-between">
                <span>Announcement Message</span>
                <span
                  class="text-xs font-mono font-semibold"
                  :class="announcementForm.message.length >= 190 ? 'text-rose-600 font-bold' : 'text-slate-400'"
                >
                  {{ announcementForm.message.length }}/200
                </span>
              </label>
              <textarea
                id="announcement-message"
                v-model="announcementForm.message"
                maxlength="200"
                rows="3"
                placeholder="e.g. Scheduled power maintenance: Cafeteria counter closes early at 18:00 today. Please claim orders by 17:45."
                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-medium text-slate-800 bg-white resize-none"
              ></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <!-- Severity Dropdown -->
              <div>
                <label for="announcement-severity" class="block text-xs font-bold uppercase text-slate-600 mb-1.5">
                  Severity Level
                </label>
                <select
                  id="announcement-severity"
                  v-model="announcementForm.severity"
                  class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-semibold text-slate-800 bg-white"
                >
                  <option value="info">Info (Informational Notification)</option>
                  <option value="warning">Warning (Operational Notice / Schedule Change)</option>
                  <option value="critical">Critical (Emergency / Urgent Action Required)</option>
                </select>
              </div>

              <!-- Expiry Input -->
              <div>
                <label for="announcement-expires-at" class="block text-xs font-bold uppercase text-slate-600 mb-1.5">
                  Expiry Date & Time (Optional)
                </label>
                <input
                  id="announcement-expires-at"
                  v-model="announcementForm.expires_at"
                  type="datetime-local"
                  class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-semibold text-slate-800 bg-white"
                />
              </div>
            </div>

            <!-- Publish Button -->
            <div class="flex items-center justify-end pt-2">
              <button
                id="btn-publish-announcement"
                type="button"
                @click="handlePublishAnnouncement"
                :disabled="savingAnnouncement || !announcementForm.message.trim()"
                class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-sm shadow-md transition-all active:scale-95 disabled:opacity-50 flex items-center gap-2"
              >
                <Megaphone :size="16" />
                <span>{{ savingAnnouncement ? "Publishing..." : "Publish Announcement" }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Feature Flags Section -->
    <div v-show="activeTab === 'flags'" class="space-y-8 mb-8">
      <!-- Alert / Notice inside Flags -->
      <div
        v-if="settingsError"
        class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm font-semibold flex items-center"
      >
        <AlertCircle :size="18" class="mr-2 shrink-0" />
        <span>{{ settingsError }}</span>
      </div>

      <!-- PANEL 2: FEATURE FLAGS & CONFIGURATION SAVE -->
      <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-indigo-100 text-indigo-800 rounded-xl">
              <Sliders :size="20" />
            </div>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Platform Feature Flags</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Enable or disable core system modules dynamically across the cafeteria ecosystem.
              </p>
            </div>
          </div>
          <span class="text-xs font-semibold text-slate-400">6 Global Feature Flags</span>
        </div>

        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              v-for="flag in featureFlagsList"
              :key="flag.key"
              class="flex items-center justify-between p-4 rounded-xl border transition-all"
              :class="settingsForm[flag.key] ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">{{ flag.label }}</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="settingsForm[flag.key] ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ settingsForm[flag.key] ? 'Enabled' : 'Disabled' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">{{ flag.description }}</p>
              </div>

              <button
                type="button"
                role="switch"
                :id="'toggle-' + flag.key"
                :aria-checked="settingsForm[flag.key]"
                @click="settingsForm[flag.key] = !settingsForm[flag.key]"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="settingsForm[flag.key] ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="settingsForm[flag.key] ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>
          </div>

          <!-- Save Configuration Action Button -->
          <div class="mt-6 pt-6 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="text-xs text-slate-500">
              <span v-if="settingsForm.updated_at" class="font-mono">
                Last persisted: {{ new Date(settingsForm.updated_at).toLocaleTimeString() }}
              </span>
              <span v-else>Click save to apply changes to feature flags.</span>
            </div>

            <button
              id="save-settings-btn"
              type="button"
              @click="handleSaveConfiguration"
              :disabled="savingSettings"
              class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow-md transition-all active:scale-95 disabled:opacity-50 flex items-center justify-center space-x-2 cursor-pointer"
            >
              <span
                v-if="savingSettings"
                class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"
              ></span>
              <Save v-else :size="16" />
              <span>{{ savingSettings ? "Saving Settings..." : "Save Configuration" }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Allergen Taxonomy Section -->
    <div v-show="activeTab === 'taxonomy'" class="mb-8">
      <Taxonomy />
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import Taxonomy from "./Taxonomy.vue";
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
  LayoutDashboard,
  Calendar,
  AlertTriangle,
  Info,
  Layers,
  ListTree,
} from "lucide-vue-next";

const activeTab = ref("overview");
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
  effective_closing_time: "20:00",
  override_closing_time: "20:00",
  override_expires_at: "",
  override_for_today: false,
  is_override_active: false,
  max_discount_qty: 5,
  ai_enabled: true,
  enable_reservations: true,
  enable_paymongo: true,
  enable_ai_recipe_generation: true,
  enable_global_announcements: true,
  enable_decay_pricing: true,
  enable_reservation_quota_suggestions: true,
  global_announcement: null,
  announcement_severity: "info",
  announcement_expires_at: null,
  updated_at: "",
});

const overrideClosingTimeEnabled = ref(false);

const announcementForm = reactive({
  message: "",
  severity: "info",
  expires_at: "",
});

const featureFlagsList = [
  {
    key: "enable_reservations",
    label: "Online Meal Reservations",
    description: "Allows students to pre-order, reserve, and schedule meal pickups in advance.",
  },
  {
    key: "enable_paymongo",
    label: "PayMongo Online Payments",
    description: "Enables digital payment gateway (GCash, Maya, Card) for express pickups.",
  },
  {
    key: "enable_ai_recipe_generation",
    label: "AI Recipe & Dish Generation",
    description: "Unlocks automated recipe breakdowns, macro calculations, and AI dish generation.",
  },
  {
    key: "enable_global_announcements",
    label: "Global Announcements Banner",
    description: "Enables platform-wide emergency, operational, and informational banner broadcasts.",
  },
  {
    key: "enable_decay_pricing",
    label: "Surplus Decay Dynamic Pricing",
    description: "Enables progressive price discounts on surplus meals approaching closing time.",
  },
  {
    key: "enable_reservation_quota_suggestions",
    label: "AI Reservation Quota Suggestions",
    description: "Provides smart inventory recommendations and portion quota suggestions for vendors.",
  },
];

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
    const s = data.settings || data.data || data;
    if (s) {
      Object.assign(settingsForm, s);

      // Handle override closing time format (HH:MM)
      if (s.override_closing_time) {
        settingsForm.override_closing_time = s.override_closing_time.slice(0, 5);
      } else {
        settingsForm.override_closing_time = (s.closing_time || "20:00").slice(0, 5);
      }

      // Handle override expiration date format (YYYY-MM-DD)
      const expires = s.override_expires_at || s.override_closing_date;
      if (expires) {
        settingsForm.override_expires_at = expires.slice(0, 10);
      } else {
        settingsForm.override_expires_at = "";
      }

      // Check whether override is currently enabled/active
      const todayManila = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Manila",
      }).format(new Date());
      const isDateActive = expires && expires.slice(0, 10) >= todayManila;

      overrideClosingTimeEnabled.value = Boolean(
        s.is_override_active || (isDateActive && s.override_closing_time)
      );

      // Cast boolean flags safely
      const boolKeys = [
        "enable_reservations",
        "enable_paymongo",
        "enable_ai_recipe_generation",
        "enable_global_announcements",
        "enable_decay_pricing",
        "enable_reservation_quota_suggestions",
        "ai_enabled",
      ];
      boolKeys.forEach((k) => {
        if (s[k] !== undefined) {
          settingsForm[k] = Boolean(s[k]);
        }
      });

      if (s.global_announcement) {
        announcementText.value = s.global_announcement;
      }
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
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        enable_ai_recipe_generation: targetState,
      }),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to update AI state.");
    }

    settingsForm.ai_enabled = targetState;
    settingsForm.enable_ai_recipe_generation = targetState;

    successMessage.value = `AI Generation ${settingsForm.ai_enabled ? "enabled" : "disabled"} successfully.`;
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);

    await fetchMetrics();
  } catch (err) {
    console.error("Error toggling AI:", err);
    pageError.value = err.message;
  } finally {
    togglingAi.value = false;
  }
};

const handleSaveConfiguration = async () => {
  settingsError.value = "";
  savingSettings.value = true;
  try {
    const payload = {
      override_closing_time: overrideClosingTimeEnabled.value
        ? settingsForm.override_closing_time
        : null,
      override_expires_at: overrideClosingTimeEnabled.value
        ? settingsForm.override_expires_at
        : null,
      enable_reservations: Boolean(settingsForm.enable_reservations),
      enable_paymongo: Boolean(settingsForm.enable_paymongo),
      enable_ai_recipe_generation: Boolean(settingsForm.enable_ai_recipe_generation),
      enable_global_announcements: Boolean(settingsForm.enable_global_announcements),
      enable_decay_pricing: Boolean(settingsForm.enable_decay_pricing),
      enable_reservation_quota_suggestions: Boolean(settingsForm.enable_reservation_quota_suggestions),
    };

    const res = await fetch("/api/admin/settings", {
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to update configuration.");
    }

    successMessage.value = "System configuration saved successfully!";
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);

    await fetchSettings();
  } catch (err) {
    console.error("Save settings error:", err);
    settingsError.value = err.message;
  } finally {
    savingSettings.value = false;
  }
};

const handleSaveSettings = handleSaveConfiguration;

const handlePublishAnnouncement = async () => {
  savingAnnouncement.value = true;
  pageError.value = "";
  try {
    const msg = announcementForm.message.trim();
    if (!msg) {
      throw new Error("Announcement message cannot be empty.");
    }

    let formattedExpiry = null;
    if (announcementForm.expires_at) {
      formattedExpiry = announcementForm.expires_at.replace("T", " ");
      if (formattedExpiry.length === 16) {
        formattedExpiry += ":00";
      }
    }

    const payload = {
      global_announcement: msg,
      announcement_severity: announcementForm.severity || "info",
      announcement_expires_at: formattedExpiry,
    };

    const res = await fetch("/api/admin/settings/announcement", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to publish announcement.");
    }

    // Clear form inputs on success as required:
    announcementForm.message = "";
    announcementForm.severity = "info";
    announcementForm.expires_at = "";

    successMessage.value = "Announcement published successfully!";
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);

    await fetchSettings();
  } catch (err) {
    console.error("Publish announcement error:", err);
    pageError.value = err.message;
  } finally {
    savingAnnouncement.value = false;
  }
};

const handleClearAnnouncement = async () => {
  savingAnnouncement.value = true;
  pageError.value = "";
  try {
    const res = await fetch("/api/admin/settings/announcement", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        global_announcement: "",
        announcement_severity: "info",
        announcement_expires_at: null,
      }),
    });

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || "Failed to clear announcement.");
    }

    successMessage.value = "Global announcement cleared successfully.";
    setTimeout(() => {
      successMessage.value = "";
    }, 4000);

    await fetchSettings();
  } catch (err) {
    pageError.value = err.message;
  } finally {
    savingAnnouncement.value = false;
  }
};
</script>
