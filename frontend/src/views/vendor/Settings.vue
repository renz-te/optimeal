<template>
  <div class="p-8 max-w-5xl mx-auto space-y-8 font-sans antialiased text-slate-900">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-5">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
          <Sliders class="text-emerald-600" :size="32" />
          <span>Vendor Settings & Operations</span>
        </h1>
        <p class="text-slate-500 mt-1 font-medium">
          Manage cafeteria branding, daily ordering cutoffs, stock buffers, and staff notification alerts.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <button
          id="refresh-settings-btn"
          @click="fetchSettings"
          :disabled="loading || saving"
          class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 active:scale-95 transition-all shadow-sm disabled:opacity-50"
        >
          <RefreshCw :size="16" :class="{ 'animate-spin': loading }" />
          <span>Refresh</span>
        </button>

        <button
          id="save-settings-btn"
          @click="saveSettings"
          :disabled="saving || loading"
          class="flex items-center gap-2 px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow-md hover:bg-emerald-700 active:scale-95 transition-all disabled:opacity-50"
        >
          <span v-if="saving" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
          <Save v-else :size="16" />
          <span>{{ saving ? 'Saving...' : 'Save Settings' }}</span>
        </button>
      </div>
    </div>

    <!-- Feedback Banners -->
    <div
      v-if="successToast"
      class="p-4 rounded-xl text-sm font-semibold flex items-center justify-between bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-sm transition-all"
    >
      <div class="flex items-center gap-2.5">
        <CheckCircle2 :size="18" class="text-emerald-600 shrink-0" />
        <span>{{ successToast }}</span>
      </div>
      <button @click="successToast = ''" class="text-emerald-600 hover:text-emerald-800">
        <X :size="16" />
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 rounded-xl text-sm font-semibold flex items-center justify-between bg-rose-50 text-rose-800 border border-rose-200 shadow-sm transition-all"
    >
      <div class="flex items-center gap-2.5">
        <AlertCircle :size="18" class="text-rose-600 shrink-0" />
        <span>{{ errorMessage }}</span>
      </div>
      <button @click="errorMessage = ''" class="text-rose-600 hover:text-rose-800">
        <X :size="16" />
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && !dataLoaded" class="py-20 text-center text-slate-400">
      <RefreshCw :size="36" class="animate-spin text-emerald-600 mx-auto mb-3" />
      <p class="font-medium text-base">Loading cafeteria settings & preferences...</p>
    </div>

    <!-- Main Settings Panels -->
    <div v-else class="space-y-8">
      <!-- 1. CAFETERIA IDENTITY PANEL -->
      <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="p-2.5 bg-emerald-50 text-emerald-700 rounded-xl border border-emerald-200">
              <Building2 :size="20" />
            </div>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Cafeteria Identity</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Branding, taglines, and direct contact channels visible to students and customers.
              </p>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
            Public Info
          </span>
        </div>

        <div class="p-6 sm:p-7 space-y-6">
          <!-- Tagline -->
          <div>
            <label for="vendor-tagline" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
              Cafeteria Tagline
            </label>
            <div class="relative">
              <input
                id="vendor-tagline"
                v-model="cafeteriaForm.tagline"
                type="text"
                maxlength="60"
                placeholder="e.g. Nutritious & Affordable Campus Dining"
                class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-sm text-slate-900 transition-all font-medium placeholder:text-slate-400"
              />
              <span class="absolute right-3.5 top-3.5 text-xs text-slate-400 font-mono">
                {{ cafeteriaForm.tagline?.length || 0 }}/60
              </span>
            </div>
            <p class="text-xs text-slate-500 mt-1.5 font-medium">
              A concise slogan displayed on the customer ordering banner and cafeteria menu header.
            </p>
          </div>

          <!-- Contact Email & Contact Number -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div>
              <label for="vendor-contact-email" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Contact Email
              </label>
              <div class="relative">
                <input
                  id="vendor-contact-email"
                  v-model="cafeteriaForm.contact_email"
                  type="email"
                  placeholder="canteen@optimeal.test"
                  class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-sm text-slate-900 transition-all font-medium placeholder:text-slate-400"
                />
              </div>
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Customer support email for order inquiries, disputes, or refund requests.
              </p>
            </div>

            <div>
              <label for="vendor-contact-number" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Contact Phone / Mobile
              </label>
              <div class="relative">
                <input
                  id="vendor-contact-number"
                  v-model="cafeteriaForm.contact_number"
                  type="text"
                  placeholder="e.g. 09123456789"
                  class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-sm text-slate-900 transition-all font-medium placeholder:text-slate-400"
                />
              </div>
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Emergency phone contact for kitchen operations and pickup window dispatch.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- 2. OPERATIONS & STOCK PANEL -->
      <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="p-2.5 bg-blue-50 text-blue-700 rounded-xl border border-blue-200">
              <Clock :size="20" />
            </div>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Operations & Stock Management</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Ordering cutoffs, counter throughput capacity, reservation quotas, and walk-in safety reserves.
              </p>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
            Kitchen Rules
          </span>
        </div>

        <div class="p-6 sm:p-7 space-y-6">
          <!-- Bounded Helper Box -->
          <div class="bg-amber-50/80 border border-amber-200/90 rounded-2xl p-4.5 flex items-start gap-3.5">
            <Info :size="20" class="text-amber-700 shrink-0 mt-0.5" />
            <div class="text-xs text-amber-900 leading-relaxed font-medium">
              <strong class="font-bold">Operating Hours Notice:</strong>
              Daily meal cutoffs are strictly bounded by the Admin's baseline opening (07:00) and closing (20:00) operating hours. 
              Orders placed after their respective meal cutoffs are restricted to prevent ingredient shortages and kitchen overload.
            </div>
          </div>

          <!-- Lunch & Dinner Cutoffs -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div>
              <label for="vendor-lunch-cutoff" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Lunch Pre-order Cutoff (HH:MM)
              </label>
              <input
                id="vendor-lunch-cutoff"
                v-model="cafeteriaForm.lunch_cutoff"
                type="time"
                required
                class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white transition-all"
              />
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Latest time students can submit lunch reservations (default: 09:00).
              </p>
            </div>

            <div>
              <label for="vendor-dinner-cutoff" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Dinner Pre-order Cutoff (HH:MM)
              </label>
              <input
                id="vendor-dinner-cutoff"
                v-model="cafeteriaForm.dinner_cutoff"
                type="time"
                required
                class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white transition-all"
              />
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Latest time students can submit dinner reservations (default: 14:00).
              </p>
            </div>
          </div>

          <div class="border-t border-slate-100 pt-6 grid grid-cols-1 sm:grid-cols-3 gap-6">
            <!-- Pickup Slot Capacity -->
            <div>
              <label for="vendor-slot-capacity" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Pickup Slot Capacity
              </label>
              <div class="relative">
                <input
                  id="vendor-slot-capacity"
                  v-model.number="cafeteriaForm.pickup_slot_capacity"
                  type="number"
                  min="1"
                  max="100"
                  class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white transition-all"
                />
                <span class="absolute right-3.5 top-3.5 text-xs text-slate-400 font-medium">orders</span>
              </div>
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Max concurrent orders per 15-minute pickup window to prevent queue bottlenecks.
              </p>
            </div>

            <!-- Walk-in Buffer Pct -->
            <div>
              <label for="vendor-walkin-buffer" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Walk-in Buffer
              </label>
              <div class="relative">
                <input
                  id="vendor-walkin-buffer"
                  v-model.number="cafeteriaForm.walkin_buffer_pct"
                  type="number"
                  min="0"
                  max="50"
                  class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white transition-all"
                />
                <span class="absolute right-3.5 top-3.5 text-xs text-slate-400 font-bold">%</span>
              </div>
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Percentage of daily cooked portions reserved strictly for walk-in counter sales (default: 20%).
              </p>
            </div>

            <!-- Default Quota -->
            <div>
              <label for="vendor-default-quota" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                Max Daily Quota
              </label>
              <div class="relative">
                <input
                  id="vendor-default-quota"
                  v-model.number="cafeteriaForm.default_quota"
                  type="number"
                  min="1"
                  max="50"
                  class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-base font-bold font-mono text-slate-900 bg-white transition-all"
                />
                <span class="absolute right-3.5 top-3.5 text-xs text-slate-400 font-medium">portions</span>
              </div>
              <p class="text-xs text-slate-500 mt-1.5 font-medium">
                Maximum portions a single customer can reserve per day across all dishes (default: 5).
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- 3. NOTIFICATIONS PANEL -->
      <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="p-2.5 bg-indigo-50 text-indigo-700 rounded-xl border border-indigo-200">
              <Bell :size="20" />
            </div>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Staff Notification Alerts</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Configure real-time alerts for kitchen events, order workflows, and stock depletion triggers.
              </p>
            </div>
          </div>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
            Vendor Preferences
          </span>
        </div>

        <div class="p-6 sm:p-7">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Toggle 1: Quota Hit -->
            <div
              class="flex items-center justify-between p-4.5 rounded-xl border transition-all"
              :class="notificationForm.notify_quota_hit ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">Quota Hit Alert</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="notificationForm.notify_quota_hit ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ notificationForm.notify_quota_hit ? 'Active' : 'Muted' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                  Trigger alerts when a dish reaches its allocated pre-order quota limit.
                </p>
              </div>

              <button
                type="button"
                role="switch"
                id="toggle-notify-quota-hit"
                :aria-checked="notificationForm.notify_quota_hit"
                @click="notificationForm.notify_quota_hit = !notificationForm.notify_quota_hit"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="notificationForm.notify_quota_hit ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="notificationForm.notify_quota_hit ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Toggle 2: Low Stock -->
            <div
              class="flex items-center justify-between p-4.5 rounded-xl border transition-all"
              :class="notificationForm.notify_low_stock ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">Low Stock Alert</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="notificationForm.notify_low_stock ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ notificationForm.notify_low_stock ? 'Active' : 'Muted' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                  Alert kitchen staff when available dish inventory drops into the safety buffer zone.
                </p>
              </div>

              <button
                type="button"
                role="switch"
                id="toggle-notify-low-stock"
                :aria-checked="notificationForm.notify_low_stock"
                @click="notificationForm.notify_low_stock = !notificationForm.notify_low_stock"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="notificationForm.notify_low_stock ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="notificationForm.notify_low_stock ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Toggle 3: New Order -->
            <div
              class="flex items-center justify-between p-4.5 rounded-xl border transition-all"
              :class="notificationForm.notify_order_status ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">New Incoming Orders</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="notificationForm.notify_order_status ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ notificationForm.notify_order_status ? 'Active' : 'Muted' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                  Sound chime and popup notification immediately upon new customer order creation.
                </p>
              </div>

              <button
                type="button"
                role="switch"
                id="toggle-notify-new-order"
                :aria-checked="notificationForm.notify_order_status"
                @click="notificationForm.notify_order_status = !notificationForm.notify_order_status"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="notificationForm.notify_order_status ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="notificationForm.notify_order_status ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Toggle 4: Order Claimed -->
            <div
              class="flex items-center justify-between p-4.5 rounded-xl border transition-all"
              :class="notificationForm.notify_order_claimed ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">Order Claimed / Picked Up</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="notificationForm.notify_order_claimed ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ notificationForm.notify_order_claimed ? 'Active' : 'Muted' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                  Notify dispatch counter when a student marks their meal claimed via PIN or QR code.
                </p>
              </div>

              <button
                type="button"
                role="switch"
                id="toggle-notify-order-claimed"
                :aria-checked="notificationForm.notify_order_claimed"
                @click="notificationForm.notify_order_claimed = !notificationForm.notify_order_claimed"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="notificationForm.notify_order_claimed ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="notificationForm.notify_order_claimed ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Toggle 5: Daily Audit Ready -->
            <div
              class="flex items-center justify-between p-4.5 rounded-xl border transition-all"
              :class="notificationForm.notify_daily_audit ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">Daily Audit Ready</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="notificationForm.notify_daily_audit ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ notificationForm.notify_daily_audit ? 'Active' : 'Muted' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                  Receive a prompt when the daily financial reconciliation & inventory audit is finalized.
                </p>
              </div>

              <button
                type="button"
                role="switch"
                id="toggle-notify-daily-audit"
                :aria-checked="notificationForm.notify_daily_audit"
                @click="notificationForm.notify_daily_audit = !notificationForm.notify_daily_audit"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="notificationForm.notify_daily_audit ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="notificationForm.notify_daily_audit ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Toggle 6: Surplus Decay Pricing -->
            <div
              class="flex items-center justify-between p-4.5 rounded-xl border transition-all"
              :class="notificationForm.notify_decay ? 'bg-emerald-50/30 border-emerald-200 shadow-xs' : 'bg-slate-50/70 border-slate-200'"
            >
              <div class="pr-4">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-bold text-slate-900">Surplus Price Decay</span>
                  <span
                    class="text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider"
                    :class="notificationForm.notify_decay ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-600'"
                  >
                    {{ notificationForm.notify_decay ? 'Active' : 'Muted' }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                  Receive notifications whenever automated end-of-day meal discount tiers trigger.
                </p>
              </div>

              <button
                type="button"
                role="switch"
                id="toggle-notify-decay"
                :aria-checked="notificationForm.notify_decay"
                @click="notificationForm.notify_decay = !notificationForm.notify_decay"
                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                :class="notificationForm.notify_decay ? 'bg-emerald-600' : 'bg-slate-300'"
              >
                <span
                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                  :class="notificationForm.notify_decay ? 'translate-x-7' : 'translate-x-0'"
                />
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Bottom Save Action Bar -->
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-slate-200">
        <p class="text-xs text-slate-500 font-medium">
          Settings are saved simultaneously to the cafeteria master configuration and user profile.
        </p>

        <button
          id="save-settings-bottom-btn"
          @click="saveSettings"
          :disabled="saving || loading"
          class="w-full sm:w-auto flex items-center justify-center gap-2 px-8 py-3 bg-emerald-600 text-white font-bold rounded-xl shadow-md hover:bg-emerald-700 active:scale-95 transition-all disabled:opacity-50"
        >
          <span v-if="saving" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
          <Save v-else :size="18" />
          <span>{{ saving ? 'Saving Changes...' : 'Save Settings' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import {
  Sliders,
  Building2,
  Clock,
  Bell,
  Save,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  X,
  Info
} from 'lucide-vue-next'

// UI state
const loading = ref(false)
const saving = ref(false)
const dataLoaded = ref(false)
const successToast = ref('')
const errorMessage = ref('')

// Reactive Form 1: Cafeteria Identity & Operations
const cafeteriaForm = reactive({
  tagline: '',
  contact_email: '',
  contact_number: '',
  lunch_cutoff: '09:00',
  dinner_cutoff: '14:00',
  pickup_slot_capacity: 10,
  walkin_buffer_pct: 20,
  default_quota: 5
})

// Reactive Form 2: Vendor User Notification Preferences
const notificationForm = reactive({
  notify_quota_hit: true,
  notify_low_stock: true,
  notify_order_status: true,
  notify_order_claimed: true,
  notify_daily_audit: true,
  notify_decay: true
})

/**
 * Concurrently fetch both cafeteria configuration and user preferences on mount
 */
const fetchSettings = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const [cafRes, prefRes] = await Promise.all([
      fetch('/api/vendor/cafeteria'),
      fetch('/api/user/preferences')
    ])

    if (cafRes.ok) {
      const cData = await cafRes.json()
      if (cData.tagline !== undefined) cafeteriaForm.tagline = cData.tagline || ''
      if (cData.contact_email !== undefined) cafeteriaForm.contact_email = cData.contact_email || ''
      if (cData.contact_number !== undefined) cafeteriaForm.contact_number = cData.contact_number || ''
      if (cData.lunch_cutoff) cafeteriaForm.lunch_cutoff = cData.lunch_cutoff.slice(0, 5)
      if (cData.dinner_cutoff) cafeteriaForm.dinner_cutoff = cData.dinner_cutoff.slice(0, 5)
      if (cData.pickup_slot_capacity !== undefined && cData.pickup_slot_capacity !== null) {
        cafeteriaForm.pickup_slot_capacity = Number(cData.pickup_slot_capacity)
      }
      if (cData.walkin_buffer_pct !== undefined && cData.walkin_buffer_pct !== null) {
        cafeteriaForm.walkin_buffer_pct = Number(cData.walkin_buffer_pct)
      }
      if (cData.default_quota !== undefined && cData.default_quota !== null) {
        cafeteriaForm.default_quota = Number(cData.default_quota)
      }
    } else {
      const err = await cafRes.json().catch(() => ({}))
      console.warn('Failed to fetch cafeteria details:', err.error)
    }

    if (prefRes.ok) {
      const pData = await prefRes.json()
      if (typeof pData.notify_quota_hit === 'boolean') {
        notificationForm.notify_quota_hit = pData.notify_quota_hit
      }
      if (typeof pData.notify_low_stock === 'boolean') {
        notificationForm.notify_low_stock = pData.notify_low_stock
      }
      if (typeof pData.notify_order_status === 'boolean') {
        notificationForm.notify_order_status = pData.notify_order_status
        notificationForm.notify_order_claimed = pData.notify_order_status
      }
      if (typeof pData.notify_daily_audit === 'boolean') {
        notificationForm.notify_daily_audit = pData.notify_daily_audit
      }
      if (typeof pData.notify_decay === 'boolean') {
        notificationForm.notify_decay = pData.notify_decay
      }
    } else {
      const err = await prefRes.json().catch(() => ({}))
      console.warn('Failed to fetch user preferences:', err.error)
    }

    dataLoaded.value = true
  } catch (err) {
    console.error('Error fetching vendor settings:', err)
    errorMessage.value = err.message || 'Failed to retrieve vendor settings.'
  } finally {
    loading.value = false
  }
}

/**
 * Save action simultaneously updating cafeteria and user preferences via Promise.all
 */
const saveSettings = async () => {
  saving.value = true
  errorMessage.value = ''
  successToast.value = ''

  try {
    const cafeteriaPayload = {
      tagline: cafeteriaForm.tagline?.trim() || null,
      contact_email: cafeteriaForm.contact_email?.trim() || null,
      contact_number: cafeteriaForm.contact_number?.trim() || null,
      lunch_cutoff: cafeteriaForm.lunch_cutoff,
      dinner_cutoff: cafeteriaForm.dinner_cutoff,
      pickup_slot_capacity: Number(cafeteriaForm.pickup_slot_capacity),
      walkin_buffer_pct: Number(cafeteriaForm.walkin_buffer_pct),
      default_quota: Number(cafeteriaForm.default_quota)
    }

    const prefPayload = {
      notify_quota_hit: Boolean(notificationForm.notify_quota_hit),
      notify_low_stock: Boolean(notificationForm.notify_low_stock),
      notify_order_status: Boolean(notificationForm.notify_order_status || notificationForm.notify_order_claimed),
      notify_daily_audit: Boolean(notificationForm.notify_daily_audit),
      notify_decay: Boolean(notificationForm.notify_decay)
    }

    const cafPromise = fetch('/api/vendor/cafeteria', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(cafeteriaPayload)
    })

    const prefPromise = fetch('/api/user/preferences', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(prefPayload)
    })

    const [cafRes, prefRes] = await Promise.all([cafPromise, prefPromise])

    if (!cafRes.ok) {
      const err = await cafRes.json().catch(() => ({}))
      throw new Error(err.error || 'Failed to update cafeteria operations.')
    }

    if (!prefRes.ok) {
      const err = await prefRes.json().catch(() => ({}))
      throw new Error(err.error || 'Failed to update notification preferences.')
    }

    successToast.value = 'Vendor settings and notification preferences saved successfully!'
    setTimeout(() => {
      if (successToast.value) {
        successToast.value = ''
      }
    }, 4000)
  } catch (err) {
    console.error('Save settings error:', err)
    errorMessage.value = err.message || 'An unexpected error occurred while saving.'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchSettings()
})
</script>
