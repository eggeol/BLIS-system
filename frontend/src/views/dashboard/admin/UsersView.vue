<template>
  <section class="room-view">
    <div v-if="usersMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ usersMessage }}</span>
    </div>
    <div v-if="usersError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ usersError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <UserRound :size="18" />
          <h3>Users</h3>
        </div>
        <button type="button" class="primary-btn add-room-btn" @click="openCreateUserModal">
          <Plus :size="16" />
          Add User
        </button>
      </header>

      <div class="management-toolbar">
        <input v-model.trim="userFilters.search" type="text" class="text-input" placeholder="Search name, email, or student ID" />
        <select v-model="userFilters.role" class="text-input narrow">
          <option value="">All roles</option>
          <option value="student">Student</option>
          <option value="staff_master_examiner">Staff / Master Examiner</option>
          <option value="admin">Admin</option>
        </select>
        <select v-model="userFilters.year_level" class="text-input narrow">
          <option value="">All year levels</option>
          <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <select v-model="userFilters.archive_state" class="text-input narrow">
          <option value="current">Current records</option>
          <option value="archived">Archived records</option>
          <option value="all">All records</option>
        </select>
        <select v-model="userFilters.status" class="text-input narrow">
          <option value="">All status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div v-if="usersLoading && adminUsers.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading users</h4>
        <p>Fetching accounts and role assignments.</p>
      </div>

      <div v-else-if="adminUsers.length === 0" class="room-empty-state">
        <UserRound :size="42" />
        <h4>No users found</h4>
        <p>Adjust filters or create a new account.</p>
      </div>

      <div v-else class="user-directory-shell">
        <div class="user-directory-summary">
          <article
            v-for="card in userDirectorySummary"
            :key="card.key"
            class="user-directory-summary-card"
            :class="`is-${card.tone}`"
          >
            <span>{{ card.label }}</span>
            <strong>{{ card.value }}</strong>
            <small>Displayed records</small>
          </article>
        </div>

        <section v-if="currentStudentYearGroups.length > 0" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>Current Students</h4>
              <p>Organized by year level for the active school records.</p>
            </div>
          </header>

          <div class="directory-group-stack">
            <article
              v-for="group in currentStudentYearGroups"
              :key="group.key"
              class="directory-group-card"
            >
              <div class="directory-group-head">
                <div>
                  <strong>{{ group.label }}</strong>
                  <p>{{ group.count }} student{{ group.count === 1 ? '' : 's' }}</p>
                </div>
                <span class="pill navy">{{ group.count }}</span>
              </div>

              <div class="user-directory-grid">
                <article v-for="user in group.users" :key="user.id" class="directory-user-card">
                  <div class="directory-user-copy">
                    <strong>{{ user.name }}</strong>
                    <p v-if="user.student_id">Student ID: {{ user.student_id }}</p>
                    <p>{{ user.email }}</p>
                  </div>

                  <div class="management-inline">
                    <span class="pill navy">{{ yearLevelLabel(user.year_level) }}</span>
                    <span class="pill" :class="user.is_active ? 'success' : 'neutral'">
                      {{ user.is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>

                  <div class="management-actions compact">
                    <button type="button" class="ghost-btn" @click="openEditUserModal(user)">
                      <Pencil :size="15" />
                      Edit
                    </button>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="usersSaving"
                      @click="handleToggleUserArchive(user, true)"
                    >
                      <ArchiveRestore :size="15" />
                      Archive
                    </button>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="usersRecovering"
                      @click="openRecoverUserModal(user)"
                    >
                      <ShieldCheck :size="15" />
                      Recover
                    </button>
                  </div>
                </article>
              </div>
            </article>
          </div>
        </section>

        <section v-if="archivedStudentYearGroups.length > 0" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>Archived Student Records</h4>
              <p>Graduates and inactive academic records stay here for history and reporting.</p>
            </div>
          </header>

          <div class="directory-group-stack">
            <article
              v-for="group in archivedStudentYearGroups"
              :key="group.key"
              class="directory-group-card archived"
            >
              <div class="directory-group-head">
                <div>
                  <strong>{{ group.label }}</strong>
                  <p>{{ group.count }} archived record{{ group.count === 1 ? '' : 's' }}</p>
                </div>
                <span class="pill neutral">{{ group.count }}</span>
              </div>

              <div class="user-directory-grid">
                <article v-for="user in group.users" :key="user.id" class="directory-user-card archived">
                  <div class="directory-user-copy">
                    <strong>{{ user.name }}</strong>
                    <p v-if="user.student_id">Student ID: {{ user.student_id }}</p>
                    <p>{{ user.email }}</p>
                    <p v-if="user.archived_at">Archived {{ new Date(user.archived_at).toLocaleDateString() }}</p>
                  </div>

                  <div class="management-inline">
                    <span class="pill neutral">Archived</span>
                    <span class="pill navy">{{ yearLevelLabel(user.year_level) }}</span>
                    <span class="pill" :class="user.is_active ? 'success' : 'neutral'">
                      {{ user.is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>

                  <div class="management-actions compact">
                    <button type="button" class="ghost-btn" @click="openEditUserModal(user)">
                      <Pencil :size="15" />
                      Edit
                    </button>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="usersSaving"
                      @click="handleToggleUserArchive(user, false)"
                    >
                      <ArchiveRestore :size="15" />
                      Restore
                    </button>
                    <button
                      type="button"
                      class="ghost-btn"
                      :disabled="usersRecovering"
                      @click="openRecoverUserModal(user)"
                    >
                      <ShieldCheck :size="15" />
                      Recover
                    </button>
                  </div>
                </article>
              </div>
            </article>
          </div>
        </section>

        <section v-if="managementAccountGroups.length > 0" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>Staff and Admin Accounts</h4>
              <p>Management accounts stay separate from the student directory.</p>
            </div>
          </header>

          <div class="directory-group-stack">
            <article
              v-for="group in managementAccountGroups"
              :key="group.key"
              class="directory-group-card"
            >
              <div class="directory-group-head">
                <div>
                  <strong>{{ group.label }}</strong>
                  <p>{{ group.users.length }} account{{ group.users.length === 1 ? '' : 's' }}</p>
                </div>
                <span class="pill neutral">{{ group.users.length }}</span>
              </div>

              <div class="user-directory-grid">
                <article v-for="user in group.users" :key="user.id" class="directory-user-card">
                  <div class="directory-user-copy">
                    <strong>{{ user.name }}</strong>
                    <p>{{ displayMemberRole(user.role) }}</p>
                    <p>{{ user.email }}</p>
                  </div>

                  <div class="management-inline">
                    <span class="pill neutral">{{ displayMemberRole(user.role) }}</span>
                    <span class="pill" :class="user.is_active ? 'success' : 'neutral'">
                      {{ user.is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </div>

                  <div class="management-actions compact">
                    <button type="button" class="ghost-btn" @click="openEditUserModal(user)">
                      <Pencil :size="15" />
                      Edit
                    </button>
                  </div>
                </article>
              </div>
            </article>
          </div>
        </section>
      </div>

      <div v-if="usersPagination.total > 0" class="management-toolbar users-pagination">
        <p class="muted">{{ usersRangeLabel }}</p>
        <div class="management-actions">
          <button
            type="button"
            class="ghost-btn"
            :disabled="usersLoading || !canGoToPreviousUsersPage"
            @click="loadAdminUsers({ page: usersPagination.current_page - 1 })"
          >
            Previous
          </button>
          <span class="pill neutral">
            Page {{ usersPagination.current_page }} of {{ usersPagination.last_page }}
          </span>
          <button
            type="button"
            class="ghost-btn"
            :disabled="usersLoading || !canGoToNextUsersPage"
            @click="loadAdminUsers({ page: usersPagination.current_page + 1 })"
          >
            Next
          </button>
        </div>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showUserModal" class="modal-backdrop" @click.self="closeUserModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>{{ userForm.id ? 'Edit User' : 'Create User' }}</h4>
            <button type="button" class="modal-close" @click="closeUserModal">
              <X :size="16" />
            </button>
          </header>

          <label class="field-stack">
            <span class="field-label">Full Name</span>
            <input v-model.trim="userForm.name" type="text" class="text-input" maxlength="255" />
          </label>

          <label class="field-stack">
            <span class="field-label">Email</span>
            <input v-model.trim="userForm.email" type="email" class="text-input" maxlength="255" />
          </label>

          <label class="field-stack">
            <span class="field-label">Student ID</span>
            <input
              v-model.trim="userForm.student_id"
              type="text"
              class="text-input"
              maxlength="32"
              inputmode="numeric"
              placeholder="e.g. 2301290"
            />
            <small class="muted">Required for student accounts. Leave blank for staff/admin.</small>
          </label>

          <label v-if="userForm.role === 'student'" class="field-stack">
            <span class="field-label">Year Level</span>
            <select v-model="userForm.year_level" class="text-input">
              <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <small class="muted">Use this to keep current students grouped by year in the admin records.</small>
          </label>

          <div class="inline-form">
            <label class="field-stack grow">
              <span class="field-label">Role</span>
              <select v-model="userForm.role" class="text-input">
                <option value="student">Student</option>
                <option value="staff_master_examiner">Staff / Master Examiner</option>
                <option value="admin">Admin</option>
              </select>
            </label>
            <label class="check-item">
              <input v-model="userForm.is_active" type="checkbox" />
              <span>Account active</span>
            </label>
          </div>

          <label v-if="userForm.role === 'student'" class="check-item">
            <input v-model="userForm.archived" type="checkbox" />
            <span>Archive this student record</span>
          </label>

          <label class="field-stack">
            <span class="field-label">{{ userForm.id ? 'New Password (optional)' : 'Password' }}</span>
            <input v-model="userForm.password" type="password" class="text-input" minlength="8" />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="usersSaving" @click="closeUserModal">Cancel</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="usersSaving || !userForm.name.trim() || !userForm.email.trim() || (userForm.role === 'student' && (!userForm.student_id.trim() || !userForm.year_level)) || (!userForm.id && userForm.password.length < 8)"
              @click="handleSaveUser"
            >
              <RefreshCw v-if="usersSaving" :size="14" class="spin-soft" />
              <span>{{ usersSaving ? 'Saving...' : (userForm.id ? 'Update User' : 'Create User') }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showRecoverUserModal" class="modal-backdrop" @click.self="closeRecoverUserModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Recover Student Account</h4>
            <button type="button" class="modal-close" @click="closeRecoverUserModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Recover access for <strong>{{ recoverTargetUser?.name }}</strong>
            (<code>{{ recoverTargetUser?.email }}</code>).
            This keeps historical exam data under the same account.
          </p>

          <label class="field-stack">
            <span class="field-label">Verify Student ID</span>
            <input
              v-model.trim="recoverForm.student_id"
              type="text"
              class="text-input"
              maxlength="32"
              inputmode="numeric"
              placeholder="Enter student ID on file"
            />
          </label>

          <label class="field-stack">
            <span class="field-label">New Email</span>
            <input
              v-model.trim="recoverForm.email"
              type="email"
              class="text-input"
              maxlength="255"
              placeholder="new-email@example.com"
            />
          </label>

          <label class="field-stack">
            <span class="field-label">Temporary Password</span>
            <input
              v-model="recoverForm.password"
              type="password"
              class="text-input"
              minlength="8"
              placeholder="At least 8 characters"
            />
            <small class="muted">All active sessions will be revoked after recovery.</small>
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="usersRecovering" @click="closeRecoverUserModal">Cancel</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="usersRecovering || !recoverForm.student_id.trim() || !recoverForm.email.trim() || recoverForm.password.length < 8"
              @click="handleRecoverUserAccount"
            >
              <RefreshCw v-if="usersRecovering" :size="14" class="spin-soft" />
              <span>{{ usersRecovering ? 'Recovering...' : 'Recover Account' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { AlertCircle, ArchiveRestore, CheckCircle2, Pencil, Plus, RefreshCw, ShieldCheck, UserRound, X } from 'lucide-vue-next'
import { useUsersModule } from '../composables/useUsersModule'

const {
  adminUsers,
  usersLoading,
  usersSaving,
  usersRecovering,
  usersError,
  usersMessage,
  showUserModal,
  showRecoverUserModal,
  recoverTargetUser,
  userFilters,
  usersPagination,
  recoverForm,
  userForm,
  canGoToPreviousUsersPage,
  canGoToNextUsersPage,
  usersRangeLabel,
  currentStudentYearGroups,
  archivedStudentYearGroups,
  managementAccountGroups,
  userDirectorySummary,
  displayMemberRole,
  yearLevelLabel,
  yearLevelOptions,
  isUserArchived,
  openCreateUserModal,
  openEditUserModal,
  closeUserModal,
  openRecoverUserModal,
  closeRecoverUserModal,
  loadAdminUsers,
  handleSaveUser,
  handleToggleUserArchive,
  handleRecoverUserAccount,
} = useUsersModule()
</script>

<style scoped src="../dashboard.css"></style>
