<script setup>
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/authStore'
import { usePosStore } from './stores/posStore'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const pos = usePosStore()

onMounted(async () => {
  await pos.refreshPendingCount()
  await pos.syncPendingOrders()
})

const handleLogout = async () => {
  await auth.logout()
  router.push('/login')
}

const toggleOfflineSim = () => {
  pos.simulateOffline = !pos.simulateOffline
  if (!pos.simulateOffline) pos.syncPendingOrders()
}
</script>

<template>
  <div class="app">
    <header v-if="auth.user && auth.token && route.path !== '/login'" class="topbar">
      <div class="brand">
        <span class="cup-icon">☕</span>
        <div>
          <div class="brand-title serif">Dwell Coffee</div>
          <div class="brand-sub">Ampayon • POS & Inventory</div>
        </div>
      </div>

      <nav class="nav">
        <router-link to="/pos">POS</router-link>
        <router-link to="/stock">Stock & Waste</router-link>
        <router-link to="/reports">Reports</router-link>
        <router-link v-if="auth.user.role === 'owner'" to="/equipment">Equipment</router-link>
        <router-link v-if="auth.user.role === 'owner'" to="/staff">Staff</router-link>
        <router-link v-if="auth.user.role === 'owner'" to="/recovery">Recovery Pack</router-link>
      </nav>

      <div class="status-bar">
        <span :class="['status-pill', pos.isOnline && !pos.simulateOffline ? 'online' : 'offline']">
          <span class="dot"></span>
          {{ pos.isOnline && !pos.simulateOffline ? 'Online' : 'Offline' }}
        </span>
        <span v-if="pos.pendingCount > 0" class="status-pill pending">
          Sync Queue: {{ pos.pendingCount }}
        </span>
        <span v-if="pos.syncError" class="status-pill sync-error" role="alert" :title="pos.syncError">
          Sync failed
        </span>
        <button class="nav-btn" @click="toggleOfflineSim">
          {{ pos.simulateOffline ? 'Reconnect' : 'Test Offline' }}
        </button>
        <button class="nav-btn logout" @click="handleLogout">
          {{ auth.user.name }} • Sign Out
        </button>
      </div>
    </header>

    <main class="container">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.topbar {
  background: var(--espresso);
  color: #fff;
  padding: 12px 24px;
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.14);
}
.brand {
  display: flex;
  align-items: center;
  gap: 10px;
}
.cup-icon {
  background: var(--caramel);
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  font-size: 18px;
}
.brand-title {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
  line-height: 1.1;
}
.brand-sub {
  font-size: 11px;
  color: #c8b8ac;
}
.nav {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.nav a {
  color: #dfd3c9;
  text-decoration: none;
  padding: 8px 13px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  transition: all 0.15s;
}
.nav a:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}
.nav a.router-link-active {
  background: var(--caramel);
  color: #fff;
}
.status-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}
.online {
  background: rgba(45, 106, 79, 0.28);
  color: #8ce0b5;
}
.offline {
  background: rgba(185, 56, 44, 0.32);
  color: #ffb4ad;
}
.pending {
  background: rgba(198, 125, 59, 0.32);
  color: #ffd6a5;
}
.sync-error {
  background: rgba(185, 56, 44, 0.32);
  color: #ffb4ad;
}
.nav-btn {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.16);
  padding: 6px 11px;
  font-size: 12px;
}
.nav-btn:hover {
  background: rgba(255, 255, 255, 0.2);
}
.container {
  max-width: 1220px;
  margin: 24px auto;
  padding: 0 20px 40px;
}
</style>