import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/auth/LoginView.vue'
import RegisterView from '../views/auth/RegisterView.vue'
import ForgotPasswordView from '../views/auth/ForgotPasswordView.vue'
import DashboardLayout from '../views/dashboard/DashboardLayout.vue'
import { useAuthStore } from '../store/auth.store'
import {
  dashboardAllowedRouteNames,
  dashboardDefaultRouteName,
  getDashboardModuleMeta,
  normalizeDashboardRole,
} from '../views/dashboard/config/dashboardNavigation'

const DashboardHomeView = () => import('../views/dashboard/student/DashboardHomeView.vue')
const RoomsView = () => import('../views/dashboard/student/RoomsView.vue')
const AnalyticsView = () => import('../views/dashboard/student/AnalyticsView.vue')
const RoomManagementView = () => import('../views/dashboard/shared/RoomManagementView.vue')
const LibraryView = () => import('../views/dashboard/staff/LibraryView.vue')
const ExamsView = () => import('../views/dashboard/staff/ExamsView.vue')
const ReportsView = () => import('../views/dashboard/staff/ReportsView.vue')
const SettingsView = () => import('../views/dashboard/shared/SettingsView.vue')
const UsersView = () => import('../views/dashboard/admin/UsersView.vue')
const AuditView = () => import('../views/dashboard/admin/AuditView.vue')

function dashboardRouteMeta(key) {
  const moduleMeta = getDashboardModuleMeta(key)

  return {
    title: moduleMeta.title,
    sub: moduleMeta.sub,
  }
}

const routes = [
  { path: '/', redirect: '/login' },
  { path: '/login', name: 'login', component: LoginView },
  { path: '/forgot-password', name: 'forgot-password', component: ForgotPasswordView },
  { path: '/register', name: 'register', component: RegisterView },
  {
    path: '/dashboard',
    component: DashboardLayout,
    children: [
      { path: '', redirect: { name: 'dashboard-home' } },
      {
        path: 'home',
        name: 'dashboard-home',
        component: DashboardHomeView,
        meta: dashboardRouteMeta('dashboard'),
      },
      {
        path: 'rooms',
        name: 'dashboard-rooms',
        component: RoomsView,
        meta: dashboardRouteMeta('rooms'),
      },
      {
        path: 'analytics',
        name: 'dashboard-analytics',
        component: AnalyticsView,
        meta: dashboardRouteMeta('analytics'),
      },
      {
        path: 'room-management',
        name: 'dashboard-room-management',
        component: RoomManagementView,
        meta: dashboardRouteMeta('room'),
      },
      {
        path: 'library',
        name: 'dashboard-library',
        component: LibraryView,
        meta: dashboardRouteMeta('library'),
      },
      {
        path: 'exams',
        name: 'dashboard-exams',
        component: ExamsView,
        meta: dashboardRouteMeta('exams'),
      },
      {
        path: 'reports',
        name: 'dashboard-reports',
        component: ReportsView,
        meta: dashboardRouteMeta('reports'),
      },
      {
        path: 'settings',
        name: 'dashboard-settings',
        component: SettingsView,
        meta: dashboardRouteMeta('settings'),
      },
      {
        path: 'users',
        name: 'dashboard-users',
        component: UsersView,
        meta: dashboardRouteMeta('users'),
      },
      {
        path: 'audit',
        name: 'dashboard-audit',
        component: AuditView,
        meta: dashboardRouteMeta('audit'),
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

function normalizedRole(role) {
  return normalizeDashboardRole(role)
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const isPublic = ['login', 'register', 'forgot-password'].includes(String(to.name))

  if (!auth.initialized) {
    await auth.fetchMe({ silent: true })
  }

  if (!isPublic && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  const role = normalizedRole(auth.user?.role)

  if (isPublic && auth.isAuthenticated) {
    return { name: dashboardDefaultRouteName(role) }
  }

  const routeName = String(to.name ?? '')
  const isDashboardRoute = routeName.startsWith('dashboard-')

  if (isDashboardRoute) {
    const allowedRoutes = dashboardAllowedRouteNames(role)

    if (!allowedRoutes.includes(routeName)) {
      return { name: dashboardDefaultRouteName(role) }
    }
  }
})

export default router
