import { ref, watch } from 'vue'

const presetCatalog = [
  { id: 1, name: 'Bicol Express', description: 'Spicy pork belly cooked in coconut milk.', category: 'Ulam / Stews', base_price: 80, ingredients: ['Pork Belly', 'Coconut Milk', 'Shrimp Paste', 'Chili Peppers'], allergens: ['Shrimp Paste (Bagoong)'], sensitivities: ['Spicy / Capsaicin', 'Coconut Milk (Gata)'], dietary: ['Pork'] },
  { id: 2, name: 'Chicken Adobo', description: 'Classic Filipino chicken adobo.', category: 'Ulam / Stews', base_price: 60, ingredients: ['Chicken Thigh', 'Soy Sauce', 'Cane Vinegar', 'Garlic'], allergens: ['Soy', 'Wheat/Gluten'], sensitivities: ['High Acid / Vinegar', 'High Sodium'], dietary: [] },
  { id: 3, name: 'Pork Sinigang', description: 'Sour tamarind soup with pork.', category: 'Sabaw / Soup', base_price: 75, ingredients: ['Pork Ribs', 'Tamarind Broth', 'Radish', 'Kangkong'], allergens: [], sensitivities: ['High Acid / Vinegar'], dietary: ['Pork'] },
  { id: 4, name: 'Peanut Kare-Kare', description: 'Beef stew in rich peanut sauce.', category: 'Ulam / Stews', base_price: 90, ingredients: ['Beef Shank', 'Peanut Paste', 'Eggplant', 'String Beans'], allergens: ['Peanuts', 'Shrimp Paste (Bagoong)'], sensitivities: ['High Purine (Beans/Organ Meats)'], dietary: ['Beef'] },
  { id: 5, name: 'Sinigang na Hipon', description: 'Tamarind soup with fresh local shrimp.', category: 'Sabaw / Soup', base_price: 95, ingredients: ['Fresh Shrimp', 'Tamarind Broth', 'Radish', 'Mustard Greens'], allergens: ['Shellfish / Crustaceans'], sensitivities: ['High Acid / Vinegar'], dietary: [] },
  { id: 6, name: 'Tortang Talong', description: 'Pan-fried roasted eggplant omelette.', category: 'Gulay & Veg', base_price: 45, ingredients: ['Eggplant', 'Eggs', 'Garlic', 'Salt'], allergens: ['Eggs'], sensitivities: [], dietary: ['Vegetarian / Plant-Based'] },
  { id: 7, name: 'Crispy Pork Kawali', description: 'Golden deep-fried pork belly slices.', category: 'Prito / Fried', base_price: 85, ingredients: ['Pork Belly', 'Oil', 'Garlic', 'Pepper'], allergens: [], sensitivities: ['High Sodium'], dietary: ['Pork'] },
  { id: 8, name: 'Pinakbet Tagalog', description: 'Native vegetables sautéed in shrimp paste.', category: 'Gulay & Veg', base_price: 50, ingredients: ['Squash', 'Okra', 'Eggplant', 'Shrimp Paste', 'Pork Chunks'], allergens: ['Shrimp Paste (Bagoong)'], sensitivities: ['High Sodium'], dietary: ['Pork'] },
  { id: 9, name: 'Ginataang Bilo-Bilo', description: 'Sweet coconut soup with sticky rice balls.', category: 'Dessert / Snacks', base_price: 40, ingredients: ['Glutinous Rice', 'Coconut Milk', 'Sweet Potato', 'Tapioca'], allergens: [], sensitivities: ['Coconut Milk (Gata)'], dietary: ['Vegetarian / Plant-Based'] },
  { id: 10, name: 'Leche Flan', description: 'Steamed creamy custard dessert.', category: 'Dessert / Snacks', base_price: 50, ingredients: ['Egg Yolks', 'Condensed Milk', 'Caramelized Sugar'], allergens: ['Eggs', 'Dairy'], sensitivities: [], dietary: ['Vegetarian / Plant-Based'] },
  { id: 11, name: 'Chicken Pancit Canton', description: 'Stir-fried flour noodles with chicken breast, shredded cabbage, carrots, and celery stalks.', category: 'Ulam / Stews', base_price: 65, ingredients: ['Egg Noodles', 'Chicken Breast', 'Cabbage', 'Carrots', 'Celery', 'Soy Sauce'], allergens: ['Wheat/Gluten', 'Eggs', 'Soy'], sensitivities: ['High Sodium'], dietary: [] }
]

const getStoredCatalog = () => {
  const m = localStorage.getItem('optimeal_vendor_catalog')
  return m ? JSON.parse(m) : [...presetCatalog]
}

export const masterCatalog = ref(getStoredCatalog())

watch(masterCatalog, (val) => {
  localStorage.setItem('optimeal_vendor_catalog', JSON.stringify(val))
}, { deep: true })

export const useCatalog = () => {
  const addDish = (dish) => {
    const id = Date.now()
    masterCatalog.value.push({ ...dish, id })
  }
  
  const updateDish = (id, updates) => {
    const idx = masterCatalog.value.findIndex(d => d.id === id)
    if (idx !== -1) {
      masterCatalog.value[idx] = { ...masterCatalog.value[idx], ...updates }
    }
  }

  return {
    masterCatalog,
    addDish,
    updateDish
  }
}
