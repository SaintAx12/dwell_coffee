import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../api/axios'
import { db } from '../db/offlineDb'

export const usePosStore = defineStore('pos', () => {
  const isOnline = ref(navigator.onLine)
  const simulateOffline = ref(false)
  const pendingCount = ref(0)
  const syncError = ref('')
  const menu = ref([])
  const discountRates = ref(
    JSON.parse(localStorage.getItem('dwell_discounts') || '{"tumbler_discount":10,"senior_discount_pct":20,"pwd_discount_pct":20}')
  )

  const refreshPendingCount = async () => {
    pendingCount.value = await db.pendingOrders.count()
  }

  const loadDiscountRates = async () => {
    if (isOnline.value && !simulateOffline.value) {
      try {
        const { data } = await api.get('/settings/discounts')
        discountRates.value = data
        localStorage.setItem('dwell_discounts', JSON.stringify(data))
      } catch (_) {}
    }
  }

  const loadMenu = async () => {
    if (isOnline.value && !simulateOffline.value) {
      try {
        const { data } = await api.get('/menu-items')
        menu.value = data
        await db.cachedMenu.clear()
        await db.cachedMenu.bulkPut(JSON.parse(JSON.stringify(data)))
        return
      } catch (_) {}
    }
    menu.value = await db.cachedMenu.toArray()
  }

  const saveOrderOfflineFirst = async (orderPayload) => {
    const order = JSON.parse(
      JSON.stringify({
        ...orderPayload,
        order_id: crypto.randomUUID(),
        created_at: new Date().toISOString(),
      })
    )
    await db.pendingOrders.put(order)
    await refreshPendingCount()
    await syncPendingOrders()
    return order
  }

  const syncPendingOrders = async () => {
    if (!isOnline.value || simulateOffline.value) return
    const queued = await db.pendingOrders.toArray()
    if (queued.length === 0) {
      syncError.value = ''
      return
    }

    try {
      await api.post('/orders/sync', { orders: queued })
      await db.pendingOrders.bulkDelete(queued.map((o) => o.order_id))
      await refreshPendingCount()
      syncError.value = ''
    } catch (error) {
      syncError.value =
        error.response?.data?.message || error.message || 'Unable to sync pending orders.'
    }
  }

  window.addEventListener('online', () => {
    isOnline.value = true
    syncPendingOrders()
  })
  window.addEventListener('offline', () => {
    isOnline.value = false
  })
  setInterval(syncPendingOrders, 30000)

  return {
    isOnline, simulateOffline, pendingCount, syncError, menu, discountRates,
    refreshPendingCount, loadDiscountRates, loadMenu, saveOrderOfflineFirst, syncPendingOrders,
  }
})