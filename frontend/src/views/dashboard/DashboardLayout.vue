<template>
  <div class="layout-shell">
    <aside class="layout-sidebar" :class="{ collapsed: sidebarCollapsed }">
      <div class="layout-sidebar-top">
        <button class="layout-toggle" @click="sidebarCollapsed = !sidebarCollapsed">
          <ChevronRight v-if="sidebarCollapsed" :size="16" />
          <ChevronLeft v-else :size="16" />
        </button>

        <button class="layout-brand" @click="sidebarCollapsed = false">
          <span class="layout-brand-icon">
            <GraduationCap :size="20" />
          </span>
          <span v-if="!sidebarCollapsed" class="layout-brand-text">
            <strong>LNU LLE</strong>
            <small>Review System</small>
          </span>
        </button>
      </div>

      <nav class="layout-nav">
        <button
          v-for="item in navItems"
          :key="item.name"
          class="layout-nav-item"
          :class="{ active: currentRouteName === item.name }"
          @click="navigate(item.name)"
        >
          <component :is="item.icon" :size="18" class="layout-nav-icon" />
          <span v-if="!sidebarCollapsed" class="layout-nav-label">{{ item.label }}</span>
        </button>
      </nav>

      <div class="layout-sidebar-footer" v-if="!sidebarCollapsed">
        <div class="layout-user-tile">
          <span class="layout-avatar">{{ userInitials }}</span>
          <div>
            <strong>{{ displayName }}</strong>
            <small>{{ displayRole }}</small>
          </div>
        </div>

        <button class="layout-logout" @click="handleLogout">
          <LogOut :size="16" />
          Log out
        </button>
      </div>
    </aside>

    <section class="layout-main">
      <header class="layout-topbar">
        <div>
          <h1>{{ pageTitle }}</h1>
          <p>{{ pageSubtitle }}</p>
        </div>
      </header>

      <main class="layout-content">
        <RouterView />
      </main>
    </section>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import {
  BarChart3,
  BookOpen,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  DoorOpen,
  FileText,
  GraduationCap,
  LayoutDashboard,
  LogOut,
  Settings,
  UserRound,
} from 'lucide-vue-next'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const sidebarCollapsed = ref(false)

const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
const isManagementRole = computed(() => ['admin', 'staff_master_examiner'].includes(normalizedRole.value))
const isAdminRole = computed(() => normalizedRole.value === 'admin')

const studentNavItems = [
  { name: 'dashboard-home', label: 'Dashboard', icon: LayoutDashboard },
  { name: 'dashboard-rooms', label: 'Rooms', icon: DoorOpen },
  { name: 'dashboard-analytics', label: 'Analytics', icon: BarChart3 },
]

const staffNavItems = [
  { name: 'dashboard-library', label: 'Library', icon: BookOpen },
  { name: 'dashboard-room-management', label: 'Room', icon: DoorOpen },
  { name: 'dashboard-exams', label: 'Exams', icon: FileText },
  { name: 'dashboard-reports', label: 'Reports', icon: BarChart3 },
  { name: 'dashboard-settings', label: 'Settings', icon: Settings },
]

const adminNavItems = [
  { name: 'dashboard-users', label: 'Users', icon: UserRound },
  ...staffNavItems,
  { name: 'dashboard-audit', label: 'Audit', icon: ClipboardList },
]

const navItems = computed(() => {
  if (!isManagementRole.value) return studentNavItems
  return isAdminRole.value ? adminNavItems : staffNavItems
})

const currentRouteName = computed(() => String(route.name ?? ''))

const pageTitle = computed(() => String(route.meta?.title ?? 'Dashboard'))
const pageSubtitle = computed(() => String(route.meta?.sub ?? 'Manage your modules and workflows.'))

const displayName = computed(() => auth.user?.name ?? 'User')
const displayRole = computed(() => {
  if (normalizedRole.value === 'staff_master_examiner') return 'Staff / Master Examiner'
  if (normalizedRole.value === 'admin') return 'Administrator'
  return 'Student'
})

const userInitials = computed(() => {
  const parts = displayName.value.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'U'
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

function navigate(name) {
  if (currentRouteName.value === name) return
  router.push({ name })
}

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}
</script>

<style scoped>
.layout-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: auto 1fr;
  background:
    radial-gradient(circle at 88% 0%, rgba(201, 168, 76, 0.24), transparent 30%),
    linear-gradient(180deg, rgba(26, 35, 126, 0.08), transparent 40%),
    var(--lnu-bg);
}

.layout-sidebar {
  width: 260px;
  height: 100vh;
  height: 100dvh;
  position: sticky;
  top: 0;
  align-self: start;
  overflow-x: hidden;
  border-right: 1px solid rgba(240, 208, 128, 0.24);
  background: linear-gradient(180deg, var(--lnu-navy-deep), var(--lnu-navy));
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  transition: width 0.2s ease;
  color: rgba(255, 255, 255, 0.92);
}

.layout-sidebar.collapsed {
  width: 84px;
  padding-left: 10px;
  padding-right: 10px;
}

.layout-sidebar-top {
  display: flex;
  align-items: center;
  gap: 8px;
}

.layout-toggle {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  border: 1px solid rgba(240, 208, 128, 0.28);
  background: rgba(255, 255, 255, 0.07);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.layout-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  border: none;
  background: transparent;
  color: var(--lnu-white);
  text-align: left;
  padding: 4px;
}

.layout-brand-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(240, 208, 128, 0.18);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.layout-brand-text {
  display: grid;
  gap: 2px;
}

.layout-brand-text strong {
  font-size: 15px;
}

.layout-brand-text small {
  font-size: 13px;
  color: rgba(240, 208, 128, 0.92);
}

.layout-nav {
  margin-top: 18px;
  display: grid;
  gap: 4px;
}

.layout-nav-item {
  height: 40px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: rgba(255, 255, 255, 0.9);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 10px;
  text-align: left;
}

.layout-nav-item.active {
  background: linear-gradient(135deg, var(--lnu-gold-light), var(--lnu-gold));
  color: var(--lnu-navy-deep);
}

.layout-nav-label {
  font-size: 14px;
  font-weight: 600;
}

.layout-sidebar-footer {
  margin-top: auto;
  border-top: 1px solid rgba(240, 208, 128, 0.22);
  padding-top: 12px;
  display: grid;
  gap: 10px;
}

.layout-user-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.06);
}

.layout-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--lnu-gold);
  color: var(--lnu-navy-deep);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
}

.layout-user-tile strong {
  display: block;
  font-size: 14px;
}

.layout-user-tile small {
  display: block;
  margin-top: 2px;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.78);
}

.layout-logout {
  height: 38px;
  border: 1px solid rgba(240, 208, 128, 0.35);
  border-radius: 9px;
  background: rgba(240, 208, 128, 0.14);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-weight: 600;
}

.layout-main {
  min-width: 0;
  height: 100vh;
  height: 100dvh;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.layout-topbar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  border-bottom: 1px solid rgba(240, 208, 128, 0.3);
  background: linear-gradient(120deg, var(--lnu-navy), var(--lnu-navy-light));
  padding: 20px 24px;
  color: var(--lnu-white);
}

.layout-topbar h1 {
  margin: 0;
  font-size: 28px;
}

.layout-topbar p {
  margin: 6px 0 0;
  font-size: 15px;
  color: rgba(255, 255, 255, 0.82);
}

.layout-content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 22px 24px;
  background:
    radial-gradient(circle at 100% 0%, rgba(201, 168, 76, 0.1), transparent 25%),
    transparent;
}

@media (max-width: 900px) {
  .layout-shell {
    display: flex;
    flex-direction: column;
  }

  .layout-sidebar,
  .layout-sidebar.collapsed {
    width: 100%;
    height: auto;
    position: static;
    padding: 10px 12px 8px;
  }

  .layout-toggle {
    display: none;
  }

  .layout-main {
    height: auto;
  }

  .layout-topbar,
  .layout-content {
    padding: 16px;
  }
}
</style>
