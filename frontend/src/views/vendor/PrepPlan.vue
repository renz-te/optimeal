<template>
  <div class="p-8 pb-32 font-sans antialiased text-slate-900 max-w-6xl mx-auto h-full flex flex-col space-y-8">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Kitchen Prep Plan & Margin Tracker</h1>
          <span class="text-xs font-bold bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded-full border border-emerald-300">
            Phase B4.12
          </span>
        </div>
        <p class="text-slate-500 font-medium mt-1">
          Evaluate Cost of Goods (COG), projected batch revenue, and the financial impact of rescued versus discarded surplus food.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <router-link
          to="/vendor/roster"
          class="flex items-center space-x-2 bg-white text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl font-bold hover:bg-slate-50 transition-colors shadow-xs"
        >
          <ArrowLeft :size="16" />
          <span>Daily Lineup Roster</span>
        </router-link>
        <button
          @click="fetchData"
          :disabled="loading"
          class="flex items-center space-x-2 bg-slate-900 text-white px-5 py-2.5 rounded-xl font-bold shadow-md hover:bg-slate-800 transition-colors disabled:opacity-50"
        >
          <RefreshCw :size="16" :class="{ 'animate-spin': loading }" />
          <span>{{ loading ? 'Recalculating...' : 'Refresh Prep Plan' }}</span>
        </button>
      </div>
    </div>

    <!-- Planned Batch Servings Scaler -->
    <div class="bg-white border border-slate-200 shadow-sm rounded-2xl p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
      <div>
        <div class="flex items-center gap-2">
          <h3 class="text-base font-bold text-slate-900">Planned Batch Servings Scale</h3>
          <span class="text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full font-mono">
            {{ plannedServings }} portions / dish
          </span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Adjust scaling to see how kitchen raw inventory costs and margin thresholds scale dynamically.</p>
      </div>
      <div class="flex items-center gap-3 w-full md:w-auto">
        <span class="text-xs font-bold text-slate-400">10</span>
        <input 
          type="range" 
          min="10" 
          max="200" 
          step="5" 
          v-model.number="plannedServings" 
          @change="onSliderChange"
          class="w-full md:w-64 h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-emerald-600"
        />
        <span class="text-xs font-bold text-slate-400">200</span>
      </div>
    </div>

    <!-- 1. HIGH-LEVEL FINANCIAL MARGIN SUMMARY CARDS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Card 1: Cook Servings -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between text-slate-400 text-xs font-bold uppercase tracking-wider mb-2">
          <span>Cook Servings</span>
          <Utensils :size="18" class="text-slate-500" />
        </div>
        <div>
          <div class="text-3xl font-black text-slate-900">{{ totalCookServings }} <span class="text-base font-normal text-slate-400">servings</span></div>
          <p class="text-xs text-slate-500 mt-1">{{ prepPlan.total_dishes || 0 }} active menu viands</p>
        </div>
      </div>

      <!-- Card 2: Cost of Goods (COG) -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between text-rose-600 text-xs font-bold uppercase tracking-wider mb-2">
          <span>Cost of Goods (COG)</span>
          <Coins :size="18" class="text-rose-500" />
        </div>
        <div>
          <div class="text-3xl font-black text-rose-700">₱{{ formatCurrency(prepPlan.grand_total) }}</div>
          <p class="text-xs text-slate-500 mt-1">Raw ingredient cost to prepare</p>
        </div>
      </div>

      <!-- Card 3: Projected Full Revenue -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between text-emerald-600 text-xs font-bold uppercase tracking-wider mb-2">
          <span>Projected Revenue</span>
          <TrendingUp :size="18" class="text-emerald-500" />
        </div>
        <div>
          <div class="text-3xl font-black text-emerald-800">₱{{ formatCurrency(projectedRevenue) }}</div>
          <p class="text-xs text-slate-500 mt-1">At base retail prices</p>
        </div>
      </div>

      <!-- Card 4: Potential Gross Margin -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between text-indigo-600 text-xs font-bold uppercase tracking-wider mb-2">
          <span>Potential Gross Profit</span>
          <BarChart3 :size="18" class="text-indigo-500" />
        </div>
        <div>
          <div class="text-3xl font-black text-indigo-800">₱{{ formatCurrency(potentialProfit) }}</div>
          <p class="text-xs text-slate-500 mt-1">
            <span class="font-bold text-emerald-600">{{ marginPercent }}%</span> gross margin
          </p>
        </div>
      </div>
    </div>

    <!-- 2. RESCUED VS. DISCARDED FOOD FINANCIAL IMPACT DEMONSTRATION -->
    <div class="bg-gradient-to-br from-slate-900 to-slate-800 text-white p-6 sm:p-8 rounded-3xl shadow-xl overflow-hidden relative">
      <div class="relative z-10 space-y-6">
        <div>
          <div class="flex items-center space-x-2 text-emerald-400 text-xs font-bold uppercase tracking-wider mb-1.5">
            <ShieldAlert :size="16" />
            <span>Financial Waste Mitigation Engine</span>
          </div>
          <h2 class="text-2xl font-black tracking-tight text-white">The Cost of Discarding vs. Rescuing Food</h2>
          <p class="text-xs text-slate-300 mt-1 max-w-2xl">
            When portions go unsold at closing, discarding them turns 100% of the raw Cost of Goods (COG) into an unrecoverable cash loss. Rescuing portions through time-decay discounting recovers your ingredient investment and generates gross margin.
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Scenario A: Full Retail -->
          <div class="bg-white/10 backdrop-blur-md rounded-2xl p-5 border border-white/10 flex flex-col justify-between">
            <div>
              <div class="text-xs font-bold text-slate-300 uppercase tracking-wider">Scenario A: 100% Sold Regular</div>
              <div class="text-2xl font-black text-white mt-1">₱{{ formatCurrency(projectedRevenue) }}</div>
              <p class="text-xs text-slate-300 mt-2">All portions sold during peak lunch rush at full price.</p>
            </div>
            <div class="mt-4 pt-3 border-t border-white/10 text-xs space-y-1">
              <div class="flex justify-between text-slate-300"><span>Raw COG:</span> <span>-₱{{ formatCurrency(prepPlan.grand_total) }}</span></div>
              <div class="flex justify-between text-emerald-400 font-bold"><span>Gross Profit:</span> <span>+₱{{ formatCurrency(potentialProfit) }}</span></div>
            </div>
          </div>

          <!-- Scenario B: 50% Rescued via Decay Discount -->
          <div class="bg-emerald-950/60 backdrop-blur-md rounded-2xl p-5 border border-emerald-500/40 flex flex-col justify-between ring-2 ring-emerald-500/30">
            <div>
              <div class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-400 uppercase tracking-wider">
                <Check :size="14" />
                <span>Scenario B: 50% Rescued</span>
              </div>
              <div class="text-2xl font-black text-emerald-300 mt-1">₱{{ formatCurrency(rescuedRevenue) }}</div>
              <p class="text-xs text-slate-300 mt-2">50% sold at peak, remaining 50% rescued at 35% closing discount.</p>
            </div>
            <div class="mt-4 pt-3 border-t border-emerald-500/20 text-xs space-y-1">
              <div class="flex justify-between text-slate-300"><span>Raw COG:</span> <span>-₱{{ formatCurrency(prepPlan.grand_total) }} (Recovered)</span></div>
              <div class="flex justify-between text-teal-300 font-bold"><span>Net Profit:</span> <span>+₱{{ formatCurrency(rescuedProfit) }}</span></div>
            </div>
          </div>

          <!-- Scenario C: 50% Discarded Loss -->
          <div class="bg-rose-950/60 backdrop-blur-md rounded-2xl p-5 border border-rose-500/40 flex flex-col justify-between ring-2 ring-rose-500/30">
            <div>
              <div class="inline-flex items-center gap-1.5 text-xs font-bold text-rose-400 uppercase tracking-wider">
                <AlertTriangle :size="14" />
                <span>Scenario C: 50% Discarded</span>
              </div>
              <div class="text-2xl font-black text-rose-400 mt-1">-₱{{ formatCurrency(discardedLoss) }}</div>
              <p class="text-xs text-slate-300 mt-2">50% sold regular, remaining 50% discarded to waste bin.</p>
            </div>
            <div class="mt-4 pt-3 border-t border-rose-500/20 text-xs space-y-1">
              <div class="flex justify-between text-slate-300"><span>Unrecovered COG Loss:</span> <span class="text-rose-400 font-bold">-₱{{ formatCurrency(discardedLoss) }}</span></div>
              <div class="flex justify-between text-slate-300 font-medium"><span>Net Retained Margin:</span> <span>+₱{{ formatCurrency(discardNetMargin) }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. DISH MARGINS & BATCH SUMMARY TABLE -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
          <h3 class="text-lg font-black text-slate-900">Batch Dish Margins & Unit Breakdown</h3>
          <p class="text-xs text-slate-500 mt-0.5">Physical Cost of Goods per portion versus full retail price and discard write-off.</p>
        </div>
        <span class="text-xs font-bold px-3 py-1 bg-slate-100 rounded-full text-slate-600">
          {{ dishesList.length }} viands planned
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-wider">
            <tr>
              <th class="px-6 py-3.5">Viand Name</th>
              <th class="px-4 py-3.5 text-center">Planned Cook</th>
              <th class="px-4 py-3.5 text-right">Est. Unit COG</th>
              <th class="px-4 py-3.5 text-right">Retail Base Price</th>
              <th class="px-4 py-3.5 text-right">Unit Margin</th>
              <th class="px-4 py-3.5 text-right text-rose-600">Discard Loss / Port</th>
              <th class="px-4 py-3.5 text-right text-emerald-700">Rescued Net / Port</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="dishesList.length === 0">
              <td colspan="7" class="text-center py-8 text-slate-400 font-medium">
                No active dishes found in prep plan. Select dishes on the daily roster to view margins.
              </td>
            </tr>
            <tr v-for="d in dishesList" :key="d.menu_item_id" class="hover:bg-slate-50/50 transition-colors">
              <td class="px-6 py-4 font-bold text-slate-900">
                <div>{{ d.name }}</div>
                <div v-if="!d.has_recipe" class="text-[11px] text-amber-600 font-semibold flex items-center gap-1 mt-0.5">
                  <AlertTriangle :size="11" /> No recipe linked
                </div>
              </td>
              <td class="px-4 py-4 text-center font-black text-slate-800">
                {{ d.cook_servings }}
              </td>
              <td class="px-4 py-4 text-right font-mono font-bold text-slate-700">
                ₱{{ formatCurrency(d.unit_cog) }}
              </td>
              <td class="px-4 py-4 text-right font-mono font-bold text-slate-900">
                ₱{{ formatCurrency(d.base_price) }}
              </td>
              <td class="px-4 py-4 text-right font-mono font-bold text-emerald-700">
                +₱{{ formatCurrency(d.base_price - d.unit_cog) }}
                <span class="text-[10px] text-emerald-600 block">{{ d.margin_percent }}%</span>
              </td>
              <td class="px-4 py-4 text-right font-mono font-bold text-rose-600 bg-rose-50/30">
                -₱{{ formatCurrency(d.unit_cog) }}
              </td>
              <td class="px-4 py-4 text-right font-mono font-bold text-emerald-800 bg-emerald-50/30">
                +₱{{ formatCurrency((d.base_price * 0.65) - d.unit_cog) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 4. RAW INGREDIENT REQUIREMENTS TABLE -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
          <h3 class="text-lg font-black text-slate-900">Consolidated Raw Ingredient Requirements</h3>
          <p class="text-xs text-slate-500 mt-0.5">Aggregated kitchen requisition quantities and cost items scaled for this prep cycle.</p>
        </div>
        <span class="text-xs font-bold px-3 py-1 bg-indigo-50 text-indigo-700 rounded-full border border-indigo-200">
          {{ prepPlan.prep_list?.length || 0 }} total ingredients
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-wider">
            <tr>
              <th class="px-6 py-3.5">Ingredient Name</th>
              <th class="px-4 py-3.5 text-right">Required Quantity</th>
              <th class="px-4 py-3.5 text-right">Estimated Cost</th>
              <th class="px-4 py-3.5">Used in Viands</th>
              <th class="px-4 py-3.5 text-center">Taxonomy</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!prepPlan.prep_list || prepPlan.prep_list.length === 0">
              <td colspan="5" class="text-center py-8 text-slate-400 font-medium">
                No raw ingredients found in prep plan.
              </td>
            </tr>
            <tr v-for="item in prepPlan.prep_list" :key="item.name + item.dimension" class="hover:bg-slate-50/50">
              <td class="px-6 py-3.5 font-bold text-slate-900">
                {{ item.name }}
              </td>
              <td class="px-4 py-3.5 text-right font-black text-emerald-700">
                {{ item.total_quantity }} {{ item.unit }}
              </td>
              <td class="px-4 py-3.5 text-right font-mono font-bold text-slate-800">
                <span v-if="item.has_price && item.estimated_cost !== null">
                  ₱{{ formatCurrency(item.estimated_cost) }}
                </span>
                <span v-else class="text-xs text-amber-700 font-semibold bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                  Missing price
                </span>
              </td>
              <td class="px-4 py-3.5 text-xs text-slate-600">
                <div class="flex flex-wrap gap-1">
                  <span v-for="d in item.used_in_dishes" :key="d" class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-medium">
                    {{ d }}
                  </span>
                </div>
              </td>
              <td class="px-4 py-3.5 text-center">
                <span v-if="item.is_unmapped" class="text-[10px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200">
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Utensils, Coins, TrendingUp, BarChart3, ShieldAlert, Check, AlertTriangle, ArrowLeft, RefreshCw } from 'lucide-vue-next'

const loading = ref(false)
const plannedServings = ref(20)
const catalogDishes = ref([])
const prepPlan = ref({
  total_dishes: 0,
  total_planned_servings: 0,
  total_cook_servings: 0,
  dishes: [],
  needs_recipe: [],
  prep_list: [],
  grand_total: 0,
  is_partial: false
})

const formatCurrency = (val) => {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const fetchData = async () => {
  loading.value = true
  try {
    const [catRes, prepRes] = await Promise.all([
      fetch('/api/vendor/menu'),
      fetch('/api/vendor/prep-plan')
    ])

    if (catRes.ok) {
      catalogDishes.value = await catRes.json()
    }
    if (prepRes.ok) {
      prepPlan.value = await prepRes.json()
    }
  } catch (err) {
    console.error('Error fetching prep plan data:', err)
  } finally {
    loading.value = false
  }
}

const onSliderChange = async () => {
  loading.value = true
  try {
    // Re-query prep plan scaled for all active dishes
    const items = catalogDishes.value
      .filter(d => d.is_active)
      .map(d => ({ menu_item_id: d.id, servings: plannedServings.value }))

    const res = await fetch('/api/vendor/prep-plan', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ items })
    })
    if (res.ok) {
      prepPlan.value = await res.json()
    }
  } catch (err) {
    console.error('Failed to scale prep plan:', err)
  } finally {
    loading.value = false
  }
}

const totalCookServings = computed(() => {
  return prepPlan.value.total_cook_servings ?? prepPlan.value.total_planned_servings ?? 0
})

const projectedRevenue = computed(() => {
  let sum = 0
  if (prepPlan.value.dishes) {
    prepPlan.value.dishes.forEach(d => {
      const dish = catalogDishes.value.find(m => m.id === d.menu_item_id)
      const price = Number(dish?.current_price ?? (dish?.base_price ?? 50))
      sum += price * Number(d.cook_servings || 0)
    })
  }
  if (prepPlan.value.needs_recipe) {
    prepPlan.value.needs_recipe.forEach(d => {
      const dish = catalogDishes.value.find(m => m.id === d.menu_item_id)
      const price = Number(dish?.current_price ?? (dish?.base_price ?? 50))
      sum += price * Number(d.cook_servings || 0)
    })
  }
  return sum
})

const potentialProfit = computed(() => {
  return Math.max(0, projectedRevenue.value - Number(prepPlan.value.grand_total || 0))
})

const marginPercent = computed(() => {
  if (projectedRevenue.value <= 0) return 0
  return Math.round((potentialProfit.value / projectedRevenue.value) * 100)
})

// Mitigation scenarios math:
const rescuedRevenue = computed(() => {
  // 50% sold at full, 50% sold at 35% discount (0.65 of price)
  return projectedRevenue.value * 0.5 + (projectedRevenue.value * 0.5 * 0.65)
})

const rescuedProfit = computed(() => {
  return Math.max(0, rescuedRevenue.value - Number(prepPlan.value.grand_total || 0))
})

const discardedLoss = computed(() => {
  // 50% of raw COG thrown into garbage
  return Number(prepPlan.value.grand_total || 0) * 0.5
})

const discardNetMargin = computed(() => {
  // 50% sold regular, but 100% of raw COG spent
  const partialRev = projectedRevenue.value * 0.5
  return partialRev - Number(prepPlan.value.grand_total || 0)
})

// Detailed dishes list with unit COG math
const dishesList = computed(() => {
  const result = []
  const allDishes = [
    ...(prepPlan.value.dishes || []),
    ...(prepPlan.value.needs_recipe || [])
  ]

  const totalCook = totalCookServings.value || 1
  const avgUnitCog = Number(prepPlan.value.grand_total || 0) / totalCook

  allDishes.forEach(d => {
    const dishObj = catalogDishes.value.find(m => m.id === d.menu_item_id)
    const basePrice = Number(dishObj?.current_price ?? (dishObj?.base_price ?? 50))
    const unitCog = Math.min(basePrice * 0.5, avgUnitCog > 0 ? avgUnitCog : basePrice * 0.4)
    const margin = basePrice - unitCog
    const marginPct = basePrice > 0 ? Math.round((margin / basePrice) * 100) : 0

    result.push({
      menu_item_id: d.menu_item_id,
      name: d.name,
      cook_servings: d.cook_servings,
      has_recipe: d.has_recipe ?? false,
      base_price: basePrice,
      unit_cog: unitCog,
      margin_percent: marginPct
    })
  })

  return result
})

onMounted(() => {
  fetchData()
})
</script>
