<template>
  <div class="p-8 font-sans antialiased text-slate-900">
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">User Management</h1>
        <p class="text-slate-500 mt-1 font-medium">Manage accounts, configure dietary bounds, and simulate user sessions.</p>
      </div>
      <button @click="openCreateModal" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-lg font-bold shadow-sm transition-colors flex items-center space-x-2">
        <UserPlus :size="18" />
        <span>Invite User</span>
      </button>
    </div>

    <div v-if="usersError" class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm font-semibold flex items-center">
      <svg class="w-5 h-5 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
      {{ usersError }}
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200">
            <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Name & Email</th>
            <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Role</th>
            <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Dietary Flags</th>
            <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loadingUsers">
            <td colspan="4" class="px-6 py-8 text-center text-slate-500 font-medium">
              <span class="inline-block animate-spin w-5 h-5 border-2 border-emerald-600 border-t-transparent rounded-full mr-2 align-middle"></span>
              Loading users from server...
            </td>
          </tr>
          <tr v-else-if="users.length === 0">
            <td colspan="4" class="px-6 py-8 text-center text-slate-400 font-medium italic">
              No users found. Click "Invite User" to create an account.
            </td>
          </tr>
          <tr v-for="user in users" :key="user.id" class="hover:bg-slate-50/50 transition-colors">
            <td class="px-6 py-4">
              <div class="font-bold text-slate-900">{{ user.name }}</div>
              <div class="text-xs text-slate-500 mt-0.5">{{ user.email }}</div>
            </td>
            <td class="px-6 py-4">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"
                    :class="user.role === 'admin' ? 'bg-indigo-100 text-indigo-700' : (user.role === 'vendor' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700')">
                {{ user.role ? (user.role.charAt(0).toUpperCase() + user.role.slice(1)) : 'Unknown' }}
              </span>
            </td>
            <td class="px-6 py-4">
              <div v-if="hasFlags(user.dietary_flags)" class="flex flex-wrap gap-1">
                <span v-for="f in flattenFlags(user.dietary_flags)" :key="f" class="inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded border bg-slate-100 text-slate-600 border-slate-200">
                  {{ f }}
                </span>
              </div>
              <div v-else class="text-xs text-slate-400 italic">No restrictions</div>
            </td>
            <td class="px-6 py-4 text-right">
              <div class="flex items-center justify-end space-x-2">
                <button @click="openEditModal(user)" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Edit Flags">
                  <Edit2 :size="16" />
                </button>
                <button @click="testAsUser(user)" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Test as User">
                  <Play :size="16" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create User Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Create New User</h3>
            <p class="text-xs text-slate-500 mt-1">Add a vendor, student, or admin account.</p>
          </div>
          <button @click="showCreateModal = false" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="handleCreateUser" class="p-6 space-y-4">
          <div v-if="createError" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg text-sm font-semibold flex items-center">
            <svg class="w-5 h-5 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            {{ createError }}
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Full Name / Stall Name</label>
            <input v-model="createForm.name" type="text" required placeholder="e.g. Test Vendor or Juan Dela Cruz"
                   class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all text-sm font-medium">
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Email Address</label>
            <input v-model="createForm.email" type="email" required placeholder="e.g. testvendor@optimeal.test"
                   class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all text-sm font-medium">
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Role</label>
            <select v-model="createForm.role" required
                    class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all text-sm font-semibold bg-white">
              <option value="vendor">Vendor</option>
              <option value="student">Student / Customer</option>
              <option value="admin">Administrator</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Password</label>
            <input v-model="createForm.password" type="password" required placeholder="••••••••" minlength="6"
                   class="w-full px-4 py-2.5 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all text-sm font-medium">
          </div>

          <div class="pt-2 flex justify-end space-x-3">
            <button type="button" @click="showCreateModal = false" class="px-4 py-2 rounded-lg font-bold text-slate-600 hover:bg-slate-100 transition-colors text-sm">Cancel</button>
            <button type="submit" :disabled="creating" class="px-6 py-2 bg-emerald-600 text-white rounded-lg font-bold hover:bg-emerald-700 shadow-sm transition-colors text-sm flex items-center space-x-2 disabled:opacity-50">
              <span v-if="creating" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full mr-2"></span>
              <span>Create Account</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Flags Modal -->
    <div v-if="editingUser" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Edit Dietary Flags</h3>
            <p class="text-xs text-slate-500 mt-1">Configuring profile for: <span class="font-bold text-slate-700">{{ editingUser.name }}</span></p>
          </div>
          <button @click="editingUser = null" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors">
            <X :size="20" />
          </button>
        </div>
        
        <div class="p-6 overflow-y-auto">
          <div class="space-y-6">
            <div>
              <label class="block text-sm font-bold text-slate-800 mb-3">Medical Allergens</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="a in masterTaxonomy.allergens" :key="a" @click="toggleFlag('allergens', a)"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors"
                        :class="editForm.allergens.includes(a) ? 'bg-rose-50 border-rose-300 text-rose-800' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300'">
                  {{ a }}
                </button>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-bold text-slate-800 mb-3">Digestive Sensitivities</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="s in masterTaxonomy.sensitivities" :key="s" @click="toggleFlag('sensitivities', s)"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors"
                        :class="editForm.sensitivities.includes(s) ? 'bg-amber-50 border-amber-300 text-amber-800' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300'">
                  {{ s }}
                </button>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-bold text-slate-800 mb-3">Dietary Boundaries</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="d in masterTaxonomy.dietary" :key="d" @click="toggleFlag('dietary', d)"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors"
                        :class="editForm.dietary.includes(d) ? 'bg-indigo-50 border-indigo-300 text-indigo-800' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300'">
                  {{ d }}
                </button>
              </div>
            </div>
          </div>
        </div>
        
        <div class="p-6 border-t border-slate-100 flex justify-end space-x-3 bg-slate-50">
          <button @click="editingUser = null" class="px-4 py-2 rounded-lg font-bold text-slate-600 hover:bg-slate-200 transition-colors">Cancel</button>
          <button @click="saveFlags" class="px-6 py-2 bg-emerald-600 text-white rounded-lg font-bold hover:bg-emerald-700 shadow-sm transition-colors">Save Changes</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { UserPlus, Edit2, Play, X } from 'lucide-vue-next'
import { useSandbox } from '../../stores/sandbox'
import { useRouter } from 'vue-router'
import { useTaxonomy } from '../../stores/taxonomy'

const { activePreviewMode, simulatedDietaryFlags } = useSandbox()
const { masterTaxonomy } = useTaxonomy()
const router = useRouter()

const users = ref([])
const loadingUsers = ref(false)
const usersError = ref('')

const fetchUsers = async () => {
  loadingUsers.value = true
  usersError.value = ''
  try {
    const res = await fetch('/api/admin/users')
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to fetch users')
    }
    users.value = data
  } catch (err) {
    console.error('Error fetching users:', err)
    usersError.value = err.message
  } finally {
    loadingUsers.value = false
  }
}

onMounted(() => {
  fetchUsers()
})

const showCreateModal = ref(false)
const creating = ref(false)
const createError = ref('')
const createForm = reactive({
  name: '',
  email: '',
  role: 'vendor',
  password: ''
})

const openCreateModal = () => {
  createError.value = ''
  createForm.name = ''
  createForm.email = ''
  createForm.role = 'vendor'
  createForm.password = ''
  showCreateModal.value = true
}

const handleCreateUser = async () => {
  createError.value = ''
  creating.value = true
  try {
    const res = await fetch('/api/admin/users', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(createForm)
    })
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to create user')
    }
    showCreateModal.value = false
    await fetchUsers()
  } catch (err) {
    console.error('Error creating user:', err)
    createError.value = err.message
  } finally {
    creating.value = false
  }
}

const editingUser = ref(null)
const editForm = reactive({
  allergens: [],
  sensitivities: [],
  dietary: []
})

const flattenFlags = (flags) => {
  if (!flags) return []
  if (Array.isArray(flags)) return flags
  return [...(flags.allergens || []), ...(flags.sensitivities || []), ...(flags.dietary || [])]
}

const hasFlags = (flags) => {
  return flattenFlags(flags).length > 0
}

const openEditModal = (user) => {
  editingUser.value = user
  const f = user.dietary_flags || {}
  editForm.allergens = [...(f.allergens || [])]
  editForm.sensitivities = [...(f.sensitivities || [])]
  editForm.dietary = [...(f.dietary || [])]
}

const toggleFlag = (category, val) => {
  const arr = editForm[category]
  const idx = arr.indexOf(val)
  if (idx > -1) arr.splice(idx, 1)
  else arr.push(val)
}

const saveFlags = () => {
  if (editingUser.value) {
    editingUser.value.dietary_flags = {
      allergens: [...editForm.allergens],
      sensitivities: [...editForm.sensitivities],
      dietary: [...editForm.dietary]
    }
  }
  editingUser.value = null
}

const testAsUser = (user) => {
  const flags = user.dietary_flags || { allergens: [], sensitivities: [], dietary: [] }
  simulatedDietaryFlags.value = flags
  activePreviewMode.value = 'customer'
  router.push('/customer/menu')
}
</script>

