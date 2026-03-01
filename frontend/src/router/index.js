import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/auth/LoginView.vue'
import RegisterView from '../views/auth/RegisterView.vue'
import ForgotPasswordView from '../views/auth/ForgotPasswordView.vue'
import DashboardLayout from '../views/dashboard/DashboardLayout.vue'
import DashboardModuleView from '../views/dashboard/DashboardModuleView.vue'
import { useAuthStore } from '../store/auth.store'

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
        component: DashboardModuleView,
        props: { forcedNav: 'dashboard', embedded: true },
        meta: { title: 'Dashboard', sub: 'Your LLE review performance at a glance' },
      },
      {
        path: 'rooms',
        name: 'dashboard-rooms',
        component: DashboardModuleView,
        props: { forcedNav: 'rooms', embedded: true },
        meta: { title: 'Rooms', sub: 'Join and track your assigned room memberships' },
      },
      {
        path: 'analytics',
        name: 'dashboard-analytics',
        component: DashboardModuleView,
        props: { forcedNav: 'analytics', embedded: true },
        meta: { title: 'Analytics', sub: 'Monitor trends and identify weak areas quickly' },
      },
      {
        path: 'room-management',
        name: 'dashboard-room-management',
        component: DashboardModuleView,
        props: { forcedNav: 'room', embedded: true },
        meta: { title: 'Rooms', sub: 'Create rooms, review enrollment, and track assigned exams' },
      },
      {
        path: 'library',
        name: 'dashboard-library',
        component: DashboardModuleView,
        props: { forcedNav: 'library', embedded: true },
        meta: { title: 'Library', sub: 'Manage exam content and question pools' },
      },
      {
        path: 'exams',
        name: 'dashboard-exams',
        component: DashboardModuleView,
        props: { forcedNav: 'exams', embedded: true },
        meta: { title: 'Exams', sub: 'Configure exam structures and schedules' },
      },
      {
        path: 'reports',
        name: 'dashboard-reports',
        component: DashboardModuleView,
        props: { forcedNav: 'reports', embedded: true },
        meta: { title: 'Reports', sub: 'Review aggregate and student-level insights' },
      },
      {
        path: 'settings',
        name: 'dashboard-settings',
        component: DashboardModuleView,
        props: { forcedNav: 'settings', embedded: true },
        meta: { title: 'Settings', sub: 'Manage preferences and account behavior' },
      },
      {
        path: 'users',
        name: 'dashboard-users',
        component: DashboardModuleView,
        props: { forcedNav: 'users', embedded: true },
        meta: { title: 'Users', sub: 'Create accounts, assign roles, and manage account status' },
      },
      {
        path: 'audit',
        name: 'dashboard-audit',
        component: DashboardModuleView,
        props: { forcedNav: 'audit', embedded: true },
        meta: { title: 'Audit Logs', sub: 'Track key system actions and account activity' },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const isPublic = ['login', 'register', 'forgot-password'].includes(String(to.name))

  if (!auth.initialized) {
    await auth.fetchMe({ silent: true })
  }

  if (!isPublic && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (isPublic && auth.isAuthenticated) {
    return { name: 'dashboard-home' }
  }
})

export default router
