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
      <AlertCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ usersError }}</span>
    </div>

    <div v-if="successMessage" class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-sm font-semibold flex items-center">
      <CheckCircle :size="18" class="mr-2 shrink-0" />
      <span>{{ successMessage }}</span>
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
              <div class="flex items-center space-x-2">
                <span class="font-bold text-slate-900">{{ user.name }}</span>
                <span
                  v-if="user.is_active === false"
                  class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 border border-rose-200"
                >
                  Inactive
                </span>
              </div>
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
              <div class="relative inline-block text-left">
                <button
                  type="button"
                  :id="'user-actions-btn-' + user.id"
                  @click.stop="toggleActionMenu(user.id)"
                  class="p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors cursor-pointer"
                  title="User Actions"
                >
                  <MoreVertical :size="18" />
                </button>

                <!-- Backdrop to close on click outside -->
                <div
                  v-if="openMenuId === user.id"
                  class="fixed inset-0 z-30 cursor-default"
                  @click.stop="openMenuId = null"
                ></div>

                <!-- Dropdown Action Menu -->
                <div
                  v-if="openMenuId === user.id"
                  class="absolute right-0 mt-1 w-52 bg-white rounded-xl shadow-xl border border-slate-200 py-1.5 z-40 text-xs font-semibold text-slate-700 divide-y divide-slate-100 text-left"
                >
                  <div class="py-1">
                    <button
                      type="button"
                      @click="openEditProfileModal(user)"
                      class="w-full px-4 py-2 hover:bg-slate-50 flex items-center space-x-2.5 text-slate-700 hover:text-emerald-700 transition-colors cursor-pointer"
                    >
                      <UserCheck :size="14" class="text-slate-400" />
                      <span>Edit Profile</span>
                    </button>

                    <button
                      type="button"
                      @click="openChangeRoleModal(user)"
                      class="w-full px-4 py-2 hover:bg-slate-50 flex items-center space-x-2.5 text-slate-700 hover:text-indigo-700 transition-colors cursor-pointer"
                    >
                      <Shield :size="14" class="text-slate-400" />
                      <span>Change Role</span>
                    </button>

                    <button
                      type="button"
                      @click="openResetPasswordModal(user)"
                      class="w-full px-4 py-2 hover:bg-slate-50 flex items-center space-x-2.5 text-slate-700 hover:text-amber-700 transition-colors cursor-pointer"
                    >
                      <Key :size="14" class="text-slate-400" />
                      <span>Reset Password</span>
                    </button>

                    <button
                      type="button"
                      @click="openEditModal(user)"
                      class="w-full px-4 py-2 hover:bg-slate-50 flex items-center space-x-2.5 text-slate-700 hover:text-teal-700 transition-colors cursor-pointer"
                    >
                      <Sliders :size="14" class="text-slate-400" />
                      <span>Dietary Flags</span>
                    </button>

                    <button
                      type="button"
                      @click="testAsUser(user)"
                      class="w-full px-4 py-2 hover:bg-slate-50 flex items-center space-x-2.5 text-slate-700 hover:text-purple-700 transition-colors cursor-pointer"
                    >
                      <Play :size="14" class="text-slate-400" />
                      <span>Simulate Session</span>
                    </button>
                  </div>

                  <div class="py-1">
                    <button
                      v-if="user.is_active !== false"
                      type="button"
                      @click="promptDeactivate(user)"
                      class="w-full px-4 py-2 hover:bg-rose-50 flex items-center space-x-2.5 text-rose-600 transition-colors cursor-pointer"
                    >
                      <UserX :size="14" class="text-rose-500" />
                      <span>Deactivate User</span>
                    </button>

                    <button
                      v-else
                      type="button"
                      @click="promptReactivate(user)"
                      class="w-full px-4 py-2 hover:bg-emerald-50 flex items-center space-x-2.5 text-emerald-600 transition-colors cursor-pointer"
                    >
                      <CheckCircle :size="14" class="text-emerald-500" />
                      <span>Reactivate User</span>
                    </button>

                    <button
                      type="button"
                      @click="promptDelete(user)"
                      class="w-full px-4 py-2 hover:bg-rose-50 flex items-center space-x-2.5 text-rose-700 font-bold transition-colors cursor-pointer"
                    >
                      <Trash2 :size="14" class="text-rose-600" />
                      <span>Delete User</span>
                    </button>
                  </div>
                </div>
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

    <!-- Red Confirmation Modal for Deactivate / Reactivate / Delete -->
    <div
      v-if="confirmModal.isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
    >
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden flex flex-col border border-rose-100">
        <div class="p-6 bg-rose-50/70 border-b border-rose-100 flex items-start space-x-4">
          <div class="w-10 h-10 rounded-full bg-rose-100 border border-rose-200 text-rose-600 flex items-center justify-center shrink-0">
            <AlertTriangle :size="20" />
          </div>
          <div>
            <h3 class="text-lg font-black text-rose-950">{{ confirmModal.title }}</h3>
            <p class="text-xs text-rose-700 font-medium mt-0.5">Please confirm this administrative action.</p>
          </div>
        </div>

        <div class="p-6 space-y-4">
          <p class="text-sm text-slate-700 leading-relaxed">
            {{ confirmModal.message }}
          </p>

          <div
            v-if="confirmModal.error"
            class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-2.5 rounded-xl text-xs font-semibold flex items-center"
          >
            <AlertCircle :size="16" class="mr-2 shrink-0" />
            <span>{{ confirmModal.error }}</span>
          </div>
        </div>

        <div class="p-6 border-t border-slate-100 flex items-center justify-end space-x-3 bg-slate-50">
          <button
            type="button"
            @click="closeConfirmModal"
            :disabled="confirmModal.loading"
            class="px-4 py-2 rounded-xl font-bold text-slate-600 hover:bg-slate-200 transition-colors text-sm cursor-pointer"
          >
            Cancel
          </button>

          <button
            id="btn-confirm-action"
            type="button"
            @click="executeConfirmAction"
            :disabled="confirmModal.loading"
            class="px-5 py-2.5 rounded-xl text-white font-bold text-sm shadow-sm transition-all flex items-center space-x-2 cursor-pointer disabled:opacity-50"
            :class="confirmModal.actionType === 'delete' || confirmModal.actionType === 'deactivate' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'"
          >
            <span
              v-if="confirmModal.loading"
              class="inline-block animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"
            ></span>
            <span>{{ confirmModal.confirmButtonText }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Change Role Modal -->
    <div
      v-if="roleModal.isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
    >
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full overflow-hidden flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Change Role</h3>
            <p class="text-xs text-slate-500 mt-1">For <span class="font-bold text-slate-700">{{ roleModal.user?.name }}</span></p>
          </div>
          <button @click="roleModal.isOpen = false" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors cursor-pointer">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="executeChangeRole" class="p-6 space-y-4">
          <div v-if="roleModal.error" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-2.5 rounded-xl text-xs font-semibold">
            {{ roleModal.error }}
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Select Role</label>
            <select
              v-model="roleModal.newRole"
              required
              class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none font-semibold text-sm bg-white cursor-pointer"
            >
              <option value="student">Student / Customer</option>
              <option value="vendor">Vendor</option>
              <option value="admin">Administrator</option>
            </select>
          </div>

          <div class="pt-2 flex justify-end space-x-3">
            <button type="button" @click="roleModal.isOpen = false" class="px-4 py-2 rounded-xl font-bold text-slate-600 hover:bg-slate-100 text-sm cursor-pointer">Cancel</button>
            <button type="submit" :disabled="roleModal.loading" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-sm transition-all text-sm flex items-center space-x-2 cursor-pointer disabled:opacity-50">
              <span v-if="roleModal.loading" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
              <span>Update Role</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Reset Password Modal -->
    <div
      v-if="resetPasswordModal.isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
    >
      <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full overflow-hidden flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Reset Password</h3>
            <p class="text-xs text-slate-500 mt-1">For <span class="font-bold text-slate-700">{{ resetPasswordModal.user?.name }}</span></p>
          </div>
          <button @click="resetPasswordModal.isOpen = false" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors cursor-pointer">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="executeResetPassword" class="p-6 space-y-4">
          <div v-if="resetPasswordModal.error" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-2.5 rounded-xl text-xs font-semibold">
            {{ resetPasswordModal.error }}
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">New Password</label>
            <input
              v-model="resetPasswordModal.password"
              type="password"
              required
              minlength="6"
              placeholder="Minimum 6 characters"
              class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-medium"
            />
          </div>

          <div class="pt-2 flex justify-end space-x-3">
            <button type="button" @click="resetPasswordModal.isOpen = false" class="px-4 py-2 rounded-xl font-bold text-slate-600 hover:bg-slate-100 text-sm cursor-pointer">Cancel</button>
            <button type="submit" :disabled="resetPasswordModal.loading" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl font-bold shadow-sm transition-all text-sm flex items-center space-x-2 cursor-pointer disabled:opacity-50">
              <span v-if="resetPasswordModal.loading" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
              <span>Set Password</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Profile Modal -->
    <div
      v-if="profileModal.isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
    >
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <div>
            <h3 class="text-xl font-bold text-slate-900">Edit User Profile</h3>
            <p class="text-xs text-slate-500 mt-1">Update basic account information.</p>
          </div>
          <button @click="profileModal.isOpen = false" class="p-2 text-slate-400 hover:bg-slate-200 rounded-full transition-colors cursor-pointer">
            <X :size="20" />
          </button>
        </div>

        <form @submit.prevent="executeEditProfile" class="p-6 space-y-4">
          <div v-if="profileModal.error" class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-2.5 rounded-xl text-xs font-semibold">
            {{ profileModal.error }}
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Full Name</label>
            <input
              v-model="profileModal.name"
              type="text"
              required
              class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-sm font-medium"
            />
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Email Address</label>
            <input
              v-model="profileModal.email"
              type="email"
              required
              class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-sm font-medium"
            />
          </div>

          <div class="pt-2 flex justify-end space-x-3">
            <button type="button" @click="profileModal.isOpen = false" class="px-4 py-2 rounded-xl font-bold text-slate-600 hover:bg-slate-100 text-sm cursor-pointer">Cancel</button>
            <button type="submit" :disabled="profileModal.loading" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold shadow-sm transition-all text-sm flex items-center space-x-2 cursor-pointer disabled:opacity-50">
              <span v-if="profileModal.loading" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
              <span>Save Profile</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import {
  UserPlus,
  Edit2,
  Play,
  X,
  MoreVertical,
  AlertTriangle,
  AlertCircle,
  CheckCircle,
  UserCheck,
  Shield,
  Key,
  UserX,
  Trash2,
  Sliders
} from 'lucide-vue-next'
import { useSandbox } from '../../stores/sandbox'
import { useRouter } from 'vue-router'
import { useTaxonomy } from '../../stores/taxonomy'

const { activePreviewMode, simulatedDietaryFlags } = useSandbox()
const { masterTaxonomy } = useTaxonomy()
const router = useRouter()

const users = ref([])
const loadingUsers = ref(false)
const usersError = ref('')
const successMessage = ref('')
const openMenuId = ref(null)

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

const toggleActionMenu = (userId) => {
  openMenuId.value = openMenuId.value === userId ? null : userId
}

// Red Confirmation Modal (Deactivate / Reactivate / Delete)
const confirmModal = reactive({
  isOpen: false,
  title: '',
  message: '',
  confirmButtonText: '',
  actionType: '',
  targetUser: null,
  loading: false,
  error: ''
})

const promptDeactivate = (user) => {
  openMenuId.value = null
  confirmModal.isOpen = true
  confirmModal.title = `Deactivate ${user.name}?`
  confirmModal.message = `Are you sure you want to deactivate ${user.name} (${user.email})? This user will immediately lose access and will be prevented from logging in.`
  confirmModal.confirmButtonText = 'Deactivate User'
  confirmModal.actionType = 'deactivate'
  confirmModal.targetUser = user
  confirmModal.error = ''
}

const promptReactivate = (user) => {
  openMenuId.value = null
  confirmModal.isOpen = true
  confirmModal.title = `Reactivate ${user.name}?`
  confirmModal.message = `Are you sure you want to restore active access for ${user.name} (${user.email})?`
  confirmModal.confirmButtonText = 'Reactivate User'
  confirmModal.actionType = 'reactivate'
  confirmModal.targetUser = user
  confirmModal.error = ''
}

const promptDelete = (user) => {
  openMenuId.value = null
  confirmModal.isOpen = true
  confirmModal.title = `Permanently Delete ${user.name}?`
  confirmModal.message = `Are you sure you want to permanently delete ${user.name} (${user.email})? This action cannot be undone.`
  confirmModal.confirmButtonText = 'Delete User'
  confirmModal.actionType = 'delete'
  confirmModal.targetUser = user
  confirmModal.error = ''
}

const closeConfirmModal = () => {
  confirmModal.isOpen = false
  confirmModal.targetUser = null
  confirmModal.error = ''
}

const executeConfirmAction = async () => {
  if (!confirmModal.targetUser) return
  confirmModal.loading = true
  confirmModal.error = ''
  const user = confirmModal.targetUser

  try {
    if (confirmModal.actionType === 'deactivate' || confirmModal.actionType === 'reactivate') {
      const is_active = confirmModal.actionType === 'reactivate'
      const res = await fetch(`/api/admin/users/${user.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_active })
      })
      const data = await res.json()
      if (!res.ok) {
        throw new Error(data.error || 'Failed to update account status.')
      }
      user.is_active = is_active
      successMessage.value = `User "${user.name}" ${is_active ? 'reactivated' : 'deactivated'} successfully.`
    } else if (confirmModal.actionType === 'delete') {
      const res = await fetch(`/api/admin/users/${user.id}`, {
        method: 'DELETE'
      })
      const data = await res.json()
      if (!res.ok) {
        throw new Error(data.error || 'Failed to delete user.')
      }
      users.value = users.value.filter((u) => u.id !== user.id)
      successMessage.value = `User "${user.name}" deleted successfully.`
    }

    setTimeout(() => {
      successMessage.value = ''
    }, 4000)
    closeConfirmModal()
  } catch (err) {
    console.error('Action error:', err)
    confirmModal.error = err.message
  } finally {
    confirmModal.loading = false
  }
}

// Change Role Modal
const roleModal = reactive({
  isOpen: false,
  user: null,
  newRole: 'student',
  loading: false,
  error: ''
})

const openChangeRoleModal = (user) => {
  openMenuId.value = null
  roleModal.isOpen = true
  roleModal.user = user
  roleModal.newRole = user.role || 'student'
  roleModal.error = ''
}

const executeChangeRole = async () => {
  if (!roleModal.user) return
  roleModal.loading = true
  roleModal.error = ''
  try {
    const res = await fetch(`/api/admin/users/${roleModal.user.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role: roleModal.newRole })
    })
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to update user role.')
    }
    roleModal.user.role = roleModal.newRole
    successMessage.value = `Role for "${roleModal.user.name}" changed to ${roleModal.newRole}.`
    setTimeout(() => { successMessage.value = '' }, 4000)
    roleModal.isOpen = false
  } catch (err) {
    roleModal.error = err.message
  } finally {
    roleModal.loading = false
  }
}

// Reset Password Modal
const resetPasswordModal = reactive({
  isOpen: false,
  user: null,
  password: '',
  loading: false,
  error: ''
})

const openResetPasswordModal = (user) => {
  openMenuId.value = null
  resetPasswordModal.isOpen = true
  resetPasswordModal.user = user
  resetPasswordModal.password = ''
  resetPasswordModal.error = ''
}

const executeResetPassword = async () => {
  if (!resetPasswordModal.user) return
  resetPasswordModal.loading = true
  resetPasswordModal.error = ''
  try {
    const res = await fetch(`/api/admin/users/${resetPasswordModal.user.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: resetPasswordModal.password })
    })
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to reset password.')
    }
    successMessage.value = `Password for "${resetPasswordModal.user.name}" reset successfully.`
    setTimeout(() => { successMessage.value = '' }, 4000)
    resetPasswordModal.isOpen = false
  } catch (err) {
    resetPasswordModal.error = err.message
  } finally {
    resetPasswordModal.loading = false
  }
}

// Edit Profile Modal
const profileModal = reactive({
  isOpen: false,
  user: null,
  name: '',
  email: '',
  loading: false,
  error: ''
})

const openEditProfileModal = (user) => {
  openMenuId.value = null
  profileModal.isOpen = true
  profileModal.user = user
  profileModal.name = user.name || ''
  profileModal.email = user.email || ''
  profileModal.error = ''
}

const executeEditProfile = async () => {
  if (!profileModal.user) return
  profileModal.loading = true
  profileModal.error = ''
  try {
    const res = await fetch(`/api/admin/users/${profileModal.user.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: profileModal.name, email: profileModal.email })
    })
    const data = await res.json()
    if (!res.ok) {
      throw new Error(data.error || 'Failed to update profile.')
    }
    profileModal.user.name = profileModal.name
    profileModal.user.email = profileModal.email
    successMessage.value = `Profile for "${profileModal.user.name}" updated successfully.`
    setTimeout(() => { successMessage.value = '' }, 4000)
    profileModal.isOpen = false
  } catch (err) {
    profileModal.error = err.message
  } finally {
    profileModal.loading = false
  }
}
</script>

