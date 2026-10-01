import { ref, watch } from 'vue'

const initialTaxonomy = {
  allergens: ['Peanuts', 'Shellfish / Crustaceans', 'Shrimp Paste (Bagoong)', 'Soy', 'Dairy', 'Eggs', 'Fish', 'Wheat/Gluten', 'Sesame'],
  dietary: ['Pork', 'Beef', 'Vegetarian / Plant-Based'],
  sensitivities: ['Spicy / Capsaicin', 'Coconut Milk (Gata)', 'High Acid / Vinegar', 'High Purine (Monggo / Dried Beans / Organ Meats)', 'High Sodium']
}

const getStoredTaxonomy = () => {
  const m = localStorage.getItem('optimeal_taxonomy')
  return m ? JSON.parse(m) : JSON.parse(JSON.stringify(initialTaxonomy))
}

export const masterTaxonomy = ref(getStoredTaxonomy())

watch(masterTaxonomy, (val) => {
  localStorage.setItem('optimeal_taxonomy', JSON.stringify(val))
}, { deep: true })

export const useTaxonomy = () => {
  const addTag = (group, tag) => {
    if (!masterTaxonomy.value[group]) return
    if (!masterTaxonomy.value[group].includes(tag)) {
      masterTaxonomy.value[group].push(tag)
    }
  }

  const removeTag = (group, tag) => {
    if (!masterTaxonomy.value[group]) return
    masterTaxonomy.value[group] = masterTaxonomy.value[group].filter(t => t !== tag)
  }

  return {
    masterTaxonomy,
    addTag,
    removeTag
  }
}
