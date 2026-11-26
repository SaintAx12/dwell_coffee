<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/authStore'

const auth = useAuthStore()
const isOwner = computed(() => auth.user?.role === 'owner')

const toLocalYMD = (d = new Date()) => {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const flash = ref({ text: '', type: 'ok' })
const setFlash = (text, type = 'ok') => {
  flash.value = { text, type }
  setTimeout(() => { flash.value.text = '' }, 3200)
}

const daily = ref(null)
const analytics = ref(null)
const auditLogs = ref([])

const paymentFilter = ref('all')
const itemSearch = ref('')

const activePeriod = ref('day')
const fromDate = ref(toLocalYMD())
const toDate = ref(toLocalYMD())

const voidModal = ref({ open: false, orderId: '', reason: 'Wrong order entered' })

const periodLabels = {
  day: 'Day (Today)',
  week: 'Week (This Week)',
  month: 'Month (This Month)',
  year: 'Year (This Year)',
  custom: 'Custom Range',
}

const cashPct = computed(() => {
  if (!daily.value) return 50
  const total = Number(daily.value.cash_sales) + Number(daily.value.gcash_sales)
  return total > 0 ? Math.round((Number(daily.value.cash_sales) / total) * 100) : 50
})

const maxTopQty = computed(() => {
  if (!analytics.value?.top_items?.length) return 1
  return Math.max(...analytics.value.top_items.map((t) => Number(t.total_qty)), 1)
})

const activeOrdersSource = computed(() => {
  if (isOwner.value && analytics.value?.orders) {
    return analytics.value.orders
  }
  return daily.value?.recent_orders || []
})

const filteredOrders = computed(() => {
  const orders = activeOrdersSource.value
  const q = itemSearch.value.trim().toLowerCase()

  return orders.filter((o) => {
    const matchesPay =
      paymentFilter.value === 'all' ||
      (o.payment_method || '').toLowerCase() === paymentFilter.value

    if (!matchesPay) return false
    if (!q) return true

    const idMatch = (o.order_id || '').toLowerCase().includes(q)
    const itemMatch = (o.items || []).some((it) =>
      (it.item_name || it.name || '').toLowerCase().includes(q)
    )
    return idMatch || itemMatch
  })
})

const formatOrderDateTime = (order) => {
  const raw = order.created_at || order.ordered_at
  if (!raw) return '—'
  const d = new Date(raw)
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (!isOwner.value || activePeriod.value === 'day') return time
  const date = d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
  return `${date} • ${time}`
}

const getOrderItems = (order) => order.items || []

const getOrderTotalQty = (order) =>
  getOrderItems(order).reduce((sum, it) => sum + Number(it.quantity ?? it.qty ?? 1), 0)

const selectPeriod = (period) => {
  if (!isOwner.value) return
  activePeriod.value = period
  const now = new Date()
  toDate.value = toLocalYMD(now)

  if (period === 'day') {
    fromDate.value = toDate.value
  } else if (period === 'week') {
    const dayOfWeek = now.getDay()
    const diffToMonday = now.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1)
    const monday = new Date(now.getFullYear(), now.getMonth(), diffToMonday)
    fromDate.value = toLocalYMD(monday)
  } else if (period === 'month') {
    const firstOfMonth = new Date(now.getFullYear(), now.getMonth(), 1)
    fromDate.value = toLocalYMD(firstOfMonth)
  } else if (period === 'year') {
    const firstOfYear = new Date(now.getFullYear(), 0, 1)
    fromDate.value = toLocalYMD(firstOfYear)
  }

  loadReports()
}

const loadReports = async () => {
  daily.value = (await api.get('/reports/daily')).data
  if (isOwner.value) {
    analytics.value = (await api.get(`/reports/analytics?from=${fromDate.value}&to=${toDate.value}`)).data
    auditLogs.value = (await api.get('/audit-logs')).data
  }
}

const openVoidModal = (orderId) => {
  voidModal.value = { open: true, orderId, reason: 'Wrong order entered' }
}

const confirmVoid = async () => {
  if (!voidModal.value.reason.trim()) return setFlash('Void reason is required.', 'warn')
  await api.patch(`/orders/${voidModal.value.orderId}/void`, { void_reason: voidModal.value.reason })
  voidModal.value.open = false
  setFlash('Order voided and logged to audit trail.', 'warn')
  loadReports()
}

const exportOrdersCsv = () => {
  const rows = [['Date & Time', 'Order ID', 'Items', 'Total Qty', 'Amount', 'Payment Method', 'Status']]
  filteredOrders.value.forEach((o) => {
    const itemsText = getOrderItems(o)
      .map((it) => `${it.quantity ?? it.qty ?? 1}x ${it.item_name || it.name}`)
      .join(' | ')
    rows.push([
      `"${formatOrderDateTime(o)}"`,
      o.order_id.slice(0, 8).toUpperCase(),
      `"${itemsText}"`,
      getOrderTotalQty(o),
      Number(o.total).toFixed(2),
      (o.payment_method || '').toUpperCase(),
      o.void_reason ? `VOID (${o.void_reason})` : 'VALID',
    ])
  })
  const csv = rows.map((r) => r.join(',')).join('\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
  const rangeTag = isOwner.value ? `${activePeriod.value}_${fromDate.value}_to_${toDate.value}` : toLocalYMD()
  a.download = `dwell_coffee_orders_${rangeTag}.csv`
  a.click()
}

onMounted(loadReports)
</script>

<template>
  <div class="reports-stack">
    <div v-if="flash.text" :class="['flash-banner', flash.type]">{{ flash.text }}</div>

    <!-- Top Summary Row -->
    <div :class="isOwner ? 'grid-2' : 'grid-1'">
      <!-- Daily Report Card (Cashier & Owner) -->
      <div v-if="daily" class="card">
        <div class="head-flex">
          <div>
            <h3>Today's Daily Report</h3>
            <p class="muted" style="margin: 0">
              {{ new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) }}
            </p>
          </div>
          <span v-if="!isOwner" class="pill-badge pill-ok">DAILY ONLY</span>
        </div>

        <div class="kpi-grid num">
          <div class="kpi"><small>Today's Sales</small><strong>₱{{ Number(daily.total_sales).toFixed(2) }}</strong></div>
          <div class="kpi"><small>Valid Orders</small><strong>{{ daily.order_count }}</strong></div>
          <div class="kpi"><small>Cash / GCash</small><strong>₱{{ Number(daily.cash_sales).toFixed(0) }} / ₱{{ Number(daily.gcash_sales).toFixed(0) }}</strong></div>
          <div class="kpi eco"><small>Cups Avoided</small><strong>🌱 {{ daily.tumbler_count }}</strong></div>
        </div>

        <div class="split-box num">
          <div class="split-labels">
            <span>💵 Cash ({{ cashPct }}%)</span>
            <span>📱 GCash ({{ 100 - cashPct }}%)</span>
          </div>
          <div class="split-bar">
            <div class="split-cash" :style="{ width: cashPct + '%' }"></div>
          </div>
        </div>
      </div>

      <!-- Owner Period Analytics Card (Day / Week / Month / Year) -->
      <div v-if="isOwner && analytics" class="card">
        <div class="head-flex">
          <div>
            <h3>Owner Period Report</h3>
            <p class="muted num" style="margin: 0">
              {{ periodLabels[activePeriod] }} • {{ fromDate }} to {{ toDate }}
            </p>
          </div>

          <div class="period-seg">
            <button :class="{ active: activePeriod === 'day' }" @click="selectPeriod('day')">Day</button>
            <button :class="{ active: activePeriod === 'week' }" @click="selectPeriod('week')">Week</button>
            <button :class="{ active: activePeriod === 'month' }" @click="selectPeriod('month')">Month</button>
            <button :class="{ active: activePeriod === 'year' }" @click="selectPeriod('year')">Year</button>
          </div>
        </div>

        <div class="row-gap" style="margin-top: 12px">
          <input v-model="fromDate" type="date" @change="activePeriod = 'custom'" />
          <span class="muted">to</span>
          <input v-model="toDate" type="date" @change="activePeriod = 'custom'" />
          <button class="btn-primary" @click="loadReports">Apply</button>
        </div>

        <div class="kpi-grid num">
          <div class="kpi">
            <small>Period Revenue ({{ analytics.order_count }} orders)</small>
            <strong>₱{{ Number(analytics.period_sales).toFixed(2) }}</strong>
          </div>
          <div class="kpi eco">
            <small>Tumbler Savings</small>
            <strong>₱{{ Number(analytics.tumbler_savings).toFixed(2) }}</strong>
          </div>
          <div class="kpi eco">
            <small>Cups Avoided</small>
            <strong>{{ analytics.cups_avoided }} cups</strong>
          </div>
          <div class="kpi warn">
            <small>Waste Cost Loss</small>
            <strong>₱{{ Number(analytics.total_waste_cost).toFixed(2) }}</strong>
          </div>
        </div>

        <h4>Top-Selling Drinks & Pastries</h4>
        <div v-if="!analytics.top_items?.length" class="empty-box">
          No item sales recorded in this period.
        </div>
        <div v-for="t in analytics.top_items" :key="t.item_name" class="bar-item num">
          <div class="bar-head">
            <span>{{ t.item_name }}</span>
            <strong>{{ t.total_qty }} sold • ₱{{ Number(t.revenue).toFixed(2) }}</strong>
          </div>
          <div class="bar-track">
            <div class="bar-fill" :style="{ width: (Number(t.total_qty) / maxTopQty) * 100 + '%' }"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Detailed Order Log Table -->
    <div v-if="daily" class="card">
      <div class="head-flex">
        <div>
          <h3>{{ isOwner ? `Order Log — ${periodLabels[activePeriod]}` : "Today's Order Log" }}</h3>
          <p class="muted num" style="margin: 0">
            {{ isOwner ? `${fromDate} to ${toDate}` : "Showing today's itemized orders only" }}
          </p>
        </div>

        <div class="row-gap">
          <input
            v-model="itemSearch"
            type="text"
            placeholder="Search drink or #ID..."
            style="width: 200px"
          />
          <button :class="['preset-btn', { active: paymentFilter === 'all' }]" @click="paymentFilter = 'all'">
            All ({{ activeOrdersSource.length }})
          </button>
          <button :class="['preset-btn', { active: paymentFilter === 'cash' }]" @click="paymentFilter = 'cash'">
            💵 Cash
          </button>
          <button :class="['preset-btn', { active: paymentFilter === 'gcash' }]" @click="paymentFilter = 'gcash'">
            📱 GCash
          </button>
          <button v-if="filteredOrders.length" @click="exportOrdersCsv">Export CSV</button>
        </div>
      </div>

      <div v-if="!filteredOrders.length" class="empty-box" style="margin-top: 14px">
        No orders found for this period.
      </div>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ isOwner && activePeriod !== 'day' ? 'Date & Time' : 'Time' }}</th>
              <th>Order Item(s)</th>
              <th>Qty</th>
              <th>Amount</th>
              <th>Payment Method</th>
              <th style="text-align: right">Status / Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in filteredOrders" :key="o.order_id" :class="['num', { 'voided-row': o.void_reason }]">
              <td class="time-cell">
                <strong>{{ formatOrderDateTime(o) }}</strong>
                <div class="muted" style="font-size: 11px">#{{ o.order_id.slice(0, 8).toUpperCase() }}</div>
              </td>

              <td>
                <div v-if="getOrderItems(o).length" class="item-stack">
                  <div v-for="(it, idx) in getOrderItems(o)" :key="idx" class="item-line">
                    {{ it.item_name || it.name }}
                  </div>
                </div>
                <span v-else class="muted">Order #{{ o.order_id.slice(0, 8).toUpperCase() }}</span>
              </td>

              <td>
                <div v-if="getOrderItems(o).length" class="item-stack">
                  <div v-for="(it, idx) in getOrderItems(o)" :key="idx" class="qty-line">
                    <strong>{{ it.quantity ?? it.qty ?? 1 }}×</strong>
                  </div>
                </div>
                <span v-else>—</span>
              </td>

              <td>
                <strong :class="{ strike: o.void_reason }">₱{{ Number(o.total).toFixed(2) }}</strong>
                <div v-if="Number(o.discount_amount) > 0" class="muted" style="font-size: 11px">
                  {{ (o.discount_type || '').toUpperCase() }} (−₱{{ Number(o.discount_amount).toFixed(2) }})
                </div>
              </td>

              <td>
                <span :class="['pill-badge', (o.payment_method || '').toLowerCase() === 'gcash' ? 'pill-gcash' : 'pill-ok']">
                  {{ (o.payment_method || '').toLowerCase() === 'gcash' ? '📱 GCASH' : '💵 CASH' }}
                </span>
              </td>

              <td style="text-align: right">
                <span v-if="o.void_reason" class="pill-badge pill-err">VOID: {{ o.void_reason }}</span>
                <button v-else class="btn-danger" @click="openVoidModal(o.order_id)">Void</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>


    <!-- Void Order Modal -->
    <div v-if="voidModal.open" class="modal-backdrop" @click.self="voidModal.open = false">
      <div class="modal-card">
        <h3>Void Order #{{ voidModal.orderId.slice(0, 8).toUpperCase() }}</h3>
        <p class="muted">Select or type a reason. This action is recorded in the audit log.</p>
        <div class="row-gap" style="margin-bottom: 10px">
          <button type="button" @click="voidModal.reason = 'Wrong order entered'">Wrong order</button>
          <button type="button" @click="voidModal.reason = 'Customer cancelled'">Cancelled</button>
          <button type="button" @click="voidModal.reason = 'Drink spilled / remade'">Spilled</button>
        </div>
        <input v-model="voidModal.reason" placeholder="Reason for voiding" style="width: 100%; margin-bottom: 14px" />
        <div class="row-gap" style="justify-content: flex-end">
          <button @click="voidModal.open = false">Cancel</button>
          <button class="btn-danger" @click="confirmVoid">Confirm Void</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.reports-stack { display: flex; flex-direction: column; gap: 22px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1.15fr; gap: 22px; align-items: start; }
.grid-1 { display: grid; grid-template-columns: 1fr; }
@media (max-width: 880px) { .grid-2 { grid-template-columns: 1fr; } }

.head-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }

.period-seg {
  display: inline-flex;
  background: var(--crema);
  border: 1px solid var(--border);
  border-radius: 11px;
  padding: 3px;
  gap: 3px;
}
.period-seg button {
  border: none;
  background: transparent;
  padding: 6px 13px;
  min-height: auto;
  border-radius: 8px;
  font-size: 12.5px;
  color: var(--muted);
}
.period-seg button.active {
  background: var(--espresso);
  color: #fff;
}

.preset-btn { border-radius: 999px; padding: 5px 12px; min-height: auto; font-size: 12px; background: var(--crema); }
.preset-btn.active { background: var(--espresso); color: #fff; border-color: var(--espresso); }

.kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 14px 0 16px; }
.kpi { background: var(--crema); border: 1px solid var(--border); padding: 14px; border-radius: 12px; display: flex; flex-direction: column; }
.kpi small { color: var(--muted); font-size: 12px; }
.kpi strong { font-size: 20px; color: var(--espresso); margin-top: 4px; }
.kpi.eco { background: var(--matcha-bg); border-color: #c3e6d1; }
.kpi.eco strong { color: var(--matcha); }
.kpi.warn { background: var(--amber-bg); border-color: #fde68a; }

.split-box { background: var(--crema); padding: 12px; border-radius: 12px; border: 1px solid var(--border); }
.split-labels { display: flex; justify-content: space-between; font-size: 12px; font-weight: 700; margin-bottom: 6px; }
.split-bar { height: 10px; border-radius: 999px; background: var(--caramel); overflow: hidden; }
.split-cash { height: 100%; background: var(--espresso); transition: width 0.3s ease; }

.table-wrap { margin-top: 14px; overflow-x: auto; }
.time-cell { white-space: nowrap; }
.item-stack { display: flex; flex-direction: column; gap: 3px; }
.item-line { font-weight: 600; color: var(--espresso); }
.qty-line { color: var(--roast); }

.pill-gcash { background: #e0f2fe; color: #0369a1; }

.empty-box { padding: 20px 12px; text-align: center; background: var(--crema); border-radius: 10px; color: var(--muted); font-size: 13px; }
.bar-item { margin-bottom: 11px; }
.bar-head { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px; }
.bar-track { height: 8px; background: var(--crema); border-radius: 999px; overflow: hidden; }
.bar-fill { height: 100%; background: var(--caramel); border-radius: 999px; }

.list-line { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.voided-row { opacity: 0.68; background: #fdf8f7; }
.strike { text-decoration: line-through; color: var(--muted); }
.audit-row { padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
</style>