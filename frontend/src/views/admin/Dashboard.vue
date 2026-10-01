<template>
  <div class="p-8">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">System Overview</h1>
        <p class="text-slate-500 mt-1 font-medium">Platform-wide metrics and activity summary.</p>
      </div>
      <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-lg font-bold shadow-sm transition-colors flex items-center space-x-2">
        <Download :size="18" />
        <span>Export Report</span>
      </button>
    </div>

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <!-- Active Stalls -->
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between">
        <div class="flex justify-between items-start mb-4">
          <div class="w-12 h-12 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
            <Store :size="24" />
          </div>
          <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-md">+0%</span>
        </div>
        <div>
          <p class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-1">Active Stalls</p>
          <div class="text-3xl font-black text-slate-900">1</div>
        </div>
      </div>

      <!-- Saved Meals / Waste Diverted -->
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between">
        <div class="flex justify-between items-start mb-4">
          <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600">
            <Leaf :size="24" />
          </div>
          <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-md">+14%</span>
        </div>
        <div>
          <p class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-1">Waste Diverted (Meals)</p>
          <div class="text-3xl font-black text-slate-900 tabular-nums">142</div>
        </div>
      </div>

      <!-- Total Revenue & Clearance Absorbed -->
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between">
        <div class="flex justify-between items-start mb-4">
          <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600">
            <TrendingUp :size="24" />
          </div>
          <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-md">+8%</span>
        </div>
        <div>
          <p class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-1">Gross Revenue (Today)</p>
          <div class="text-3xl font-black text-slate-900 tabular-nums">₱8,450</div>
          <p class="text-xs font-medium text-slate-500 mt-1">₱1,240 clearance discounts absorbed</p>
        </div>
      </div>

      <!-- Health Warning Triggers -->
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between">
        <div class="flex justify-between items-start mb-4">
          <div class="w-12 h-12 bg-rose-50 rounded-xl flex items-center justify-center text-rose-600">
            <AlertTriangle :size="24" />
          </div>
          <span class="text-xs font-bold text-rose-600 bg-rose-50 px-2 py-1 rounded-md">Action Req</span>
        </div>
        <div>
          <p class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-1">Allergen Triggers</p>
          <div class="text-3xl font-black text-slate-900 tabular-nums">12</div>
          <p class="text-xs font-medium text-slate-500 mt-1">Intercepted today</p>
        </div>
      </div>
    </div>

    <!-- Coming Soon Section for scaffold -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-12 text-center">
      <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-400 mx-auto mb-4">
        <Activity :size="32" />
      </div>
      <h3 class="text-xl font-bold text-slate-900 mb-2">Detailed Analytics Engine</h3>
      <p class="text-slate-500 max-w-md mx-auto">Full reporting charts, stall-by-stall breakdown, and cross-referenced allergen trends are being configured.</p>
    </div>
  </div>
</template>

<script setup>
import { Store, Leaf, TrendingUp, AlertTriangle, Download, Activity } from 'lucide-vue-next'
</script>
