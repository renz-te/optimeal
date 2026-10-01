<template>
  <div class="p-8 max-w-4xl mx-auto">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Today's Live Stock</h1>
        <p class="text-slate-500 mt-1 font-medium">Manage real-time inventory for your published lineup.</p>
      </div>
    </div>
      
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
      <div v-if="inventory.length === 0" class="text-slate-500 text-sm text-center py-12 bg-slate-50 rounded-lg border border-dashed border-slate-200 font-medium">
        No active dishes published today. Head to Master Catalog to launch your menu.
      </div>

      <div v-for="item in inventory" :key="item.id" class="mb-5 bg-slate-50 p-5 rounded-xl border border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex-1">
          <h3 class="font-bold text-slate-800 text-lg">{{ item.item_name }}</h3>
          <div class="mt-2 flex items-center space-x-2">
             <label class="flex items-center cursor-pointer">
               <input type="checkbox" v-model="item.available_online" class="w-4 h-4 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 mr-2">
               <span class="text-xs font-bold" :class="item.available_online ? 'text-emerald-700' : 'text-slate-500'">{{ item.available_online ? 'Available Online' : 'Sold Out Online' }}</span>
             </label>
          </div>
        </div>
        <div class="flex items-end space-x-4">
          <label class="block">
            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Walk-in Qty</span>
            <input type="number" v-model.number="item.walkin_pool_qty" class="block w-20 border-slate-300 shadow-sm rounded-lg focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm px-3 py-2 border outline-none font-bold" />
          </label>
          <label class="block">
            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">App Qty</span>
            <input type="number" v-model.number="item.online_pool_qty" class="block w-20 border-slate-300 shadow-sm rounded-lg focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm px-3 py-2 border outline-none font-bold" />
          </label>
          <button @click="saveInventory(item)" class="bg-emerald-600 text-white font-bold px-5 py-2.5 rounded-lg shadow-sm hover:bg-emerald-700 active:scale-95 transition-all h-[42px] flex items-center justify-center">
            Update
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useSandbox } from '../../stores/sandbox'

const { sandboxMenu } = useSandbox()
const inventory = ref([])

onMounted(() => {
  syncInventory()
})

watch(sandboxMenu, () => {
  syncInventory()
}, { deep: true })

const syncInventory = () => {
  inventory.value = sandboxMenu.value.map(item => ({
    id: item.id,
    menu_item_id: item.id,
    item_name: item.name,
    walkin_pool_qty: item.stock,
    online_pool_qty: item.stock,
    available_online: item.stock > 0
  }))
}

const saveInventory = (item) => {
  const dish = sandboxMenu.value.find(d => d.id === item.menu_item_id)
  if (dish) {
    dish.stock = item.available_online ? item.online_pool_qty : 0
  }
}
</script>
