import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import LoginView from '../views/LoginView.vue'
import PosOrderView from '../views/PosOrderView.vue'
import StockClosingView from '../views/StockClosingView.vue'
import ReportsView from '../views/ReportsView.vue'
import EquipmentView from '../views/EquipmentView.vue'
import StaffView from '../views/StaffView.vue'
import RecoveryPackView from '../views/RecoveryPackView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView },
    { path: '/', redirect: '/pos' },
    { path: '/pos', component: PosOrderView, meta: { requiresAuth: true } },
    { path: '/stock', component: StockClosingView, meta: { requiresAuth: true } },
    { path: '/reports', component: ReportsView, meta: { requiresAuth: true } },
    { path: '/equipment', component: EquipmentView, meta: { requiresAuth: true, ownerOnly: true } },
    { path: '/staff', component: StaffView, meta: { requiresAuth: true, ownerOnly: true } },
    { path: '/recovery', component: RecoveryPackView, meta: { requiresAuth: true, ownerOnly: true } },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.token) return '/login'
  if (to.meta.ownerOnly && auth.user?.role !== 'owner') return '/pos'
})

export default router