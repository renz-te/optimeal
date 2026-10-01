import { ref, watch } from 'vue'
import router from '../router' // We need router to push from store

const initialMenu = [
  { id: 1, name: 'Bicol Express', description: 'Spicy pork belly cooked in coconut milk.', category: 'Ulam / Stews', current_price: 55, base_price: 80, stock: 4, decay: 31, ingredients: ['Pork Belly', 'Coconut Milk', 'Shrimp Paste', 'Chili Peppers'], allergens: ['Shrimp Paste (Bagoong)'], sensitivities: ['Spicy / Capsaicin', 'Coconut Milk (Gata)'], dietary: ['Pork'] },
  { id: 2, name: 'Chicken Adobo', description: 'Classic Filipino chicken adobo.', category: 'Ulam / Stews', current_price: 40, base_price: 60, stock: 8, decay: 33, ingredients: ['Chicken Thigh', 'Soy Sauce', 'Cane Vinegar', 'Garlic'], allergens: ['Soy', 'Wheat/Gluten'], sensitivities: ['High Acid / Vinegar', 'High Sodium'], dietary: [] },
  { id: 3, name: 'Pork Sinigang', description: 'Sour tamarind soup with pork.', category: 'Sabaw / Soup', current_price: 50, base_price: 75, stock: 5, decay: 33, ingredients: ['Pork Ribs', 'Tamarind Broth', 'Radish', 'Kangkong'], allergens: [], sensitivities: ['High Acid / Vinegar'], dietary: ['Pork'] },
  { id: 4, name: 'Peanut Kare-Kare', description: 'Beef stew in rich peanut sauce.', category: 'Ulam / Stews', current_price: 65, base_price: 90, stock: 3, decay: 28, ingredients: ['Beef Shank', 'Peanut Paste', 'Eggplant', 'String Beans'], allergens: ['Peanuts', 'Shrimp Paste (Bagoong)'], sensitivities: ['High Purine (Beans/Organ Meats)'], dietary: ['Beef'] },
  { id: 5, name: 'Sinigang na Hipon', description: 'Tamarind soup with fresh local shrimp.', category: 'Sabaw / Soup', current_price: 70, base_price: 95, stock: 4, decay: 26, ingredients: ['Fresh Shrimp', 'Tamarind Broth', 'Radish', 'Mustard Greens'], allergens: ['Shellfish / Crustaceans'], sensitivities: ['High Acid / Vinegar'], dietary: [] },
  { id: 6, name: 'Tortang Talong', description: 'Pan-fried roasted eggplant omelette.', category: 'Gulay & Veg', current_price: 30, base_price: 45, stock: 6, decay: 33, ingredients: ['Eggplant', 'Eggs', 'Garlic', 'Salt'], allergens: ['Eggs'], sensitivities: [], dietary: ['Vegetarian / Plant-Based'] },
  { id: 7, name: 'Crispy Pork Kawali', description: 'Golden deep-fried pork belly slices.', category: 'Prito / Fried', current_price: 60, base_price: 85, stock: 5, decay: 29, ingredients: ['Pork Belly', 'Oil', 'Garlic', 'Pepper'], allergens: [], sensitivities: ['High Sodium'], dietary: ['Pork'] },
  { id: 8, name: 'Pinakbet Tagalog', description: 'Native vegetables sautéed in shrimp paste.', category: 'Gulay & Veg', current_price: 35, base_price: 50, stock: 7, decay: 30, ingredients: ['Squash', 'Okra', 'Eggplant', 'Shrimp Paste', 'Pork Chunks'], allergens: ['Shrimp Paste (Bagoong)'], sensitivities: ['High Sodium'], dietary: ['Pork'] },
  { id: 9, name: 'Ginataang Bilo-Bilo', description: 'Sweet coconut soup with sticky rice balls.', category: 'Dessert / Snacks', current_price: 30, base_price: 40, stock: 10, decay: 25, ingredients: ['Glutinous Rice', 'Coconut Milk', 'Sweet Potato', 'Tapioca'], allergens: [], sensitivities: ['Coconut Milk (Gata)'], dietary: ['Vegetarian / Plant-Based'] },
  { id: 10, name: 'Leche Flan', description: 'Steamed creamy custard dessert.', category: 'Dessert / Snacks', current_price: 35, base_price: 50, stock: 6, decay: 30, ingredients: ['Egg Yolks', 'Condensed Milk', 'Caramelized Sugar'], allergens: ['Eggs', 'Dairy'], sensitivities: [], dietary: ['Vegetarian / Plant-Based'] },
  { id: 11, name: 'Chicken Pancit Canton', description: 'Stir-fried flour noodles with chicken breast, shredded cabbage, carrots, and celery stalks.', category: 'Ulam / Stews', current_price: 45, base_price: 65, stock: 5, decay: 30, ingredients: ['Egg Noodles', 'Chicken Breast', 'Cabbage', 'Carrots', 'Celery', 'Soy Sauce'], allergens: ['Wheat/Gluten', 'Eggs', 'Soy'], sensitivities: ['High Sodium'], dietary: [] }
]

const getStoredMenu = () => {
  const m = localStorage.getItem('optimeal_published_menu')
  return m ? JSON.parse(m) : [...initialMenu]
}

const getStoredOrders = () => {
  const o = localStorage.getItem('sandbox_orders')
  return o ? JSON.parse(o) : []
}

const getStoredMode = () => {
  return sessionStorage.getItem('sandbox_preview_mode') || 'admin'
}

const getStoredFlags = () => {
  const f = sessionStorage.getItem('sandbox_simulated_flags')
  return f ? JSON.parse(f) : { allergens: [], sensitivities: [], dietary: [] }
}

export const activePreviewMode = ref(getStoredMode())
export const simulatedDietaryFlags = ref(getStoredFlags())
export const sandboxMenu = ref(getStoredMenu())
export const sandboxOrders = ref(getStoredOrders())

export const resetSandboxState = () => {
  sandboxMenu.value = [...initialMenu]
  sandboxOrders.value = []
  simulatedDietaryFlags.value = { allergens: [], sensitivities: [], dietary: [] }
}

export const detectPreset = (flags) => {
  const f = flags || { allergens: [], sensitivities: [], dietary: [] }
  const match = (arr1, arr2) => {
    if (!arr1) arr1 = []
    if (!arr2) arr2 = []
    if (arr1.length !== arr2.length) return false
    const sorted1 = [...arr1].sort()
    const sorted2 = [...arr2].sort()
    return sorted1.every((val, index) => val === sorted2[index])
  }

  if (match(f.allergens, []) && match(f.sensitivities, []) && match(f.dietary, [])) {
    return 'clean'
  }
  if (match(f.allergens, ['Peanuts']) && match(f.sensitivities, ['Spicy / Capsaicin']) && match(f.dietary, ['No Pork'])) {
    return 'peanuts_pork_spicy'
  }
  if (match(f.allergens, ['Shellfish / Crustaceans', 'Shrimp Paste (Bagoong)']) && match(f.sensitivities, []) && match(f.dietary, [])) {
    return 'shellfish_bagoong'
  }
  if (match(f.allergens, []) && match(f.sensitivities, []) && match(f.dietary, ['Vegetarian / Plant-Based'])) {
    return 'vegetarian'
  }

  return 'custom'
}

export const enterCustomerMode = (preset = 'clean') => {
  activePreviewMode.value = 'customer'
  let flags = { allergens: [], sensitivities: [], dietary: [] }
  if (preset === 'peanuts_pork_spicy') {
    flags = { allergens: ['Peanuts'], dietary: ['No Pork'], sensitivities: ['Spicy / Capsaicin'] }
  } else if (preset === 'shellfish_bagoong') {
    flags = { allergens: ['Shellfish / Crustaceans', 'Shrimp Paste (Bagoong)'], dietary: [], sensitivities: [] }
  } else if (preset === 'vegetarian') {
    flags = { allergens: [], dietary: ['Vegetarian / Plant-Based'], sensitivities: [] }
  }
  simulatedDietaryFlags.value = flags
  router.push('/customer/menu')
}

export const enterVendorMode = () => {
  activePreviewMode.value = 'vendor'
  router.push('/vendor/dashboard')
}

export const clearSandbox = () => {
  activePreviewMode.value = 'admin'
  simulatedDietaryFlags.value = { allergens: [], sensitivities: [], dietary: [] }
  sessionStorage.removeItem('sandbox_preview_mode')
  sessionStorage.removeItem('sandbox_simulated_flags')
  localStorage.removeItem('optimeal_sandbox_mode')
  localStorage.removeItem('optimeal_simulated_role')
}

export const exitSandbox = () => {
  clearSandbox()
  router.push('/admin/dashboard')
}

watch(activePreviewMode, (val) => {
  sessionStorage.setItem('sandbox_preview_mode', val)
})

watch(simulatedDietaryFlags, (val) => {
  sessionStorage.setItem('sandbox_simulated_flags', JSON.stringify(val))
}, { deep: true })

watch(sandboxMenu, (val) => {
  localStorage.setItem('optimeal_published_menu', JSON.stringify(val))
  window.dispatchEvent(new Event('optimeal_menu_updated'))
}, { deep: true })

watch(sandboxOrders, (val) => {
  localStorage.setItem('sandbox_orders', JSON.stringify(val))
}, { deep: true })

export const useSandbox = () => {
  return {
    activePreviewMode,
    simulatedDietaryFlags,
    sandboxMenu,
    sandboxOrders,
    resetSandboxState,
    enterCustomerMode,
    enterVendorMode,
    exitSandbox,
    clearSandbox,
    detectPreset
  }
}
