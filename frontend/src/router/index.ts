import { createRouter, createWebHistory, type RouterHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import FoundationView from '../views/FoundationView.vue'
import NotFoundView from '../views/NotFoundView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ResetPasswordView from '../views/ResetPasswordView.vue'
import VerificationRequiredView from '../views/VerificationRequiredView.vue'
import OrganizationsView from '../views/OrganizationsView.vue'
import OrganizationWorkspaceView from '../views/OrganizationWorkspaceView.vue'

export const routes = [
  { path: '/', name: 'foundation', component: FoundationView },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { guestOnly: true },
  },
  {
    path: '/register',
    name: 'register',
    component: RegisterView,
    meta: { guestOnly: true },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: ForgotPasswordView,
    meta: { guestOnly: true },
  },
  {
    path: '/reset-password/:token',
    name: 'reset-password',
    component: ResetPasswordView,
    meta: { guestOnly: true },
  },
  {
    path: '/verify-email',
    name: 'verify-email',
    component: VerificationRequiredView,
    meta: { requiresAuth: true },
  },
  {
    path: '/app',
    name: 'app',
    redirect: { name: 'organizations' },
    meta: { requiresAuth: true, requiresVerified: true },
  },
  {
    path: '/app/organizations',
    name: 'organizations',
    component: OrganizationsView,
    meta: { requiresAuth: true, requiresVerified: true },
  },
  {
    path: '/app/organizations/:organizationId',
    name: 'organization',
    component: OrganizationWorkspaceView,
    meta: { requiresAuth: true, requiresVerified: true },
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView },
]

export function createAppRouter(history: RouterHistory = createWebHistory()) {
  const router = createRouter({ history, routes })

  router.beforeEach(async (to) => {
    if (!to.meta.requiresAuth && !to.meta.guestOnly) return true

    const auth = useAuthStore()
    try {
      if (auth.initialized) await auth.refresh()
      else await auth.initialize()
    } catch {
      if (to.meta.guestOnly) return true
      return { name: 'login', query: { unavailable: '1' } }
    }

    if (!auth.user) {
      return to.meta.requiresAuth
        ? { name: 'login', query: { redirect: to.fullPath } }
        : true
    }
    if (to.meta.guestOnly)
      return { name: auth.user.email_verified ? 'app' : 'verify-email' }
    if (to.meta.requiresVerified && !auth.user.email_verified)
      return { name: 'verify-email' }
    if (to.name === 'verify-email' && auth.user.email_verified)
      return { name: 'app' }
    return true
  })

  return router
}

export const router = createAppRouter()
