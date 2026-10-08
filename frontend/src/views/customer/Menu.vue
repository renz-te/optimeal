<template>
  <div class="font-sans antialiased text-slate-900">
    <div v-if="isAdmin && activePreviewMode === 'customer'" class="bg-indigo-600 text-white p-3 mb-6 -mx-4 -mt-4 text-center text-sm font-bold flex justify-center items-center gap-2 shadow-sm relative z-50">
      <span>Sandbox Preview: Viewing menu as simulated customer.</span>
    </div>
    
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Today's Menu</h2>
      <button v-if="cart.length > 0" @click="showPlateDrawer = true" class="hidden md:flex items-center space-x-2 bg-emerald-100 text-emerald-700 px-4 py-3 rounded-full font-bold hover:bg-emerald-200 transition-colors shadow-sm">
        <Utensils :size="18" />
        <span>Plate ({{ totalCartItems }})</span>
      </button>
    </div>

    <div v-if="userDangerMatches.length > 0" class="bg-red-600 text-white p-4 mb-6 rounded-lg shadow-md flex items-center justify-between">
      <div class="flex items-center">
        <AlertTriangle :size="24" class="mr-3 text-red-100" />
        <div>
          <h3 class="font-black text-lg">CRITICAL ALLERGEN ALERT</h3>
          <p class="font-medium text-red-100">Plate contains severe allergens: {{ userDangerMatches.join(', ') }}</p>
        </div>
      </div>
    </div>
    <div v-else-if="userCautionMatches.length > 0 && !dismissedSoftWarning" class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-4 mb-6 rounded-lg shadow-md flex items-center justify-between">
      <div class="flex items-center">
        <Info :size="24" class="mr-3 text-yellow-500" />
        <div>
          <h3 class="font-bold">Dietary Notice</h3>
          <p class="text-sm text-yellow-700 font-medium">Contains items known to trigger digestive sensitivities: {{ userCautionMatches.join(', ') }}</p>
        </div>
      </div>
      <button @click="dismissedSoftWarning = true" class="text-yellow-500 hover:text-yellow-700"><X :size="20"/></button>
    </div>

    <!-- Filters Area -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
      <!-- Category Filters -->
      <div class="flex overflow-x-auto pb-2 gap-2 hide-scrollbar w-full sm:w-auto">
        <button v-for="cat in categories" :key="cat"
                @click="selectedCategory = cat"
                class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors"
                :class="selectedCategory === cat ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'">
          {{ cat }}
        </button>
      </div>

      <!-- Calorie Filter -->
      <div class="flex items-center space-x-2 w-full sm:w-auto flex-shrink-0">
        <label class="text-sm font-bold text-slate-700 whitespace-nowrap">Max Est. Calories:</label>
        <div class="relative w-32">
          <input type="number" v-model.number="maxCalories" placeholder="e.g. 500" class="w-full pl-3 pr-8 py-2 border border-slate-300 rounded-full outline-none focus:border-emerald-500 font-medium text-sm">
          <button v-if="maxCalories" @click="maxCalories = null" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
            <X :size="14" />
          </button>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="item in displayDishes" :key="item.id" 
           class="bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow relative overflow-hidden flex flex-col"
           :class="[
             (item.allergens && item.allergens.length > 0) ? 'opacity-80' : '',
             getMatches(item).length > 0 ? 'opacity-75 border-2 border-rose-200 bg-rose-50/30' : 'border border-slate-200'
           ]">
           
        <div class="h-40 bg-slate-100 w-full relative">
          <!-- Image placeholder -->
          <div v-if="!item.image_url" class="absolute inset-0 flex items-center justify-center text-gray-300">
            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
          </div>
          <!-- Real image if exists -->
          <img v-else :src="item.image_url" class="w-full h-full object-cover" :alt="item.name">

          <div v-if="isClearance(item)" class="absolute top-3 right-3 bg-red-600 text-white text-xs font-bold px-3 py-1.5 rounded-full shadow-lg animate-pulse flex items-center">
            <Clock :size="14" class="mr-1" />
            {{ calculateDiscountPercent(item) }}% OFF (Surplus Decay)
          </div>
        </div>

        <div class="p-5 flex-1 flex flex-col">
          <h3 class="text-lg font-semibold text-slate-900 mb-1">{{ item.name }}</h3>
          <p class="text-sm text-slate-600 mb-3">{{ item.description }}</p>
          
          <div v-if="item.macros && item.macros.calories" class="mb-2">
            <div class="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-1 rounded inline-block border border-slate-200">
              Est. {{ item.macros.calories }} kcal • {{ item.macros.protein_g }}g Pro • {{ item.macros.carbs_g }}g Carbs • {{ item.macros.fat_g }}g Fat • {{ item.macros.sodium_mg }}mg Sod
            </div>
          </div>
          
          <div v-if="item.ingredients && item.ingredients.length" class="mb-3">
            <p class="text-[11px] text-slate-500 line-clamp-2 leading-snug">
              <span class="font-semibold text-slate-700">Ingredients:</span> {{ item.ingredients.join(', ') }}
            </p>
          </div>
          
          <div class="flex-1"></div>

          <div class="flex items-end space-x-2 mb-4">
            <span class="text-3xl font-semibold tabular-nums text-slate-900">₱{{ item.current_price.toFixed(2) }}</span>
            <span v-if="isClearance(item)" class="text-sm font-semibold tabular-nums line-through text-slate-400 mb-1">₱{{ item.base_price }}</span>
          </div>

          <div v-if="getMatches(item).length" class="mt-2 flex flex-wrap gap-1">
            <span v-for="tag in getMatches(item)" :key="tag" class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded">
              <template v-if="tag.startsWith('Watchlist:')">
                Matches {{ tag }}
              </template>
              <template v-else>
                Contains: {{ tag }}
              </template>
            </span>
          </div>

          <div class="mb-5 flex flex-wrap gap-1.5">
            <span v-if="(!item.critical_allergens?.length && !item.allergens?.length) && (!item.digestive_sensitivities?.length && !item.sensitivities?.length)" class="inline-flex items-center bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] uppercase font-bold px-2 py-0.5 rounded-full">
              Safe / No Common Allergens
            </span>
            <template v-else>
              <span v-for="a in (item.critical_allergens || item.allergens || [])" :key="'c-'+a" class="inline-flex items-center bg-rose-50 text-rose-700 border border-rose-200 text-[10px] uppercase font-bold px-2 py-0.5 rounded-full">
                <AlertTriangle :size="14" class="text-rose-600 inline mr-1" /> {{ a }}
              </span>
              <span v-for="s in (item.digestive_sensitivities || item.sensitivities || [])" :key="'s-'+s" class="inline-flex items-center bg-amber-50 text-amber-700 border border-amber-200 text-[10px] uppercase font-bold px-2 py-0.5 rounded-full">
                 {{ s }}
              </span>
            </template>
          </div>

          <button @click="openItemModal(item)" class="w-full font-bold py-3 rounded-lg transition-all shadow-sm flex items-center justify-center" 
                  :class="item.stock > 0 ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-slate-100 text-slate-400 cursor-not-allowed'"
                  :disabled="item.stock <= 0">
            {{ item.stock > 0 ? 'Select Options' : 'Sold Out' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Item Detail Slide-Over Drawer -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="selectedItem" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-40 transition-opacity" @click="selectedItem = null"></div>
    </transition>
    
    <transition enter-active-class="transition transform duration-300 ease-out" leave-active-class="transition transform duration-300 ease-in" enter-from-class="translate-y-full md:translate-y-0 md:translate-x-full" leave-to-class="translate-y-full md:translate-y-0 md:translate-x-full">
      <div v-if="selectedItem" class="fixed right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl z-50 flex flex-col">
        
        <!-- Zone 1: Pinned Header -->
        <div class="flex-shrink-0 p-6 border-b border-slate-100 relative">
          <button @click="selectedItem = null" class="absolute top-6 right-6 p-2 rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
            <X :size="20" />
          </button>
          <div class="pr-12">
            <h3 class="text-2xl font-black mb-2 text-slate-900">{{ selectedItem.name }}</h3>
            <p class="text-sm text-slate-600 mb-4 leading-relaxed">{{ selectedItem.description }}</p>
            <div v-if="selectedItem.stock > 0" class="inline-block bg-slate-100 px-3 py-1.5 rounded-full text-xs font-bold text-slate-700 shadow-sm border border-slate-200">
              Stock: {{ selectedItem.stock }} left
            </div>
            <div v-else class="inline-block bg-slate-100 px-3 py-1.5 rounded-full text-xs font-bold text-slate-500 shadow-sm border border-slate-200">
              Out of Stock
            </div>
          </div>
        </div>

        <!-- Zone 2: Scrollable Body -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
          <!-- Dish Ingredients -->
          <div>
            <label class="block text-sm font-bold text-slate-800 mb-3">Dish Ingredients</label>
            <div class="flex flex-wrap gap-2">
              <span v-for="ing in (selectedItem.ingredients || [])" :key="'ing-'+ing" class="bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold px-2.5 py-1 rounded-md">
                {{ ing }}
              </span>
              <span v-if="!selectedItem.ingredients || selectedItem.ingredients.length === 0" class="text-slate-500 text-sm italic">Not specified</span>
            </div>
          </div>
          
          <!-- Macros -->
          <div v-if="selectedItem.macros && selectedItem.macros.calories">
            <label class="block text-sm font-bold text-slate-800 mb-2">Estimated Macros</label>
            <div class="grid grid-cols-5 gap-2 text-center">
              <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                <div class="text-[9px] uppercase text-slate-500 font-bold mb-0.5 tracking-wider">Calories</div>
                <div class="text-xs font-black text-slate-900">{{ selectedItem.macros.calories }}</div>
              </div>
              <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                <div class="text-[9px] uppercase text-slate-500 font-bold mb-0.5 tracking-wider">Protein</div>
                <div class="text-xs font-black text-slate-900">{{ selectedItem.macros.protein_g }}g</div>
              </div>
              <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                <div class="text-[9px] uppercase text-slate-500 font-bold mb-0.5 tracking-wider">Carbs</div>
                <div class="text-xs font-black text-slate-900">{{ selectedItem.macros.carbs_g }}g</div>
              </div>
              <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                <div class="text-[9px] uppercase text-slate-500 font-bold mb-0.5 tracking-wider">Fat</div>
                <div class="text-xs font-black text-slate-900">{{ selectedItem.macros.fat_g }}g</div>
              </div>
              <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                <div class="text-[9px] uppercase text-slate-500 font-bold mb-0.5 tracking-wider">Sodium</div>
                <div class="text-xs font-black text-slate-900">{{ selectedItem.macros.sodium_mg }}mg</div>
              </div>
            </div>
          </div>
          
          <!-- Dietary & Allergen Notice -->
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Dietary & Allergen Notice</label>
            <div class="flex flex-wrap gap-2">
              <span v-if="(!selectedItem.critical_allergens?.length && !selectedItem.allergens?.length) && (!selectedItem.digestive_sensitivities?.length && !selectedItem.sensitivities?.length)" class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold px-2.5 py-1 rounded-md flex items-center">
                Safe / No Common Allergens
              </span>
              <template v-else>
                <span v-for="a in (selectedItem.critical_allergens || selectedItem.allergens || [])" :key="'c-'+a" class="bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold px-2.5 py-1 rounded-md flex items-center">
                  <AlertTriangle :size="14" class="text-rose-600 inline mr-1" /> {{ a }}
                </span>
                <span v-for="s in (selectedItem.digestive_sensitivities || selectedItem.sensitivities || [])" :key="'s-'+s" class="bg-amber-50 text-amber-700 border border-amber-200 text-xs font-bold px-2.5 py-1 rounded-md">
                   {{ s }}
                </span>
              </template>
            </div>
          </div>
          
          <div class="pt-2">
            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Portion Preference</label>
            <div class="flex flex-wrap gap-2 mb-3">
              <label v-for="opt in ['no_preference', 'lean_meat', 'bone_fat', 'extra_sauce']" :key="opt" 
                     class="px-4 py-2 rounded-lg border text-sm transition-colors"
                     :class="{
                       'bg-emerald-600 text-white border-emerald-600 font-medium cursor-pointer shadow-sm': preference === opt && !isClearance(selectedItem),
                       'bg-slate-100 text-slate-700 border-transparent hover:bg-slate-200 font-medium cursor-pointer': preference !== opt && !isClearance(selectedItem),
                       'bg-slate-100 text-slate-400 border-slate-100 cursor-not-allowed': isClearance(selectedItem)
                     }">
                <input type="radio" :value="opt" v-model="preference" class="hidden" :disabled="isClearance(selectedItem)">
                {{ opt.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) }}
              </label>
            </div>
            
            <div v-if="isClearance(selectedItem)" class="text-sm text-amber-700 font-medium bg-amber-50 p-3 rounded-xl border border-amber-100 mb-3 flex items-start">
              <AlertTriangle :size="18" class="text-amber-600 mr-2 flex-shrink-0" />
              <span>Sold as-is: portion selection is unavailable for clearance surplus.</span>
            </div>
            <p class="text-xs text-slate-500 italic">* Best-Effort Only: Vendors will try to accommodate preferences but cannot guarantee exact portions during lunch rushes.</p>
          </div>
        </div>

        <!-- Zone 3: Pinned Footer -->
        <div class="flex-shrink-0 p-6 border-t border-slate-100 bg-white space-y-4">
          <!-- Quantity Selector & Anti-Hoarding -->
          <div class="flex flex-col items-center">
            <div v-if="isClearance(selectedItem)" class="text-[10px] font-bold text-amber-700 uppercase tracking-wide mb-2 bg-amber-50 px-3 py-1 rounded-full border border-amber-200 shadow-sm">
              Clearance Limit: Max 3 portions per person
            </div>
            
            <div v-if="limitWarning" class="flex items-center gap-2 p-2.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-medium transition-all mb-3 w-full max-w-[280px]">
              <AlertTriangle :size="14" class="text-amber-600 shrink-0"/>
              <span>{{ limitWarning }}</span>
            </div>

            <div class="flex items-center space-x-4 bg-slate-50 border border-slate-200 p-1.5 rounded-lg shadow-sm inline-flex" @click.capture="handleItemDrawerIncrementAttempt">
              <button @click="quantity > 1 ? quantity-- : null" class="w-10 h-10 rounded-md bg-white shadow-sm border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-semibold" :disabled="quantity <= 1 || selectedItem.stock <= 0"><Minus :size="16" /></button>
              <span class="w-8 text-center font-semibold tabular-nums text-xl text-slate-900">{{ quantity }}</span>
              <button @click="quantity < maxQuantity ? quantity++ : null" class="w-10 h-10 rounded-md bg-white shadow-sm border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-semibold" :disabled="quantity >= maxQuantity || selectedItem.stock <= 0"><Plus :size="16" /></button>
            </div>
          </div>

          <button @click="addToCart" 
                  class="w-full py-3.5 rounded-lg font-semibold tabular-nums shadow-md transition-all flex justify-center items-center text-lg"
                  :class="selectedItem.stock > 0 ? 'bg-emerald-600 text-white hover:bg-emerald-700 active:scale-95' : 'bg-slate-300 text-slate-500 cursor-not-allowed pointer-events-none'"
                  :disabled="selectedItem.stock <= 0">
            <template v-if="selectedItem.stock > 0">Add to Plate &bull; ₱{{ (selectedItem.current_price * quantity).toFixed(2) }}</template>
            <template v-else>Sold Out</template>
          </button>
        </div>

      </div>
    </transition>

    <!-- Mobile Sticky Floating Bottom Bar for Cart -->
    <div v-if="cart.length > 0 && !showPlateDrawer" class="fixed bottom-0 left-0 w-full p-4 z-30 md:hidden pointer-events-none">
      <button @click="showPlateDrawer = true" class="w-full bg-emerald-600 text-white font-bold py-4 rounded-2xl shadow-[0_10px_25px_-5px_rgba(5,150,105,0.4)] flex justify-between items-center px-6 pointer-events-auto active:scale-95 transition-transform">
        <div class="flex items-center space-x-2">
          <span class="bg-white/20 px-2 py-0.5 rounded-md text-sm">{{ totalCartItems }}</span>
          <span>₱{{ cartTotal.toFixed(2) }}</span>
        </div>
        <div class="flex items-center space-x-2">
          <span>View Plate</span>
          <Utensils :size="16" />
        </div>
      </button>
    </div>

    <!-- Plate Slide-Over Drawer -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showPlateDrawer" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] transition-opacity" @click="showPlateDrawer = false"></div>
    </transition>
    
    <transition enter-active-class="transition transform duration-300 ease-out" leave-active-class="transition transform duration-300 ease-in" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
      <div v-if="showPlateDrawer" class="fixed top-0 right-0 w-full md:max-w-md h-full bg-white shadow-2xl z-[70] flex flex-col overflow-hidden">
        
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 flex-shrink-0">
          <h3 class="text-xl font-bold text-slate-900 flex items-center">
            <Utensils :size="20" class="mr-2" /> Today's Plate
          </h3>
          <button @click="showPlateDrawer = false" class="p-2 rounded-full text-slate-600 hover:bg-slate-200 transition-colors">
            <X :size="20" />
          </button>
        </div>

        <div class="p-6 flex-1 overflow-y-auto">
          <!-- Eviction Notice Banner -->
          <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
          >
            <div 
              v-if="evictionNotice.show" 
              class="mb-3 bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-2.5 text-xs flex items-start gap-2 shadow-sm"
            >
              <span class="text-amber-600 font-bold">ⓘ</span>
              <div class="flex-1">
                <p class="font-semibold text-amber-800">Item no longer available</p>
                <p class="text-[11px] text-amber-700 leading-tight">
                  {{ evictionNotice.items.join(', ') }} was unlisted by the vendor and removed from your plate.
                </p>
              </div>
              <button 
                @click="evictionNotice.show = false" 
                class="text-amber-500 hover:text-amber-700 text-sm font-bold leading-none"
              >
                ×
              </button>
            </div>
          </transition>

          <!-- Same-Day Expiration Guard -->
          <div class="bg-amber-50 border border-amber-200 text-amber-800 text-[10px] font-bold p-2.5 rounded-lg mb-6 uppercase tracking-wide leading-tight flex items-start">
            <AlertTriangle :size="14" class="mr-1.5 flex-shrink-0" />
            <span>Same-Day Pickup: Orders expire today at closing. Unclaimed plates are non-refundable.</span>
          </div>

          <!-- Items -->
          <div v-if="cart.length === 0" class="text-center py-12 text-slate-500">
            <Utensils :size="48" class="mx-auto mb-4 opacity-20" />
            <p>Your plate is empty.</p>
          </div>
          
          <div v-for="(c, i) in cart" :key="i" class="flex justify-between items-start mb-6 border-b border-slate-100 pb-6 last:border-0 last:pb-0">
            <div class="flex-1 pr-4">
              <h4 class="font-bold text-slate-900 leading-tight">{{ c.item.name }}</h4>
              <div v-for="matchedFlag in getMatches(c.item)" :key="'cart-flag-'+matchedFlag" class="mt-1 flex items-center gap-1 text-xs font-semibold text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded w-fit">
                <AlertTriangle :size="12" /> 
                <template v-if="matchedFlag.startsWith('Watchlist:')">
                  Matches {{ matchedFlag }}
                </template>
                <template v-else>
                  Contains: {{ matchedFlag }}
                </template>
              </div>
              <p class="text-[10px] text-slate-500 uppercase mt-1 font-bold">{{ c.preference.replace('_', ' ') }}</p>
              <div v-if="isClearance(c.item) && c.quantity >= 3" class="text-[10px] text-amber-600 font-bold mt-1">Clearance cap: max 3 per plate</div>
              <div class="text-sm font-semibold tabular-nums text-emerald-700 mt-2">₱{{ c.item.current_price.toFixed(2) }}</div>
            </div>
            
            <div class="flex flex-col items-end">
              <div v-if="limitWarning && isClearance(c.item) && getCartItemTotalQty(c.item.id) >= 3" class="flex items-center gap-2 p-2.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs font-medium transition-all mb-2 w-full max-w-[200px]">
                <AlertTriangle :size="14" class="text-amber-600 shrink-0"/>
                <span>{{ limitWarning }}</span>
              </div>
              <div class="flex items-center space-x-3 bg-slate-50 border border-slate-200 p-1 rounded-lg mb-2" @click.capture="handleCartIncrementAttempt(c)">
                <button @click="c.quantity > 1 ? c.quantity-- : removeFromCart(i)" class="w-8 h-8 rounded bg-white shadow-sm border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100 transition-colors"><Minus :size="14" /></button>
                <span class="w-6 text-center font-semibold tabular-nums text-sm">{{ c.quantity }}</span>
                <button @click="getCartItemTotalQty(c.item.id) < 3 || !isClearance(c.item) ? c.quantity++ : null" class="w-8 h-8 rounded bg-white shadow-sm border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-100 transition-colors disabled:opacity-50" :disabled="isClearance(c.item) && getCartItemTotalQty(c.item.id) >= 3"><Plus :size="14" /></button>
              </div>
              <button @click="removeFromCart(i)" class="text-[10px] font-bold text-rose-600 hover:underline uppercase tracking-wider">Remove</button>
            </div>
          </div>
        </div>

        <div class="p-6 bg-slate-50 border-t border-slate-200 flex-shrink-0">
          <!-- Order Type -->
          <div class="flex bg-slate-200/60 p-1.5 rounded-xl mb-6">
            <button @click="orderType = 'dine_in'" class="flex-1 flex justify-center items-center py-2.5 text-sm font-bold rounded-lg transition-all" :class="orderType === 'dine_in' ? 'bg-white shadow-sm text-emerald-700' : 'text-slate-500 hover:text-slate-700'">
              <Utensils :size="16" class="mr-2" /> Dine-In (Tray)
            </button>
            <button @click="orderType = 'take_out'" class="flex-1 flex justify-center items-center py-2.5 text-sm font-bold rounded-lg transition-all" :class="orderType === 'take_out' ? 'bg-white shadow-sm text-emerald-700' : 'text-slate-500 hover:text-slate-700'">
              <Package :size="16" class="mr-2" /> Take-Out (Packed)
            </button>
          </div>
          <p class="text-[11px] text-slate-500 mb-6 italic text-center">*Reserves food portions; table seating is first-come, first-served.</p>

          <div class="flex justify-between items-center mb-2 text-sm font-medium text-slate-600">
            <span>Subtotal</span>
            <span class="tabular-nums">₱{{ cartSubtotal.toFixed(2) }}</span>
          </div>
          <div v-if="clearanceSavings > 0" class="flex justify-between items-center mb-4 text-sm font-bold text-amber-600">
            <span>Clearance Savings</span>
            <span class="tabular-nums">-₱{{ clearanceSavings.toFixed(2) }}</span>
          </div>
          <div class="flex justify-between items-center pt-3 border-t border-slate-200 font-black text-xl mb-6">
            <span class="text-slate-900">Total</span>
            <span class="text-emerald-600 tabular-nums">₱{{ cartTotal.toFixed(2) }}</span>
          </div>

          <div v-if="userDangerMatches.length > 0" class="bg-red-50 border border-red-300 text-red-900 p-3 mb-4 rounded-lg flex flex-col shadow-sm">
            <div class="flex items-start font-bold mb-2 text-sm leading-tight">
              <AlertTriangle :size="16" class="mr-2 text-red-600 flex-shrink-0" />
              <span>CRITICAL ALLERGEN ALERT: Plate contains severe allergens: {{ userDangerMatches.join(', ') }}</span>
            </div>
            <label class="flex items-center space-x-2 text-xs cursor-pointer mt-1 pl-1">
              <input type="checkbox" v-model="dangerAcknowledged" class="rounded text-red-600 focus:ring-red-500 w-4 h-4 cursor-pointer">
              <span class="font-semibold text-red-800">I understand this meal contains my designated medical allergen.</span>
            </label>
          </div>
          <div v-else-if="userCautionMatches.length > 0" class="bg-yellow-50 border border-yellow-300 text-yellow-800 p-3 mb-4 rounded-lg flex items-start shadow-sm">
            <Info :size="16" class="mr-2 text-yellow-600 flex-shrink-0" />
            <span class="text-xs font-bold leading-tight">Notice: Contains items known to trigger digestive sensitivities: {{ userCautionMatches.join(', ') }}</span>
          </div>

          <button @click="initiateCheckout" class="w-full font-bold py-3.5 rounded-xl shadow-md transition-transform flex justify-center items-center text-lg" 
                  :class="(cart.length === 0 || (userDangerMatches.length > 0 && !dangerAcknowledged)) ? 'bg-slate-300 text-slate-500 cursor-not-allowed' : 'bg-emerald-600 text-white hover:bg-emerald-700 active:scale-95'"
                  :disabled="cart.length === 0 || (userDangerMatches.length > 0 && !dangerAcknowledged)">
            Finalize Order
          </button>
        </div>
      </div>
    </transition>

    <!-- Checkout Options Modal -->
    <div v-if="showCheckoutOptions" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[80] flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <h3 class="text-lg font-bold text-slate-900">How would you like to claim?</h3>
          <button @click="showCheckoutOptions = false" class="text-slate-400 hover:text-slate-600 transition-colors">
            <X :size="20" />
          </button>
        </div>
        <div class="p-6 space-y-4">
          <button @click="processOrder('express')" class="w-full text-left p-4 border-2 border-emerald-500 bg-emerald-50 rounded-xl hover:bg-emerald-100 transition-colors group">
            <div class="flex items-center justify-between mb-1">
              <span class="font-bold text-emerald-900">Pre-Order & Pay</span>
              <span class="text-[10px] bg-emerald-600 text-white font-bold px-2 py-0.5 rounded-full uppercase tracking-wider">Express Pickup</span>
            </div>
            <p class="text-sm text-emerald-700">Pay now online. Skip the line and pick up your plate immediately.</p>
          </button>

          <button @click="processOrder('inline')" class="w-full text-left p-4 border border-slate-200 rounded-xl hover:bg-slate-50 hover:border-slate-300 transition-colors group">
            <div class="flex items-center justify-between mb-1">
              <span class="font-bold text-slate-900 group-hover:text-slate-700">Claim In-Line Pass</span>
              <span class="text-[10px] bg-slate-100 text-slate-600 font-bold px-2 py-0.5 rounded-full uppercase tracking-wider">Cash at Counter</span>
            </div>
            <p class="text-sm text-slate-500">Generate a temporary 4-digit code. Present and pay at the physical counter.</p>
          </button>
        </div>
      </div>
    </div>

    <!-- Confirmation Slide-Over Drawer -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="showConfirmationDrawer" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[90] transition-opacity" @click="showConfirmationDrawer = false"></div>
    </transition>
    
    <transition enter-active-class="transition transform duration-300 ease-out" leave-active-class="transition transform duration-300 ease-in" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
      <div v-if="showConfirmationDrawer" class="fixed top-0 right-0 w-full md:max-w-md h-full bg-emerald-600 shadow-2xl z-[100] flex flex-col overflow-hidden text-white">
        
        <div class="p-6 flex justify-end">
          <button @click="showConfirmationDrawer = false" class="p-2 rounded-full bg-white/20 hover:bg-white/30 transition-colors">
            <X :size="20" class="text-white" />
          </button>
        </div>

        <div class="p-6 flex-1 flex flex-col items-center justify-center text-center">
          <div class="w-20 h-20 bg-white text-emerald-600 rounded-full flex items-center justify-center mb-6 shadow-lg">
            <Utensils v-if="confirmationType === 'express'" :size="40" />
            <Clock v-else :size="40" />
          </div>
          <h2 class="text-3xl font-black mb-2">{{ confirmationType === 'express' ? 'Order Finalized!' : 'Pass Generated!' }}</h2>
          <p class="text-emerald-100 mb-8">{{ confirmationType === 'express' ? 'Please present this token at the counter.' : 'Valid for 5 minutes. Present at cash register.' }}</p>
          
          <div class="bg-white text-slate-900 w-full rounded-2xl p-8 shadow-xl relative overflow-hidden">
            <div class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2">{{ confirmationType === 'express' ? 'Your Claim Token' : 'Your In-Line Code' }}</div>
            <div class="text-6xl font-black tabular-nums tracking-widest text-emerald-600">#{{ confirmedToken }}</div>
          </div>
          
          <div class="mt-8 text-emerald-100 font-medium text-lg">
            Total to Pay: <span class="text-white font-bold text-2xl tabular-nums ml-2">₱{{ confirmedTotal.toFixed(2) }}</span>
          </div>

          <div v-if="confirmedFulfillmentType === 'dine_in'" class="mt-6 w-full">
            <button @click="convertToTakeOut" class="text-sm bg-white/20 hover:bg-white/30 text-white py-2 px-4 rounded-full transition-colors w-full font-semibold border border-white/30 shadow-sm">
              No seats available? Pack as Take-Out instead
            </button>
          </div>
          <div v-else class="mt-6 w-full text-center text-sm font-semibold text-emerald-100 bg-white/10 py-2 px-4 rounded-full border border-white/20">
            Fulfillment: Take-Out (Packed)
          </div>
        </div>

        <div class="p-6">
          <button @click="showConfirmationDrawer = false" class="w-full bg-white text-emerald-700 font-bold py-3.5 rounded-xl hover:bg-emerald-50 shadow-md transition-transform active:scale-95 text-lg">
            Done
          </button>
        </div>
      </div>
    </transition>

    <!-- Conflict Roadblock Modal -->
    <transition enter-active-class="transition-opacity duration-300" leave-active-class="transition-opacity duration-300" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="conflictModalOpen" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[80] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full overflow-hidden" @click.stop>
          <div class="p-6 bg-rose-50 border-b border-rose-100 flex flex-col items-center text-center">
            <div class="w-12 h-12 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mb-3 shadow-sm">
              <AlertTriangle :size="24" />
            </div>
            <h3 class="text-xl font-black text-rose-900">Dietary Conflict Warning</h3>
          </div>
          <div class="p-6">
            <p class="text-sm text-slate-600 mb-4 text-center font-medium">This item matches triggers in your saved health profile / watchlist:</p>
            <div class="flex flex-wrap gap-2 justify-center mb-6">
              <span v-for="tag in activeDishConflicts" :key="tag" class="bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold px-3 py-1.5 rounded-full">
                {{ tag }}
              </span>
            </div>
            <div class="space-y-3">
              <button @click="proceedWithAddition" class="w-full font-bold py-3 rounded-lg bg-rose-600 text-white hover:bg-rose-700 shadow-md transition-all active:scale-95">
                Add Anyway
              </button>
              <button @click="conflictModalOpen = false" class="w-full font-bold py-3 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition-all active:scale-95">
                Cancel Order
              </button>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Utensils, ShoppingBag, Clock, SlidersHorizontal, AlertTriangle, X, Plus, Minus, Info, Package } from 'lucide-vue-next'
import { useSandbox } from '../../stores/sandbox'

const router = useRouter()
const { activePreviewMode, sandboxMenu: menuItems, simulatedDietaryFlags, sandboxOrders } = useSandbox()

const isAdmin = computed(() => {
  try {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}')
    return user.role === 'admin'
  } catch (e) {
    return false
  }
})
const getInitialPlate = () => {
  const today = new Date().toISOString().split('T')[0];
  const saved = JSON.parse(localStorage.getItem('optimeal_customer_plate') || 'null');
  
  // Hard reset if saved from a previous day or wrong format
  if (!saved || saved.date !== today) {
    localStorage.removeItem('optimeal_customer_plate');
    return [];
  }

  // Check if past canteen closing hour (e.g., 20:00 / 8:00 PM)
  const now = new Date();
  const closingHour = 20; // 8 PM
  if (now.getHours() >= closingHour) {
    localStorage.removeItem('optimeal_customer_plate');
    return [];
  }

  return saved.items || [];
};

const cart = ref(getInitialPlate())
watch(cart, (newItems) => {
  const today = new Date().toISOString().split('T')[0];
  localStorage.setItem('optimeal_customer_plate', JSON.stringify({
    date: today,
    items: newItems
  }))
}, { deep: true })

const dangerAcknowledged = ref(false)
const categories = ['All', 'Sabaw / Soup', 'Ulam / Stews', 'Prito / Fried', 'Gulay & Veg', 'Dessert / Snacks']
const selectedCategory = ref('All')
const maxCalories = ref(null)
const selectedItem = ref(null)
const preference = ref('no_preference')
const quantity = ref(1)
const ttlSeconds = ref(1800) 
const dismissedSoftWarning = ref(false)
const showPlateDrawer = ref(false)
const showCheckoutOptions = ref(false)
const showConfirmationDrawer = ref(false)
const confirmationType = ref('express')
const confirmedToken = ref('')
const confirmedTotal = ref(0)
const confirmedFulfillmentType = ref('dine_in')
const orderType = ref('dine_in')
const limitWarning = ref('')
let limitWarningTimeout = null

const conflictModalOpen = ref(false)
const activeDishConflicts = ref([])

const evictionNotice = ref({ show: false, items: [] })
let evictionTimer = null

const triggerEvictionNotice = (itemNames) => {
  if (evictionTimer) clearTimeout(evictionTimer)
  evictionNotice.value = { show: true, items: itemNames }
  evictionTimer = setTimeout(() => {
    evictionNotice.value.show = false
  }, 8000)
}


const showWarning = () => {
  limitWarning.value = 'Clearance limit reached: Maximum 3 portions per plate.'
  if (limitWarningTimeout) clearTimeout(limitWarningTimeout)
  limitWarningTimeout = setTimeout(() => {
    limitWarning.value = ''
  }, 3500)
}

watch(showPlateDrawer, () => {
  limitWarning.value = ''
})
watch(selectedItem, () => {
  limitWarning.value = ''
})

const handleItemDrawerIncrementAttempt = (e) => {
  if (e.target.closest('button') && e.target.closest('button').disabled) {
    if (quantity.value >= maxQuantity.value && isClearance(selectedItem.value) && maxQuantity.value === 3) {
      showWarning()
    }
  }
}

const handleCartIncrementAttempt = (c) => {
  // Check if click was on the [+] button (we can infer it if they are already at the max cap for clearance)
  if (isClearance(c.item) && getCartItemTotalQty(c.item.id) >= 3) {
    // If they click anywhere in the control while maxed out, just show the warning
    showWarning()
  }
}

const reconcileCart = () => {
  if (!cart.value.length) return
  const activeDishIds = new Set(menuItems.value.map(d => d.id))
  const evictedItems = new Set()
  const newCart = []
  
  cart.value.forEach(c => {
    if (activeDishIds.has(c.item.id)) {
      newCart.push(c)
    } else {
      evictedItems.add(c.item.name)
    }
  })
  
  if (newCart.length !== cart.value.length) {
    cart.value = newCart
    if (evictedItems.size > 0) {
      triggerEvictionNotice(Array.from(evictedItems))
      showPlateDrawer.value = true
    }
  }
}

watch(menuItems, () => {
  reconcileCart()
}, { deep: true })

onMounted(() => {
  reconcileCart()
  
  window.addEventListener('optimeal_menu_updated', () => {
    reconcileCart()
  })

  // Real-time cart TTL countdown
  setInterval(() => {
    if (cart.value.length > 0 && ttlSeconds.value > 0) ttlSeconds.value--
  }, 1000)
})

const displayDishes = computed(() => {
  let list = menuItems.value;
  if (selectedCategory.value && selectedCategory.value !== 'All') {
    list = list.filter(item => item.category === selectedCategory.value);
  }
  if (maxCalories.value && maxCalories.value > 0) {
    list = list.filter(item => {
      if (!item.macros || item.macros.calories === null || item.macros.calories === undefined) {
        return false;
      }
      return item.macros.calories <= maxCalories.value;
    });
  }
  return [...list].sort((a, b) => {
    const aConflicts = getMatches(a).length > 0;
    const bConflicts = getMatches(b).length > 0;
    if (aConflicts && !bConflicts) return 1;
    if (!aConflicts && bConflicts) return -1;
    return 0;
  });
})

const cartSubtotal = computed(() => {
  return cart.value.reduce((acc, c) => acc + (c.item.base_price * c.quantity), 0)
})

const cartTotal = computed(() => {
  return cart.value.reduce((acc, c) => acc + (c.item.current_price * c.quantity), 0)
})

const totalCartItems = computed(() => {
  return cart.value.reduce((sum, c) => sum + c.quantity, 0)
})

const hasCartDietaryConflicts = computed(() => {
  return cart.value.some(c => getMatches(c.item).length > 0)
})

const getCartItemTotalQty = (itemId) => {
  return cart.value.filter(c => c.item.id === itemId).reduce((sum, c) => sum + c.quantity, 0)
}

const clearanceSavings = computed(() => {
  return cartSubtotal.value - cartTotal.value
})

const formattedTtl = computed(() => {
  const m = Math.floor(ttlSeconds.value / 60)
  const s = ttlSeconds.value % 60
  return `${m}:${s.toString().padStart(2, '0')}`
})

const isClearance = (item) => {
  return item.current_price < item.base_price
}

const maxQuantity = computed(() => {
  if (!selectedItem.value) return 1
  const stock = selectedItem.value.online_pool_qty
  if (isClearance(selectedItem.value)) {
    return Math.min(stock, 3)
  }
  return stock
})

const calculateDiscountPercent = (item) => {
  return Math.round(((item.base_price - item.current_price) / item.base_price) * 100)
}

function getMatches(item) {
  let userFlags = [];
  let watchlist = [];
  
  if (isAdmin.value && activePreviewMode.value === 'customer') {
    const rawFlags = simulatedDietaryFlags.value;
    userFlags = [...(rawFlags.allergens || []), ...(rawFlags.sensitivities || []), ...(rawFlags.dietary || [])];
    watchlist = rawFlags.custom_watchlist || [];
  } else {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}');
    const rawFlags = user.dietary_flags || {};
    userFlags = Array.isArray(rawFlags) 
      ? rawFlags 
      : [...(rawFlags.allergens || []), ...(rawFlags.sensitivities || []), ...(rawFlags.dietary || [])];
    watchlist = Array.isArray(rawFlags) ? [] : (rawFlags.custom_watchlist || []);
  }

  const matched = [];

  // 1. Structured tag matching
  const itemAllergens = item.critical_allergens || item.allergens || [];
  itemAllergens.forEach(a => { if (userFlags.includes(a)) matched.push(a); });
  
  const itemSensitivities = item.digestive_sensitivities || item.sensitivities || [];
  itemSensitivities.forEach(s => { if (userFlags.includes(s)) matched.push(s); });

  // Handle dietary boundary exclusions
  if (userFlags.includes('No Pork') && (item.dietary || []).includes('Pork')) matched.push('Pork');
  if (userFlags.includes('No Beef') && (item.dietary || []).includes('Beef')) matched.push('Beef');
  if (userFlags.includes('Vegetarian / Plant-Based') && !(item.dietary || []).includes('Vegetarian / Plant-Based')) matched.push('Non-Vegetarian');

  // 2. Custom Watchlist / Ingredients text scan
  watchlist.forEach(term => {
    const lowerTerm = term.toLowerCase().trim();
    if (!lowerTerm) return;
    const inIngredients = (item.ingredients || []).some(i => i.toLowerCase().includes(lowerTerm));
    const inName = item.name.toLowerCase().includes(lowerTerm);
    if (inIngredients || inName) {
      matched.push(`Watchlist: [${term}]`);
    }
  });

  return matched;
}

const openItemModal = (item) => {
  selectedItem.value = item
  preference.value = 'no_preference'
  quantity.value = 1
}

const addToCart = () => {
  if (!selectedItem.value || selectedItem.value.stock <= 0) return

  const conflicts = getMatches(selectedItem.value)
  if (conflicts.length > 0) {
    activeDishConflicts.value = conflicts
    conflictModalOpen.value = true
    return
  }
  
  confirmAddToPlate()
}

const proceedWithAddition = () => {
  confirmAddToPlate()
  conflictModalOpen.value = false
}

const confirmAddToPlate = () => {
  if (!selectedItem.value || selectedItem.value.stock <= 0) return

  let qtyToAdd = quantity.value
  let wasClamped = false
  
  if (isClearance(selectedItem.value)) {
    const existingQty = getCartItemTotalQty(selectedItem.value.id)
      
    if (existingQty + qtyToAdd > 3) {
      qtyToAdd = Math.max(0, 3 - existingQty)
      showWarning()
      if (qtyToAdd === 0) {
        return
      }
      wasClamped = true
    }
  }

  const existingIndex = cart.value.findIndex(
    c => c.item.id === selectedItem.value.id && c.preference === preference.value
  )
  
  if (existingIndex > -1) {
    cart.value[existingIndex].quantity += qtyToAdd
  } else {
    cart.value.push({
      item: selectedItem.value,
      quantity: qtyToAdd,
      preference: preference.value
    })
  }
  
  if (!wasClamped) {
    selectedItem.value = null
  }
  ttlSeconds.value = 1800 // extend TTL
}

const removeFromCart = (index) => {
  cart.value.splice(index, 1)
  if (cart.value.length === 0) {
    showPlateDrawer.value = false
  }
}

const userDangerMatches = computed(() => {
  let userFlags = [];
  if (isAdmin.value && activePreviewMode.value === 'customer') {
    const rawFlags = simulatedDietaryFlags.value;
    userFlags = [...(rawFlags.allergens || []), ...(rawFlags.dietary || [])];
  } else {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}');
    const rawFlags = user.dietary_flags || [];
    userFlags = Array.isArray(rawFlags) ? rawFlags : [...(rawFlags.allergens || []), ...(rawFlags.dietary || [])];
  }
  const set = new Set();
  cart.value.forEach(c => {
    (c.item.allergens || []).forEach(a => { if (userFlags.includes(a)) set.add(a); });
    if (userFlags.includes('No Pork') && (c.item.dietary || []).includes('Pork')) set.add('Pork');
    if (userFlags.includes('No Beef') && (c.item.dietary || []).includes('Beef')) set.add('Beef');
    if (userFlags.includes('Vegetarian / Plant-Based') && !(c.item.dietary || []).includes('Vegetarian / Plant-Based')) set.add('Non-Vegetarian');
  });
  return Array.from(set);
})

const userCautionMatches = computed(() => {
  let userFlags = [];
  if (isAdmin.value && activePreviewMode.value === 'customer') {
    const rawFlags = simulatedDietaryFlags.value;
    userFlags = [...(rawFlags.sensitivities || [])];
  } else {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}');
    const rawFlags = user.dietary_flags || [];
    userFlags = Array.isArray(rawFlags) ? rawFlags : [...(rawFlags.sensitivities || [])];
  }
  const set = new Set();
  cart.value.forEach(c => {
    (c.item.sensitivities || []).forEach(s => { if (userFlags.includes(s)) set.add(s); });
  });
  return Array.from(set);
})

const initiateCheckout = () => {
  if (!localStorage.getItem('optimeal_user')) {
    router.push('/customer/login')
    return
  }
  
  if (userDangerMatches.value.length > 0 && !dangerAcknowledged.value) {
    return // Block if not checked, although the button is already disabled
  } else {
    proceedToCheckout()
  }
}

const proceedToCheckout = () => {
  showCheckoutOptions.value = true
}

const processOrder = async (type) => {
  const currentTotal = cartTotal.value
  showCheckoutOptions.value = false
  showPlateDrawer.value = false
  confirmationType.value = type
  
  try {
    if (isAdmin.value && activePreviewMode.value === 'customer') {
      const token = Math.floor(1000 + Math.random() * 9000).toString()
      sandboxOrders.value.push({
        id: Date.now(),
        token,
        type,
        fulfillment_type: orderType.value,
        total: currentTotal,
        is_sandbox: true,
        items: cart.value.map(c => ({...c}))
      })
      confirmedToken.value = token
    } else {
      const payload = {
        store_id: 1,
        type: type,
        fulfillment_type: orderType.value,
        items: cart.value.map(c => ({
          menu_item_id: c.item.id,
          quantity: c.quantity,
          portion_preference: c.preference,
          ack: true
        }))
      }
      
      const res = await fetch('/api/reservations', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      
      if (!res.ok) throw new Error('API Error')
      
      const data = await res.json()
      confirmedToken.value = data.claim_token
    }
  } catch (e) {
    console.warn('API error or offline mode, falling back to mock token', e)
    confirmedToken.value = Math.floor(1000 + Math.random() * 9000).toString()
  }
  
  confirmedTotal.value = currentTotal
  confirmedFulfillmentType.value = orderType.value
  showConfirmationDrawer.value = true
  cart.value = []
}

const convertToTakeOut = () => {
  confirmedFulfillmentType.value = 'take_out';
  if (isAdmin.value && activePreviewMode.value === 'customer') {
    const order = sandboxOrders.value.find(o => o.token === confirmedToken.value);
    if (order) {
      order.fulfillment_type = 'take_out';
    }
  } else {
    fetch('/api/reservations/update-fulfillment', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: confirmedToken.value, fulfillment_type: 'take_out' })
    }).catch(e => console.warn('Mock fallback for updating fulfillment', e))
  }
}
</script>
