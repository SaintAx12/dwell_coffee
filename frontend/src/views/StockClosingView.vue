<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/authStore'

const auth = useAuthStore()

const flash = ref({ text: '', type: 'ok' })
const setFlash = (text, type = 'ok') => {
  flash.value = { text, type }
  setTimeout(() => { flash.value.text = '' }, 3600)
}

const addDaysLocalYMD = (days) => {
  const d = new Date()
  d.setDate(d.getDate() + Number(days))
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

// Top-level page section: 'stock' | 'recipes'
const activeSection = ref('stock')

const stock = ref([])
const wasteLogs = ref([])
const movements = ref([])
const menuItems = ref([])

const showAllWaste = ref(false)
const showAllMovements = ref(false)
const movementFilter = ref('all')

const searchQuery = ref('')
const menuSearchQuery = ref('')
const filterMode = ref('all')
const ownerPanel = ref('none')
const dirtyRows = ref({})
const wasteCardRef = ref(null)

// Inline per-item expiry & lifespan editor (Owner only)
const editingItemId = ref(null)
const editForm = ref({
  expiry_date: '',
  expiry_warning_days: 2,
  shelf_life_days: '',
})

const newStock = ref({
  name: '',
  unit: 'pcs',
  quantity: 10,
  min_level: 5,
  unit_cost: 50,
  expiry_date: '',
  expiry_warning_days: 2,
  shelf_life_days: '',
})

const stockIn = ref({ stock_item_id: null, quantity: 5, unit_cost: '', expiry_date: '' })
const newWaste = ref({ stock_item_id: null, item_name: '', quantity: 1, reason: 'spoiled' })

// Menu & Recipe Builder state (Owner only)
const showRecipeForm = ref(false)
const editingMenuId = ref(null)
const menuForm = ref({
  name: '',
  category: 'Coffee',
  price: 120,
  is_available: true,
  recipes: [],
})

const visibleWasteLogs = computed(() => {
  return showAllWaste.value ? wasteLogs.value : wasteLogs.value.slice(0, 5)
})

const filteredMovements = computed(() => {
  if (movementFilter.value === 'all') return movements.value
  return movements.value.filter((m) => m.reason === movementFilter.value)
})

const visibleMovements = computed(() => {
  return showAllMovements.value
    ? filteredMovements.value
    : filteredMovements.value.slice(0, 6)
})

const filteredMenu = computed(() => {
  const q = menuSearchQuery.value.trim().toLowerCase()
  if (!q) return menuItems.value
  return menuItems.value.filter(
    (m) => m.name.toLowerCase().includes(q) || (m.category || '').toLowerCase().includes(q)
  )
})

const reasonMeta = (reason) => {
  const map = {
    order_sale:    { label: 'ORDER SALE', cls: 'pill-err' },
    order_void:    { label: 'VOID / REFUND', cls: 'pill-ok' },
    stock_in:      { label: 'RESTOCK', cls: 'pill-ok' },
    initial_stock: { label: 'INITIAL STOCK', cls: 'pill-ok' },
    waste:         { label: 'WASTE', cls: 'pill-warn' },
    closing_count: { label: 'COUNT ADJUST', cls: 'pill-neutral' },
  }
  return map[reason] || { label: (reason || 'ADJUST').toUpperCase(), cls: 'pill-neutral' }
}

const getRawExpiryDate = (item) => {
  let rawDate = item.earliest_expiry || item.expiry_date || null
  if (Array.isArray(item.batches) && item.batches.length) {
    const activeDates = item.batches
      .filter((b) => Number(b.remaining_qty ?? b.quantity ?? 0) > 0 && b.expiry_date)
      .map((b) => b.expiry_date.slice(0, 10))
      .sort()
    rawDate = activeDates.length ? activeDates[0] : (item.expiry_date || null)
  }
  return rawDate ? rawDate.slice(0, 10) : ''
}

const getExpiryInfo = (item) => {
  if (Number(item.quantity) <= 0) {
    return { status: 'none', label: '', dateText: '', urgent: false }
  }

  const cleanDate = getRawExpiryDate(item)
  const warnDays = Number(item.expiry_warning_days ?? 2)

  if (!cleanDate) {
    return item.is_expiring
      ? { status: 'soon', label: `Exp ≤${warnDays}d`, dateText: '', urgent: true }
      : { status: 'none', label: '', dateText: '', urgent: false }
  }

  const expDate = new Date(`${cleanDate}T00:00:00`)
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const diffDays = Math.round((expDate - today) / 86400000)

  const dateText = expDate.toLocaleDateString([], { month: 'short', day: 'numeric' })

  if (diffDays < 0) {
    return {
      status: 'expired',
      label: `Expired ${Math.abs(diffDays)}d ago`,
      dateText,
      urgent: true,
    }
  }
  if (diffDays === 0) {
    return {
      status: 'today',
      label: 'Expires Today',
      dateText,
      urgent: true,
    }
  }
  if (diffDays <= warnDays) {
    return {
      status: 'soon',
      label: `Exp in ${diffDays}d (${dateText})`,
      dateText,
      urgent: true,
    }
  }
  return {
    status: 'ok',
    label: `Exp ${dateText}`,
    dateText,
    urgent: false,
  }
}

const isItemUrgentExpiry = (item) => Number(item.quantity) > 0 && getExpiryInfo(item).urgent

const lowCount = computed(() => stock.value.filter((s) => s.is_low).length)
const expCount = computed(() => stock.value.filter((s) => isItemUrgentExpiry(s)).length)

const filteredStock = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return stock.value.filter((s) => {
    if (q && !s.name.toLowerCase().includes(q)) return false
    if (filterMode.value === 'low') return s.is_low
    if (filterMode.value === 'expiring') return isItemUrgentExpiry(s)
    return true
  })
})

const applyDefaultStockInExpiry = (itemId) => {
  const found = stock.value.find((s) => s.id === Number(itemId))
  if (found && found.shelf_life_days) {
    stockIn.value.expiry_date = addDaysLocalYMD(found.shelf_life_days)
  } else {
    stockIn.value.expiry_date = ''
  }
}

const loadData = async () => {
  const [stockRes, wasteRes, moveRes, menuRes] = await Promise.all([
    api.get('/stock-items'),
    api.get('/waste-logs'),
    api.get('/stock-movements'),
    api.get('/menu-items'),
  ])
  stock.value = stockRes.data
  wasteLogs.value = wasteRes.data
  movements.value = moveRes.data
  menuItems.value = menuRes.data
  dirtyRows.value = {}

  if (stock.value.length) {
    if (!newWaste.value.item_name) {
      newWaste.value.stock_item_id = stock.value[0].id
      newWaste.value.item_name = stock.value[0].name
    }
    if (!stockIn.value.stock_item_id) {
      stockIn.value.stock_item_id = stock.value[0].id
      applyDefaultStockInExpiry(stock.value[0].id)
    }
  }
}

const startEditLifespan = (item) => {
  editingItemId.value = item.id
  editForm.value = {
    expiry_date: getRawExpiryDate(item),
    expiry_warning_days: Number(item.expiry_warning_days ?? 2),
    shelf_life_days: item.shelf_life_days ?? '',
  }
}

const saveItemLifespan = async (item) => {
  try {
    await api.patch(`/stock-items/${item.id}/expiry`, {
      expiry_date: editForm.value.expiry_date || null,
      expiry_warning_days: Number(editForm.value.expiry_warning_days ?? 2),
      shelf_life_days: editForm.value.shelf_life_days === '' ? null : Number(editForm.value.shelf_life_days),
    })
    editingItemId.value = null
    setFlash(`Updated expiry & lifespan settings for ${item.name}.`, 'ok')
    await loadData()
  } catch (e) {
    setFlash(e.response?.data?.message || 'Failed to update expiry lifespan.', 'err')
  }
}

const markDirty = (id) => {
  dirtyRows.value[id] = true
}

const adjustCount = (item, delta) => {
  const current = Number(item.quantity) || 0
  item.quantity = Math.max(0, Number((current + delta).toFixed(2)))
  markDirty(item.id)
}

const saveCount = async (item) => {
  if (item.quantity === '' || item.quantity < 0 || isNaN(item.quantity)) {
    return setFlash('Count must be 0 or higher.', 'err')
  }
  await api.patch(`/stock-items/${item.id}/count`, { quantity: Number(item.quantity) })
  delete dirtyRows.value[item.id]
  setFlash(`Saved closing count for ${item.name}.`, 'ok')
  loadData()
}

const prepareExpiredWaste = (item) => {
  newWaste.value = {
    stock_item_id: item.id,
    item_name: item.name,
    quantity: Math.max(1, Number(item.quantity) || 1),
    reason: 'expired',
  }
  setFlash(`Pre-filled Waste Log for expired "${item.name}". Confirm quantity and click Log Waste.`, 'warn')
  wasteCardRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

const addStock = async () => {
  if (!newStock.value.name) return setFlash('Enter an item name.', 'warn')
  await api.post('/stock-items', {
    ...newStock.value,
    expiry_warning_days: Number(newStock.value.expiry_warning_days ?? 2),
    shelf_life_days: newStock.value.shelf_life_days === '' ? null : Number(newStock.value.shelf_life_days),
  })
  newStock.value = {
    name: '',
    unit: 'pcs',
    quantity: 10,
    min_level: 5,
    unit_cost: 50,
    expiry_date: '',
    expiry_warning_days: 2,
    shelf_life_days: '',
  }
  ownerPanel.value = 'none'
  setFlash('New inventory item created.', 'ok')
  loadData()
}

const recordStockIn = async () => {
  if (!stockIn.value.stock_item_id || stockIn.value.quantity <= 0) return
  await api.post(`/stock-items/${stockIn.value.stock_item_id}/stock-in`, {
    quantity: stockIn.value.quantity,
    unit_cost: stockIn.value.unit_cost === '' ? null : Number(stockIn.value.unit_cost),
    expiry_date: stockIn.value.expiry_date || null,
  })
  stockIn.value.quantity = 5
  applyDefaultStockInExpiry(stockIn.value.stock_item_id)
  ownerPanel.value = 'none'
  setFlash('Stock-in batch recorded (FIFO).', 'ok')
  loadData()
}

const selectWasteItem = (e) => {
  const found = stock.value.find((s) => s.id === Number(e.target.value))
  if (found) {
    newWaste.value.stock_item_id = found.id
    newWaste.value.item_name = found.name
  }
}

const logWaste = async () => {
  if (!newWaste.value.stock_item_id || newWaste.value.quantity <= 0) {
    return setFlash('Select an item and enter a valid quantity.', 'warn')
  }
  await api.post('/waste-logs', newWaste.value)
  newWaste.value.quantity = 1
  setFlash('Waste logged & FIFO batch updated.', 'ok')
  await loadData()
}

// Menu & Recipe Builder Methods
const openNewMenuForm = () => {
  editingMenuId.value = null
  menuForm.value = {
    name: '',
    category: 'Coffee',
    price: 120,
    is_available: true,
    recipes: stock.value.length
      ? [{ stock_item_id: stock.value[0].id, quantity_used: 1 }]
      : [],
  }
  showRecipeForm.value = true
}

const openEditMenuForm = (item) => {
  editingMenuId.value = item.id
  menuForm.value = {
    name: item.name,
    category: item.category || 'Coffee',
    price: Number(item.price),
    is_available: item.is_available !== false && item.active !== false,
    recipes: Array.isArray(item.recipes)
      ? item.recipes.map((r) => ({
          stock_item_id: r.stock_item_id,
          quantity_used: Number(r.quantity_used),
        }))
      : [],
  }
  showRecipeForm.value = true
}

const addRecipeRow = () => {
  if (!stock.value.length) {
    return setFlash('Create stock ingredients first on the Stock & Movements tab.', 'warn')
  }
  const usedIds = new Set(menuForm.value.recipes.map((r) => Number(r.stock_item_id)))
  const nextStock = stock.value.find((s) => !usedIds.has(s.id)) || stock.value[0]
  menuForm.value.recipes.push({
    stock_item_id: nextStock.id,
    quantity_used: 1,
  })
}

const removeRecipeRow = (idx) => {
  menuForm.value.recipes.splice(idx, 1)
}

const getStockUnit = (stockId) => {
  return stock.value.find((s) => s.id === Number(stockId))?.unit || ''
}

const saveMenuItem = async () => {
  if (!menuForm.value.name.trim()) {
    return setFlash('Enter a menu item name.', 'warn')
  }

  const cleanRecipes = menuForm.value.recipes.filter(
    (r) => r.stock_item_id && Number(r.quantity_used) > 0
  )

  try {
    if (editingMenuId.value) {
      await api.put(`/menu-items/${editingMenuId.value}`, {
        ...menuForm.value,
        recipes: cleanRecipes,
      })
      setFlash(`Updated "${menuForm.value.name}" and its ingredient recipe.`, 'ok')
    } else {
      await api.post('/menu-items', {
        ...menuForm.value,
        recipes: cleanRecipes,
      })
      setFlash(`Created "${menuForm.value.name}" with ${cleanRecipes.length} recipe ingredient(s).`, 'ok')
    }
    showRecipeForm.value = false
    await loadData()
  } catch (e) {
    setFlash(e.response?.data?.message || 'Failed to save menu item.', 'err')
  }
}

const toggleMenuAvailability = async (item) => {
  await api.patch(`/menu-items/${item.id}/toggle`)
  await loadData()
}

const formatLogTime = (raw) => {
  if (!raw) return ''
  const d = new Date(raw)
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) +
    ' • ' +
    d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

onMounted(loadData)
</script>

<template>
  <div>
    <!-- Top Section Switcher -->
    <div class="section-bar">
      <button
        :class="['section-tab', { active: activeSection === 'stock' }]"
        @click="activeSection = 'stock'"
      >
        Stock Levels & Movement Log
      </button>
      <button
        :class="['section-tab', { active: activeSection === 'recipes' }]"
        @click="activeSection = 'recipes'"
      >
        Menu & Ingredient Recipes ({{ menuItems.length }})
      </button>
    </div>

    <!-- SECTION 1: STOCK LEVELS, MOVEMENTS & WASTE -->
    <div v-if="activeSection === 'stock'" class="grid-2">
      <!-- Left: Closing Stock Count & Expiry Detection -->
      <div class="card">
        <div v-if="flash.text" :class="['flash-banner', flash.type]">{{ flash.text }}</div>

        <div class="head-flex">
          <div>
            <h3>Stock Levels, Restock & Expiry Alerts</h3>
            <p class="muted" style="margin: 0">Update physical counts, restock batches, and configure shelf-life alerts</p>
          </div>

          <div class="row-gap">
            <button :class="['pill-tab', { active: filterMode === 'all' }]" @click="filterMode = 'all'">
              All ({{ stock.length }})
            </button>
            <button :class="['pill-tab', { active: filterMode === 'low' }]" @click="filterMode = 'low'">
              Low ({{ lowCount }})
            </button>
            <button
              :class="['pill-tab', { active: filterMode === 'expiring', 'has-alert': expCount > 0 }]"
              @click="filterMode = 'expiring'"
            >
              Expiring / Expired ({{ expCount }})
            </button>
          </div>
        </div>

        <div style="margin-top: 12px">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search ingredient (e.g., Fresh Milk, Espresso Beans, Cups)..."
            style="width: 100%; background: var(--crema)"
          />
        </div>

        <div v-if="filteredStock.length === 0" class="empty-box">
          <span v-if="filterMode === 'low'">✓ All ingredients are above minimum stock levels.</span>
          <span v-else-if="filterMode === 'expiring'">✓ No expired or expiring ingredients within their alert window.</span>
          <span v-else>No matching inventory items found.</span>
        </div>

        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Ingredient / Lifespan Settings</th>
                <th>Current Qty</th>
                <th>Alerts & Last Count</th>
                <th style="text-align: right">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="s in filteredStock"
                :key="s.id"
                :class="{
                  'row-expired': getExpiryInfo(s).status === 'expired',
                  'row-alert': s.is_low || getExpiryInfo(s).status === 'today' || getExpiryInfo(s).status === 'soon',
                }"
              >
                <td>
                  <div>
                    <strong>{{ s.name }}</strong>
                    <span class="muted"> ({{ s.unit }})</span>
                  </div>

                  <!-- Inline Owner Expiry & Lifespan Editor -->
                  <div v-if="editingItemId === s.id" class="lifespan-editor num">
                    <div class="editor-field">
                      <label>Current Batch Expiry</label>
                      <input v-model="editForm.expiry_date" type="date" />
                    </div>
                    <div class="editor-field">
                      <label>Alert Before Expiry (Days)</label>
                      <input
                        v-model.number="editForm.expiry_warning_days"
                        type="number"
                        min="0"
                        max="365"
                        style="width: 95px"
                      />
                    </div>
                    <div class="editor-field">
                      <label>Default Shelf Life (Days)</label>
                      <input
                        v-model="editForm.shelf_life_days"
                        type="number"
                        min="1"
                        max="365"
                        placeholder="e.g., 7"
                        style="width: 95px"
                      />
                    </div>
                    <div class="row-gap" style="gap: 5px; align-self: flex-end">
                      <button type="button" class="btn-primary mini-btn" @click="saveItemLifespan(s)">Save</button>
                      <button type="button" class="mini-btn" @click="editingItemId = null">Cancel</button>
                    </div>
                  </div>

                  <!-- Expiry & Lifespan Badges -->
                  <div v-else class="expiry-meta num">
                    <span
                      v-if="getExpiryInfo(s).label"
                      :class="[
                        'pill-badge',
                        getExpiryInfo(s).status === 'expired' || getExpiryInfo(s).status === 'today'
                          ? 'pill-err'
                          : getExpiryInfo(s).status === 'soon'
                            ? 'pill-warn'
                            : 'pill-neutral',
                        { clickable: auth.user?.role === 'owner' },
                      ]"
                      :title="auth.user?.role === 'owner' ? 'Click to edit expiry date & lifespan' : ''"
                      @click="auth.user?.role === 'owner' && startEditLifespan(s)"
                    >
                      {{ getExpiryInfo(s).status === 'expired' ? '⚠ ' : '⏳ ' }}{{ getExpiryInfo(s).label }}
                    </span>

                    <span class="muted lifespan-tag">
                      Warn: {{ s.expiry_warning_days ?? 2 }}d<span v-if="s.shelf_life_days"> • Life: {{ s.shelf_life_days }}d</span>
                    </span>

                    <button
                      v-if="auth.user?.role === 'owner'"
                      type="button"
                      class="set-exp-link"
                      @click="startEditLifespan(s)"
                    >
                      ⚙ Lifespan
                    </button>
                  </div>
                </td>

                <td>
                  <div class="count-stepper num">
                    <button type="button" @click="adjustCount(s, -1)">−</button>
                    <input
                      v-model.number="s.quantity"
                      type="number"
                      min="0"
                      step="0.01"
                      style="width: 72px; text-align: center"
                      @input="markDirty(s.id)"
                    />
                    <button type="button" @click="adjustCount(s, 1)">+</button>
                  </div>
                </td>

                <td>
                  <div class="row-gap" style="gap: 5px">
                    <span v-if="s.is_low" class="pill-badge pill-warn">Low (&le;{{ s.min_level }})</span>
                    <span v-if="!s.is_low && !isItemUrgentExpiry(s)" class="pill-badge pill-ok">Healthy</span>
                  </div>
                  <div v-if="s.counts?.length" class="muted" style="font-size: 11px; margin-top: 4px">
                    Last: {{ s.counts[0].counted_qty }} by {{ s.counts[0].counted_by }}
                  </div>
                </td>

                <td style="text-align: right">
                  <div class="row-gap" style="justify-content: flex-end">
                    <button
                      v-if="getExpiryInfo(s).status === 'expired' || getExpiryInfo(s).status === 'today'"
                      type="button"
                      class="btn-danger"
                      title="Move expired stock to Waste Log"
                      @click="prepareExpiredWaste(s)"
                    >
                      Dispose
                    </button>
                    <button
                      :class="dirtyRows[s.id] ? 'btn-accent' : 'btn-primary'"
                      @click="saveCount(s)"
                    >
                      {{ dirtyRows[s.id] ? 'Save*' : 'Save' }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Owner Action Toggles -->
        <div v-if="auth.user?.role === 'owner'" class="owner-actions">
          <div class="row-gap">
            <button
              :class="ownerPanel === 'stock-in' ? 'btn-accent' : ''"
              @click="ownerPanel = ownerPanel === 'stock-in' ? 'none' : 'stock-in'"
            >
              {{ ownerPanel === 'stock-in' ? 'Close Restock' : '+ Restock / Stock-In' }}
            </button>
            <button
              :class="ownerPanel === 'new-item' ? 'btn-primary' : ''"
              @click="ownerPanel = ownerPanel === 'new-item' ? 'none' : 'new-item'"
            >
              {{ ownerPanel === 'new-item' ? 'Close New Item' : '+ Create New Item' }}
            </button>
          </div>

          <div v-if="ownerPanel === 'stock-in'" class="sub-box">
            <h4>Restock / Record Stock-In Batch (FIFO Expiry)</h4>
            <p class="muted" style="margin-top: 0; font-size: 12px">
              If the ingredient has a Default Shelf Life set, its expiry date fills automatically.
            </p>
            <div class="labeled-grid num">
              <div class="field-group" style="min-width: 175px; flex: 1">
                <label>Select Ingredient</label>
                <select v-model="stockIn.stock_item_id" @change="applyDefaultStockInExpiry(stockIn.stock_item_id)">
                  <option v-for="s in stock" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </div>
              <div class="field-group" style="width: 105px">
                <label>Quantity Added</label>
                <input v-model.number="stockIn.quantity" type="number" min="0.01" step="0.01" placeholder="e.g., 10" />
              </div>
              <div class="field-group" style="width: 115px">
                <label>Unit Cost (₱)</label>
                <input v-model="stockIn.unit_cost" type="number" placeholder="Optional" />
              </div>
              <div class="field-group" style="width: 150px">
                <label>Batch Expiry Date</label>
                <input v-model="stockIn.expiry_date" type="date" />
              </div>
              <button class="btn-accent" style="align-self: flex-end" @click="recordStockIn">Save Batch</button>
            </div>
          </div>

          <div v-if="ownerPanel === 'new-item'" class="sub-box">
            <h4>Create New Inventory Item</h4>
            <div class="labeled-grid num">
              <div class="field-group" style="min-width: 175px; flex: 1">
                <label>Ingredient Name</label>
                <input v-model="newStock.name" placeholder="e.g., Fresh Milk" />
              </div>
              <div class="field-group" style="width: 110px">
                <label>Unit</label>
                <input v-model="newStock.unit" placeholder="pcs, ml, g" />
              </div>
              <div class="field-group" style="width: 105px">
                <label>Starting Qty</label>
                <input v-model.number="newStock.quantity" type="number" min="0" step="0.01" />
              </div>
              <div class="field-group" style="width: 115px">
                <label>Low-Stock Alert</label>
                <input v-model.number="newStock.min_level" type="number" min="0" step="0.01" />
              </div>
              <div class="field-group" style="width: 105px">
                <label>Unit Cost (₱)</label>
                <input v-model.number="newStock.unit_cost" type="number" min="0" />
              </div>
              <div class="field-group" style="width: 135px">
                <label>Warn Before (Days)</label>
                <input
                  v-model.number="newStock.expiry_warning_days"
                  type="number"
                  min="0"
                  title="How many days before expiry to show a warning"
                />
              </div>
              <div class="field-group" style="width: 135px">
                <label>Shelf Life (Days)</label>
                <input
                  v-model="newStock.shelf_life_days"
                  type="number"
                  min="1"
                  placeholder="e.g., 7"
                  title="Default lifespan in days for future Stock-In batches"
                />
              </div>
              <div class="field-group" style="width: 150px">
                <label>Initial Expiry Date</label>
                <input v-model="newStock.expiry_date" type="date" />
              </div>
              <button class="btn-primary" style="align-self: flex-end" @click="addStock">Add Item</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Stock Movement Audit Log + Waste Log -->
      <div style="display: flex; flex-direction: column; gap: 22px">
        <!-- Stock Movement Audit Log -->
        <div class="card">
          <div class="head-flex">
            <div>
              <h3>Stock Movement Log</h3>
              <p class="muted" style="margin: 0">Every order deduction, refund, restock, and adjustment</p>
            </div>
            <select v-model="movementFilter" style="padding: 5px 10px; font-size: 12px; min-height: auto">
              <option value="all">All Movements</option>
              <option value="order_sale">Order Deductions</option>
              <option value="order_void">Void / Refunds</option>
              <option value="stock_in">Restocks</option>
              <option value="waste">Waste</option>
              <option value="closing_count">Count Adjustments</option>
            </select>
          </div>

          <div v-if="filteredMovements.length === 0" class="empty-box">
            No stock movements recorded yet.
          </div>

          <div v-for="m in visibleMovements" :key="m.id" class="waste-row num">
            <div>
              <div>
                <strong :style="{ color: m.change_qty < 0 ? 'var(--berry)' : 'var(--matcha)' }">
                  {{ m.change_qty > 0 ? `+${m.change_qty}` : m.change_qty }}
                </strong>
                <strong style="margin-left: 6px">{{ m.item_name }}</strong>
                <span :class="['pill-badge', reasonMeta(m.reason).cls]" style="margin-left: 8px">
                  {{ reasonMeta(m.reason).label }}
                </span>
              </div>
              <div class="muted" style="font-size: 11px; margin-top: 3px">
                Bal: {{ m.balance_after }}
                <span v-if="m.reference"> • {{ m.reference }}</span>
                <span v-if="m.performed_by"> • By {{ m.performed_by }}</span>
                <span v-if="m.created_at"> • {{ formatLogTime(m.created_at) }}</span>
              </div>
            </div>
          </div>

          <button
            v-if="filteredMovements.length > 6"
            type="button"
            class="see-more-btn"
            @click="showAllMovements = !showAllMovements"
          >
            {{ showAllMovements ? 'Show less' : `See more (${filteredMovements.length - 6} more)` }}
          </button>
        </div>

        <!-- Waste & Spoilage Log -->
        <div ref="wasteCardRef" class="card">
          <h3>Waste & Spoilage Log</h3>
          <p class="muted">Record spoiled, spilled, or expired stock to deduct automatically.</p>

          <div class="waste-form">
            <div class="labeled-grid num">
              <div class="field-group" style="flex: 1; min-width: 165px">
                <label>Ingredient to Deduct</label>
                <select :value="newWaste.stock_item_id" @change="selectWasteItem">
                  <option v-for="s in stock" :key="s.id" :value="s.id">
                    {{ s.name }} ({{ s.quantity }} {{ s.unit }} left)
                  </option>
                </select>
              </div>
              <div class="field-group" style="width: 92px">
                <label>Waste Qty</label>
                <input v-model.number="newWaste.quantity" type="number" min="0.01" step="0.01" />
              </div>
            </div>

            <div class="labeled-grid" style="margin-top: 10px">
              <div class="field-group" style="flex: 1">
                <label>Spoilage / Waste Reason</label>
                <select v-model="newWaste.reason">
                  <option value="spoiled">Spoiled</option>
                  <option value="spilled">Spilled</option>
                  <option value="unsold">Unsold</option>
                  <option value="expired">Expired</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <button class="btn-danger" style="align-self: flex-end" @click="logWaste">Log Waste</button>
            </div>
          </div>

          <h4 style="margin-top: 18px">Recent Waste Entries</h4>
          <div v-if="wasteLogs.length === 0" class="empty-box">
            No waste entries recorded yet.
          </div>

          <div v-for="w in visibleWasteLogs" :key="w.id" class="waste-row num">
            <div>
              <strong>{{ w.quantity }}× {{ w.item_name }}</strong>
              <span :class="['pill-badge', w.reason === 'expired' ? 'pill-err' : 'pill-warn']" style="margin-left: 8px">
                {{ w.reason.toUpperCase() }}
              </span>
              <div class="muted" style="font-size: 11px; margin-top: 3px">
                By {{ w.logged_by }} <span v-if="w.created_at">• {{ formatLogTime(w.created_at) }}</span>
              </div>
            </div>
          </div>

          <button
            v-if="wasteLogs.length > 5"
            type="button"
            class="see-more-btn"
            @click="showAllWaste = !showAllWaste"
          >
            {{ showAllWaste ? 'Show less' : `See more (${wasteLogs.length - 5} more)` }}
          </button>
        </div>
      </div>
    </div>

    <!-- SECTION 2: MENU & INGREDIENT RECIPES -->
    <div v-else class="card">
      <div v-if="flash.text" :class="['flash-banner', flash.type]">{{ flash.text }}</div>

      <div class="head-flex">
        <div>
          <h3>Menu & Ingredient Recipes</h3>
          <p class="muted" style="margin: 0">
            Connect stock ingredients to each menu item for automatic POS deduction
          </p>
        </div>

        <button
          v-if="auth.user?.role === 'owner'"
          class="btn-primary"
          @click="showRecipeForm ? (showRecipeForm = false) : openNewMenuForm()"
        >
          {{ showRecipeForm ? 'Close Editor' : '+ Add Menu Item & Recipe' }}
        </button>
      </div>

      <!-- Owner Menu + Recipe Editor -->
      <div v-if="showRecipeForm && auth.user?.role === 'owner'" class="sub-box" style="margin-top: 16px">
        <h4>{{ editingMenuId ? 'Edit Menu Item & Recipe' : 'New Menu Item & Recipe' }}</h4>

        <div class="labeled-grid num">
          <div class="field-group" style="flex: 2; min-width: 180px">
            <label>Menu Item Name</label>
            <input v-model="menuForm.name" placeholder="e.g., Iced Spanish Latte" />
          </div>
          <div class="field-group" style="flex: 1; min-width: 130px">
            <label>Category</label>
            <input v-model="menuForm.category" placeholder="Coffee, Pastry, Non-Coffee" />
          </div>
          <div class="field-group" style="width: 120px">
            <label>Selling Price (₱)</label>
            <input v-model.number="menuForm.price" type="number" min="0" step="0.01" />
          </div>
        </div>

        <div class="recipe-box">
          <div class="head-flex" style="margin-bottom: 10px">
            <strong>Ingredient Recipe (Deducted per 1 Serving Sold)</strong>
            <button type="button" class="mini-btn" @click="addRecipeRow">+ Add Ingredient</button>
          </div>

          <div v-if="menuForm.recipes.length === 0" class="muted" style="font-size: 13px">
            No ingredients linked yet. Click "+ Add Ingredient" to connect stock items.
          </div>

          <div v-for="(r, idx) in menuForm.recipes" :key="idx" class="recipe-row num">
            <select v-model="r.stock_item_id" style="flex: 1">
              <option v-for="s in stock" :key="s.id" :value="s.id">
                {{ s.name }} ({{ s.quantity }} {{ s.unit }} available)
              </option>
            </select>

            <div class="qty-unit">
              <input
                v-model.number="r.quantity_used"
                type="number"
                min="0.01"
                step="0.01"
                style="width: 95px"
                placeholder="Qty used"
              />
              <span class="muted" style="min-width: 36px">{{ getStockUnit(r.stock_item_id) }}</span>
            </div>

            <button type="button" class="btn-danger mini-btn" @click="removeRecipeRow(idx)">
              Remove
            </button>
          </div>
        </div>

        <div class="row-gap" style="margin-top: 14px; justify-content: flex-end">
          <button type="button" @click="showRecipeForm = false">Cancel</button>
          <button type="button" class="btn-primary" @click="saveMenuItem">
            {{ editingMenuId ? 'Save Changes & Recipe' : 'Create Menu Item' }}
          </button>
        </div>
      </div>

      <div style="margin-top: 14px">
        <input
          v-model="menuSearchQuery"
          type="text"
          placeholder="Search menu item or category..."
          style="width: 100%; background: var(--crema)"
        />
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Menu Item</th>
              <th>Price</th>
              <th>Linked Recipe (Per Serving)</th>
              <th>Stock Status</th>
              <th v-if="auth.user?.role === 'owner'" style="text-align: right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in filteredMenu" :key="m.id">
              <td>
                <strong>{{ m.name }}</strong>
                <div class="muted" style="font-size: 12px">{{ m.category }}</div>
              </td>
              <td class="num">₱{{ Number(m.price).toFixed(2) }}</td>
              <td>
                <div v-if="m.recipes?.length" class="recipe-tags num">
                  <span v-for="r in m.recipes" :key="r.id" class="recipe-pill">
                    {{ r.quantity_used }}{{ r.stock_item?.unit || '' }} {{ r.stock_item?.name }}
                  </span>
                </div>
                <span v-else class="muted" style="font-size: 12px">No recipe linked</span>
              </td>
              <td class="num">
                <span v-if="!m.is_in_stock" class="pill-badge pill-err">Out of Stock</span>
                <span v-else-if="m.max_servings !== null" class="pill-badge pill-ok">
                  {{ m.max_servings }} servings left
                </span>
                <span v-else class="pill-badge pill-neutral">Untracked</span>
              </td>
              <td v-if="auth.user?.role === 'owner'" style="text-align: right">
                <div class="row-gap" style="justify-content: flex-end">
                  <button type="button" class="mini-btn" @click="openEditMenuForm(m)">
                    Edit / Recipe
                  </button>
                  <button
                    type="button"
                    :class="['mini-btn', (m.is_available ?? m.active) ? 'btn-accent' : '']"
                    @click="toggleMenuAvailability(m)"
                  >
                    {{ (m.is_available ?? m.active) ? 'Active' : 'Hidden' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.section-bar {
  display: flex;
  gap: 10px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.section-tab {
  border-radius: 999px;
  padding: 8px 18px;
  min-height: auto;
  font-size: 13.5px;
  font-weight: 700;
  background: var(--crema);
  border: 1px solid var(--border);
}
.section-tab.active {
  background: var(--espresso);
  color: #fff;
  border-color: var(--espresso);
}

.grid-2 { display: grid; grid-template-columns: 1.55fr 1fr; gap: 22px; align-items: start; }
@media (max-width: 960px) { .grid-2 { grid-template-columns: 1fr; } }

.head-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
.pill-tab { border-radius: 999px; padding: 6px 12px; min-height: auto; font-size: 12px; background: var(--crema); }
.pill-tab.active { background: var(--espresso); color: #fff; border-color: var(--espresso); }
.pill-tab.has-alert:not(.active) { border-color: #f5c6c2; background: var(--berry-bg); color: var(--berry); }

.table-wrap { margin-top: 12px; overflow-x: auto; }
.row-alert { background: #fffbf2; }
.row-expired { background: #fff6f5; }

.expiry-meta { margin-top: 5px; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.lifespan-tag { font-size: 11px; }
.pill-neutral { background: var(--crema); color: var(--muted); border: 1px solid var(--border); }
.clickable { cursor: pointer; }
.clickable:hover { opacity: 0.85; }

.lifespan-editor {
  margin-top: 8px;
  padding: 10px;
  background: var(--crema);
  border: 1px solid var(--border);
  border-radius: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.labeled-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: flex-end;
}

.editor-field,
.field-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.editor-field label,
.field-group label {
  font-size: 11px;
  font-weight: 700;
  color: var(--espresso);
  letter-spacing: 0.01em;
}

.field-group input,
.field-group select {
  width: 100%;
}

.editor-field input {
  padding: 5px 8px;
  font-size: 12px;
  min-height: auto;
}

.mini-btn { min-height: auto; padding: 5px 10px; font-size: 12px; border-radius: 7px; }
.set-exp-link { background: transparent; border: none; padding: 0; min-height: auto; font-size: 11.5px; color: var(--caramel); font-weight: 600; }
.set-exp-link:hover { text-decoration: underline; }

.count-stepper { display: inline-flex; align-items: center; gap: 4px; }
.count-stepper button { width: 30px; height: 34px; min-height: auto; padding: 0; border-radius: 8px; }

.empty-box { margin-top: 14px; padding: 22px 14px; text-align: center; background: var(--crema); border-radius: 12px; color: var(--muted); font-size: 13.5px; }
.owner-actions { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border); }

.waste-form { background: var(--crema); padding: 14px; border-radius: 12px; border: 1px solid var(--border); }
.waste-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 14px; }

.recipe-box { margin-top: 14px; padding: 12px; background: #fff; border: 1px solid var(--border); border-radius: 10px; }
.recipe-row { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.qty-unit { display: flex; align-items: center; gap: 6px; }
.recipe-tags { display: flex; flex-wrap: wrap; gap: 5px; }
.recipe-pill { background: var(--crema); border: 1px solid var(--border); border-radius: 999px; padding: 3px 9px; font-size: 11.5px; }

.see-more-btn {
  width: 100%;
  margin-top: 12px;
  padding: 8px 12px;
  min-height: auto;
  font-size: 12.5px;
  font-weight: 600;
  background: var(--crema);
  color: var(--espresso);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.see-more-btn:hover {
  background: var(--border);
}
</style>