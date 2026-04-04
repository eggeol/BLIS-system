<template>
  <div class="layout-shell">
    <aside class="layout-sidebar" :class="{ collapsed: sidebarCollapsed }">
      <div class="layout-sidebar-top">
        <button
          class="layout-toggle"
          :aria-expanded="(!sidebarCollapsed).toString()"
          aria-label="Toggle navigation"
          @click="sidebarCollapsed = !sidebarCollapsed"
        >
          <ChevronRight v-if="sidebarCollapsed" :size="16" />
          <ChevronLeft v-else :size="16" />
        </button>

        <button class="layout-brand" @click="sidebarCollapsed = false">
          <span class="layout-brand-icon">
            <GraduationCap :size="20" />
          </span>
          <span v-if="!sidebarCollapsed || isMobileViewport" class="layout-brand-text">
            <strong>LNU LLE</strong>
            <small>Review System</small>
          </span>
        </button>
      </div>

      <nav class="layout-nav">
        <button
          v-for="item in navItems"
          :key="item.routeName"
          class="layout-nav-item"
          :class="{ active: currentRouteName === item.routeName }"
          @click="navigate(item.routeName)"
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import { getDashboardNavItems } from './config/dashboardNavigation'
import {
  ChevronLeft,
  ChevronRight,
  GraduationCap,
  LogOut,
} from 'lucide-vue-next'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const sidebarCollapsed = ref(false)
const isMobileViewport = ref(false)
const mobileMediaQuery = '(max-width: 900px)'

const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())

const navItems = computed(() => getDashboardNavItems(normalizedRole.value))

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
  if (currentRouteName.value === name) {
    if (isMobileViewport.value) {
      sidebarCollapsed.value = true
    }
    return
  }
  router.push({ name })
  if (isMobileViewport.value) {
    sidebarCollapsed.value = true
  }
}

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}

function syncViewportState() {
  if (typeof window === 'undefined') return

  const mobile = window.matchMedia(mobileMediaQuery).matches
  const wasMobile = isMobileViewport.value

  isMobileViewport.value = mobile

  if (mobile && !wasMobile) {
    sidebarCollapsed.value = true
  } else if (!mobile && wasMobile) {
    sidebarCollapsed.value = false
  }
}

onMounted(() => {
  syncViewportState()
  window.addEventListener('resize', syncViewportState)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', syncViewportState)
})
</script>

<style scoped>
.layout-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: auto 1fr;
  background: var(--lnu-bg, #f4f6f8);
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

.layout-sidebar.collapsed .layout-sidebar-top {
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.layout-sidebar.collapsed .layout-toggle {
  width: 44px;
  height: 44px;
  border-radius: 12px;
}

.layout-sidebar.collapsed .layout-brand {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 12px;
}

.layout-sidebar.collapsed .layout-brand-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
}

.layout-sidebar.collapsed .layout-nav {
  justify-items: center;
  gap: 8px;
}

.layout-sidebar.collapsed .layout-nav-item {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 12px;
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
  transition: all 0.2s ease;
}

.layout-nav-item:hover:not(.active) {
  background: rgba(255, 255, 255, 0.05);
  color: #fff;
}

.layout-nav-item.active {
  background: rgba(255, 255, 255, 0.08);
  color: var(--lnu-gold-light);
  box-shadow: inset 4px 0 0 var(--lnu-gold-light);
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
  background: transparent;
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

  .layout-sidebar-top {
    justify-content: space-between;
    gap: 10px;
  }

  .layout-sidebar.collapsed {
    padding-bottom: 10px;
  }

  .layout-sidebar.collapsed .layout-sidebar-top {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }

  .layout-sidebar.collapsed .layout-toggle {
    width: 34px;
    height: 34px;
    border-radius: 9px;
  }

  .layout-sidebar.collapsed .layout-brand {
    width: auto;
    height: auto;
    padding: 4px;
    justify-content: flex-start;
    border-radius: 0;
  }

  .layout-sidebar.collapsed .layout-brand-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
  }

  .layout-sidebar.collapsed .layout-nav,
  .layout-sidebar.collapsed .layout-sidebar-footer {
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
