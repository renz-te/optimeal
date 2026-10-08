<template>
  <div class="p-8">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">System Overview</h1>
        <p class="text-slate-500 mt-1 font-medium">Platform-wide metrics and activity summary.</p>
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
import { Activity } from 'lucide-vue-next'
</script>
