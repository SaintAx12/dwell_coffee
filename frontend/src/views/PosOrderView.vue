<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api/axios'
import { useAuthStore } from '../stores/authStore'
import { usePosStore } from '../stores/posStore'

const auth = useAuthStore()
const pos = usePosStore()

const flash = ref({ text: '', type: 'ok' })
const setFlash = (text, type = 'ok') => {
  flash.value = { text, type }
  setTimeout(() => { flash.value.text = '' }, 4000)
}

const searchQuery = ref('')
const selectedCategory = ref('All')
const cart = ref([])
const discountType = ref('none')
const paymentMethod = ref('cash')
const cashTendered = ref(0)
const receipt = ref(null)
const charging = ref(false)

const showMenuManager = ref(false)
const stockList = ref([])
const menuForm = ref({
  id: null,
  name: '',
  category: 'Specialty Coffee',
  price: '',
  recipes: [],
})

const isMenuActive = (m) => m.is_active !== false && m.is_available !== false && m.active !== false
const isItemInStock = (m) => m.is_in_stock !== false && (m.max_servings === null || m.max_servings === undefined || Number(m.max_servings) > 0)

const categories = computed(() => {
  const cats = new Set(pos.menu.filter((m) => isMenuActive(m)).map((m) => m.category))
  return ['All', ...Array.from(cats)]
})

const filteredMenu = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return pos.menu.filter((m) => {
    if (!isMenuActive(m)) return false
    const matchesCat = selectedCategory.value === 'All' || m.category === selectedCategory.value
    const matchesSearch = !q || m.name.toLowerCase().includes(q) || m.category.toLowerCase().includes(q)
    return matchesCat && matchesSearch
  })
})

const getItemIcon = (item) => {
  const text = `${item.category} ${item.name}`.toLowerCase()
  if (text.includes('matcha') || text.includes('tea')) return '🍵'
  if (text.includes('pastry') || text.includes('croissant') || text.includes('cake') || text.includes('cookie') || text.includes('bread')) return '🥐'
  if (text.includes('iced') || text.includes('cold') || text.includes('frappe')) return '🧊'
  return '☕'
}

const getCartQty = (id) => cart.value.find((c) => c.id === id)?.qty || 0
const totalCartItems = computed(() => cart.value.reduce((sum, i) => sum + i.qty, 0))

const addToCart = (item) => {
  if (!isItemInStock(item)) {
    const missing = item.missing_ingredients?.length
      ? ` — short on: ${item.missing_ingredients.join(', ')}`
      : ''
    return setFlash(`"${item.name}" is Out of Stock${missing}.`, 'err')
  }

  const found = cart.value.find((c) => c.id === item.id)
  const nextQty = (found ? found.qty : 0) + 1

  if (item.max_servings !== null && item.max_servings !== undefined && nextQty > Number(item.max_servings)) {
    return setFlash(`Only ${item.max_servings} serving(s) of "${item.name}" available in stock.`, 'warn')
  }

  if (found) {
    found.qty = nextQty
    found.quantity = nextQty
  } else {
    cart.value.push({
      ...item,
      menu_item_id: item.id,
      qty: 1,
      quantity: 1,
    })
  }
}

const incrementCartItem = (cartItem) => {
  const liveMenu = pos.menu.find((m) => m.id === cartItem.id) || cartItem
  const nextQty = cartItem.qty + 1

  if (liveMenu.max_servings !== null && liveMenu.max_servings !== undefined && nextQty > Number(liveMenu.max_servings)) {
    return setFlash(`Only ${liveMenu.max_servings} serving(s) of "${cartItem.name}" available in stock.`, 'warn')
  }

  cartItem.qty = nextQty
  cartItem.quantity = nextQty
}

const decrementCartItem = (index) => {
  const item = cart.value[index]
  if (item.qty > 1) {
    item.qty--
    item.quantity = item.qty
  } else {
    cart.value.splice(index, 1)
  }
}

const subtotal = computed(() => cart.value.reduce((s, i) => s + Number(i.price) * i.qty, 0))
const discountAmount = computed(() => {
  const rates = pos.discountRates
  if (discountType.value === 'tumbler') return Math.min(Number(rates.tumbler_discount), subtotal.value)
  if (discountType.value === 'senior') return subtotal.value * (Number(rates.senior_discount_pct) / 100)
  if (discountType.value === 'pwd') return subtotal.value * (Number(rates.pwd_discount_pct) / 100)
  return 0
})
const total = computed(() => Math.max(0, subtotal.value - discountAmount.value))
const change = computed(() => Math.max(0, Number(cashTendered.value) - total.value))
const shortAmount = computed(() => Math.max(0, total.value - Number(cashTendered.value)))

const smartCashOptions = computed(() => {
  const t = Math.ceil(total.value)
  if (t <= 0) return [100, 200, 500]
  const candidates = [
    Math.ceil(t / 50) * 50,
    Math.ceil(t / 100) * 100,
    Math.ceil(t / 500) * 500,
    1000,
  ].filter((v) => v > t)
  return [...new Set(candidates)].slice(0, 3)
})

const chargeOrder = async () => {
  if (charging.value) return
  if (cart.value.length === 0) return setFlash('Select at least one menu item.', 'warn')
  if (paymentMethod.value === 'cash' && Number(cashTendered.value) < total.value) {
    return setFlash('Cash received is less than total due.', 'err')
  }

  charging.value = true
  try {
    const normalizedItems = cart.value.map((c) => ({
      ...c,
      menu_item_id: c.id,
      quantity: c.qty,
      qty: c.qty,
    }))

    const saved = await pos.saveOrderOfflineFirst({
      items: normalizedItems,
      payment_method: paymentMethod.value,
      discount_type: discountType.value,
      discount_amount: discountAmount.value,
      total: total.value,
      cash_tendered: Number(cashTendered.value),
      change: change.value,
    })

    receipt.value = saved
    cart.value = []
    discountType.value = 'none'
    cashTendered.value = 0

    const offline = !pos.isOnline || pos.simulateOffline
    if (!offline) {
      await pos.loadMenu()
    }

    const queuedForSync = pos.pendingCount > 0
    setFlash(
      queuedForSync
        ? offline
          ? 'Order saved offline — queued for sync.'
          : `Order saved on this device but sync failed: ${pos.syncError || 'waiting to retry.'}`
        : 'Order charged & recipe ingredients deducted from stock!',
      queuedForSync ? 'warn' : 'ok'
    )
  } catch (e) {
    const stockError = e.response?.data?.errors?.stock?.[0] || e.response?.data?.message
    setFlash(stockError || 'Order blocked due to insufficient ingredient stock.', 'err')
    if (pos.isOnline && !pos.simulateOffline) {
      await pos.loadMenu()
    }
  } finally {
    charging.value = false
  }
}

const printPage = () => window.print()

// Owner Inline Menu & Recipe Manager
const resetMenuForm = () => {
  menuForm.value = { id: null, name: '', category: 'Specialty Coffee', price: '', recipes: [] }
}

const addRecipeLine = () => {
  if (!stockList.value.length) {
    return setFlash('Add stock ingredients first in the Stock page.', 'warn')
  }
  const usedIds = new Set(menuForm.value.recipes.map((r) => Number(r.stock_item_id)))
  const nextItem = stockList.value.find((s) => !usedIds.has(s.id)) || stockList.value[0]
  menuForm.value.recipes.push({ stock_item_id: nextItem.id, quantity_used: 1 })
}

const removeRecipeLine = (idx) => {
  menuForm.value.recipes.splice(idx, 1)
}

const getStockUnit = (stockId) => {
  return stockList.value.find((s) => s.id === Number(stockId))?.unit || ''
}

const saveMenuItem = async () => {
  if (!menuForm.value.name || menuForm.value.price === '') {
    return setFlash('Enter a menu item name and price.', 'warn')
  }

  const cleanRecipes = menuForm.value.recipes.filter(
    (r) => r.stock_item_id && Number(r.quantity_used) > 0
  )

  const payload = {
    name: menuForm.value.name,
    category: menuForm.value.category,
    price: Number(menuForm.value.price),
    recipes: cleanRecipes,
  }

  try {
    if (menuForm.value.id) {
      await api.put(`/menu-items/${menuForm.value.id}`, payload)
      setFlash('Menu item & recipe updated.', 'ok')
    } else {
      await api.post('/menu-items', payload)
      setFlash('New menu item & recipe added.', 'ok')
    }
    resetMenuForm()
    await pos.loadMenu()
  } catch (e) {
    setFlash(e.response?.data?.message || 'Failed to save menu item.', 'err')
  }
}

const editMenuItem = (m) => {
  menuForm.value = {
    id: m.id,
    name: m.name,
    category: m.category,
    price: m.price,
    recipes: Array.isArray(m.recipes)
      ? m.recipes.map((r) => ({
          stock_item_id: r.stock_item_id,
          quantity_used: Number(r.quantity_used),
        }))
      : [],
  }
}

const toggleMenu = async (id) => {
  await api.patch(`/menu-items/${id}/toggle`)
  await pos.loadMenu()
}

onMounted(async () => {
  await pos.loadDiscountRates()
  await pos.loadMenu()
  if (auth.user?.role === 'owner' && pos.isOnline && !pos.simulateOffline) {
    try { stockList.value = (await api.get('/stock-items')).data } catch (_) {}
  }
})
</script>

<template>
  <div class="pos-layout">
    <!-- Left: Café Menu -->
    <div class="card no-print">
      <div class="section-head">
        <div>
          <h3>Café Menu</h3>
          <p class="muted" style="margin: 0">Tap an item to add it to the active ticket</p>
        </div>
        <button v-if="auth.user?.role === 'owner'" @click="showMenuManager = !showMenuManager">
          {{ showMenuManager ? 'Close Editor' : '✎ Edit Menu' }}
        </button>
      </div>

      <div class="toolbar">
        <div class="search-wrap">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search espresso, iced latte, matcha, pastry..."
            class="search-box"
          />
          <button v-if="searchQuery" type="button" class="clear-search" @click="searchQuery = ''">×</button>
        </div>

        <div class="cat-tabs">
          <button
            v-for="cat in categories"
            :key="cat"
            :class="['cat-tab', { active: selectedCategory === cat }]"
            @click="selectedCategory = cat"
          >
            {{ cat }}
          </button>
        </div>
      </div>

      <div v-if="filteredMenu.length === 0" class="empty-ticket">
        <p style="margin: 0 0 10px">No menu items match "{{ searchQuery }}"</p>
        <button v-if="searchQuery || selectedCategory !== 'All'" @click="searchQuery = ''; selectedCategory = 'All'">
          Reset Filter
        </button>
      </div>

      <div v-else class="menu-grid">
        <button
          v-for="item in filteredMenu"
          :key="item.id"
          :class="[
            'drink-card',
            {
              selected: getCartQty(item.id) > 0,
              'out-of-stock': !isItemInStock(item),
            },
          ]"
          @click="addToCart(item)"
        >
          <span v-if="!isItemInStock(item)" class="oos-badge">
            Out of Stock
          </span>
          <span v-else-if="getCartQty(item.id) > 0" class="qty-badge num">
            {{ getCartQty(item.id) }}×
          </span>
          <span
            v-else-if="item.max_servings !== null && item.max_servings !== undefined && item.max_servings <= 5"
            class="low-serv-badge num"
          >
            {{ item.max_servings }} left
          </span>

          <div class="drink-top">
            <span class="drink-icon">{{ getItemIcon(item) }}</span>
            <span class="drink-cat">{{ item.category }}</span>
          </div>
          <span class="drink-name">{{ item.name }}</span>
          <span class="drink-price num">₱{{ Number(item.price).toFixed(2) }}</span>
        </button>
      </div>

      <!-- Owner Menu & Recipe Editor -->
      <div v-if="auth.user?.role === 'owner' && showMenuManager" class="sub-box">
        <h4>{{ menuForm.id ? 'Edit Menu Item & Recipe' : 'Add New Menu Item & Recipe' }}</h4>
        <div class="row-gap">
          <input v-model="menuForm.name" placeholder="Item name" style="flex: 2; min-width: 150px" />
          <input v-model="menuForm.category" placeholder="Category" style="width: 145px" />
          <input v-model.number="menuForm.price" type="number" placeholder="Price" style="width: 90px" />
          <button type="button" @click="addRecipeLine">+ Ingredient</button>
        </div>

        <div v-if="menuForm.recipes.length" class="recipe-editor-list">
          <div v-for="(r, idx) in menuForm.recipes" :key="idx" class="row-gap num" style="margin-top: 8px">
            <select v-model="r.stock_item_id" style="flex: 1">
              <option v-for="s in stockList" :key="s.id" :value="s.id">
                {{ s.name }} ({{ s.quantity }} {{ s.unit }} left)
              </option>
            </select>
            <input
              v-model.number="r.quantity_used"
              type="number"
              min="0.01"
              step="0.01"
              placeholder="Qty"
              style="width: 85px"
            />
            <span class="muted" style="min-width: 34px; font-size: 12px">{{ getStockUnit(r.stock_item_id) }}</span>
            <button type="button" class="btn-danger" style="padding: 4px 9px; min-height: auto" @click="removeRecipeLine(idx)">
              ×
            </button>
          </div>
        </div>

        <div class="row-gap" style="margin-top: 10px">
          <button class="btn-primary" @click="saveMenuItem">{{ menuForm.id ? 'Update Item & Recipe' : 'Add Item' }}</button>
          <button v-if="menuForm.id" @click="resetMenuForm">Cancel</button>
        </div>

        <div class="manage-list">
          <div v-for="m in pos.menu" :key="m.id" class="manage-row">
            <div>
              <strong>{{ m.name }}</strong>
              <span class="muted"> ({{ m.category }} • ₱{{ m.price }})</span>
              <span v-if="!isItemInStock(m)" class="pill-badge pill-err" style="margin-left: 6px">Out of Stock</span>
              <div v-if="m.recipes?.length" class="muted" style="font-size: 11px; margin-top: 2px">
                Recipe:
                <span v-for="(r, i) in m.recipes" :key="r.id">
                  {{ r.quantity_used }}{{ r.stock_item?.unit || '' }} {{ r.stock_item?.name }}<span v-if="i < m.recipes.length - 1">, </span>
                </span>
              </div>
            </div>
            <div class="row-gap">
              <button @click="editMenuItem(m)">Edit</button>
              <button @click="toggleMenu(m.id)">{{ isMenuActive(m) ? 'Hide' : 'Show' }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Order Ticket -->
    <div class="card ticket-card">
      <div class="no-print">
        <div v-if="flash.text" :class="['flash-banner', flash.type]">{{ flash.text }}</div>

        <div class="section-head">
          <h3>
            Order Ticket
            <span v-if="totalCartItems > 0" class="ticket-count num">{{ totalCartItems }}</span>
          </h3>
          <button v-if="cart.length" class="btn-danger" @click="cart = []">Clear</button>
        </div>

        <div v-if="cart.length === 0" class="empty-ticket">
          <span style="font-size: 28px">🧾</span>
          <p style="margin: 6px 0 0">Tap drinks or pastries on the left to build an order</p>
        </div>

        <div v-else class="cart-list">
          <div v-for="(item, i) in cart" :key="item.id" class="cart-row">
            <div>
              <div class="cart-name">{{ item.name }}</div>
              <div class="muted num">₱{{ Number(item.price).toFixed(2) }} × {{ item.qty }} = <strong>₱{{ (item.price * item.qty).toFixed(2) }}</strong></div>
            </div>
            <div class="stepper">
              <button @click="decrementCartItem(i)">−</button>
              <span class="num" style="min-width: 20px; text-align: center; font-weight: 700">{{ item.qty }}</span>
              <button @click="incrementCartItem(item)">+</button>
            </div>
          </div>
        </div>

        <div class="checkout-panel">
          <div class="control-group">
            <label>Discount</label>
            <div class="seg-group">
              <button :class="{ active: discountType === 'none' }" @click="discountType = 'none'">None</button>
              <button :class="{ active: discountType === 'tumbler' }" @click="discountType = 'tumbler'">
                Tumbler (−₱{{ pos.discountRates.tumbler_discount }})
              </button>
              <button :class="{ active: discountType === 'senior' }" @click="discountType = 'senior'">
                Senior ({{ pos.discountRates.senior_discount_pct }}%)
              </button>
              <button :class="{ active: discountType === 'pwd' }" @click="discountType = 'pwd'">
                PWD ({{ pos.discountRates.pwd_discount_pct }}%)
              </button>
            </div>
          </div>

          <div class="totals-box num">
            <div class="tot-line"><span>Subtotal</span><span>₱{{ subtotal.toFixed(2) }}</span></div>
            <div v-if="discountAmount > 0" class="tot-line discount">
              <span>Discount ({{ discountType.toUpperCase() }})</span>
              <span>−₱{{ discountAmount.toFixed(2) }}</span>
            </div>
            <div class="tot-grand"><span>Total Due</span><span>₱{{ total.toFixed(2) }}</span></div>
          </div>

          <div class="control-group">
            <label>Payment Method</label>
            <div class="seg-group">
              <button :class="{ active: paymentMethod === 'cash' }" @click="paymentMethod = 'cash'">💵 Cash</button>
              <button :class="{ active: paymentMethod === 'gcash' }" @click="paymentMethod = 'gcash'">📱 GCash</button>
            </div>
          </div>

          <div v-if="paymentMethod === 'cash'" class="cash-box">
            <div class="quick-cash num">
              <button type="button" @click="cashTendered = total">Exact</button>
              <button v-for="amt in smartCashOptions" :key="amt" type="button" @click="cashTendered = amt">
                ₱{{ amt.toLocaleString() }}
              </button>
            </div>
            <div class="cash-input-row">
              <input v-model.number="cashTendered" type="number" min="0" placeholder="Cash received" />
              <div v-if="cashTendered > 0 && shortAmount > 0" class="change-pill short num">
                Short: <strong>₱{{ shortAmount.toFixed(2) }}</strong>
              </div>
              <div v-else class="change-pill num">
                Change: <strong>₱{{ change.toFixed(2) }}</strong>
              </div>
            </div>
          </div>

          <button class="btn-accent charge-btn" :disabled="charging || cart.length === 0" @click="chargeOrder">
            {{ charging ? 'Processing...' : `Charge ₱${total.toFixed(2)}` }}
          </button>
        </div>
      </div>

      <!-- Printable Thermal Receipt -->
      <div v-if="receipt" class="thermal-receipt">
        <div class="receipt-head">
          <strong>DWELL COFFEE</strong>
          <small>Ampayon, Butuan City</small>
          <small>Order #{{ receipt.order_id.slice(0, 8).toUpperCase() }}</small>
        </div>
        <div class="receipt-items num">
          <div v-for="it in receipt.items" :key="it.id" class="r-line">
            <span>{{ it.qty }}× {{ it.name }}</span>
            <span>₱{{ (it.qty * it.price).toFixed(2) }}</span>
          </div>
        </div>
        <div class="receipt-foot num">
          <div class="r-line">
            <span>Discount ({{ receipt.discount_type.toUpperCase() }})</span>
            <span>−₱{{ Number(receipt.discount_amount).toFixed(2) }}</span>
          </div>
          <div class="r-line bold">
            <span>TOTAL ({{ receipt.payment_method.toUpperCase() }})</span>
            <span>₱{{ Number(receipt.total).toFixed(2) }}</span>
          </div>
          <div v-if="receipt.payment_method === 'cash'" class="r-line">
            <span>Cash / Change</span>
            <span>₱{{ Number(receipt.cash_tendered).toFixed(2) }} / ₱{{ Number(receipt.change).toFixed(2) }}</span>
          </div>
        </div>
        <div class="row-gap no-print" style="margin-top: 12px">
          <button style="flex: 1" @click="printPage">🖨️ Print Receipt</button>
          <button @click="receipt = null">Dismiss</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pos-layout { display: grid; grid-template-columns: 1.4fr 1fr; gap: 22px; align-items: start; }
@media (max-width: 880px) { .pos-layout { grid-template-columns: 1fr; } }
.section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.section-head h3 { margin: 0; display: flex; align-items: center; gap: 8px; }
.ticket-count { background: var(--espresso); color: #fff; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 12px; padding: 2px 8px; border-radius: 999px; }
.toolbar { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.search-wrap { position: relative; display: flex; align-items: center; }
.search-box { width: 100%; background: var(--crema); padding-right: 34px; }
.clear-search { position: absolute; right: 6px; min-height: auto; padding: 2px 8px; border: none; background: transparent; font-size: 18px; color: var(--muted); }
.cat-tabs { display: flex; gap: 7px; flex-wrap: wrap; }
.cat-tab { border-radius: 999px; padding: 6px 14px; min-height: auto; font-size: 12.5px; background: var(--crema); }
.cat-tab.active { background: var(--espresso); color: #fff; border-color: var(--espresso); }
.menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(154px, 1fr)); gap: 12px; }
.drink-card { position: relative; display: flex; flex-direction: column; align-items: flex-start; justify-content: space-between; min-height: 108px; padding: 14px; border-radius: 14px; background: var(--crema); border: 1.5px solid var(--border); text-align: left; }
.drink-card:hover { background: var(--latte); border-color: var(--caramel); transform: translateY(-2px); }
.drink-card.selected { border-color: var(--caramel); background: #fdf6ee; }
.drink-card.out-of-stock { opacity: 0.58; background: #f5f2ef; border-color: #e3dad0; cursor: not-allowed; }
.drink-card.out-of-stock:hover { transform: none; background: #f5f2ef; border-color: #e3dad0; }
.qty-badge { position: absolute; top: 10px; right: 10px; background: var(--espresso); color: #fff; font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 999px; }
.oos-badge { position: absolute; top: 10px; right: 10px; background: var(--berry-bg); color: var(--berry); border: 1px solid #f5c6c2; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; }
.low-serv-badge { position: absolute; top: 10px; right: 10px; background: #fff3d6; color: #9a5b00; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; }
.drink-top { display: flex; align-items: center; gap: 5px; }
.drink-icon { font-size: 14px; }
.drink-cat { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
.drink-name { font-weight: 700; font-size: 15px; color: var(--espresso); margin: 5px 0; }
.drink-price { font-weight: 700; color: var(--caramel); font-size: 14px; }
.empty-ticket { text-align: center; padding: 34px 12px; background: var(--crema); border-radius: 12px; color: var(--muted); }
.cart-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); }
.cart-name { font-weight: 600; font-size: 14px; }
.stepper { display: flex; align-items: center; gap: 8px; }
.stepper button { width: 32px; height: 32px; min-height: auto; padding: 0; border-radius: 8px; }
.checkout-panel { margin-top: 16px; display: flex; flex-direction: column; gap: 14px; }
.control-group label { display: block; font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 6px; }
.seg-group { display: flex; gap: 6px; flex-wrap: wrap; }
.seg-group button { flex: 1; min-width: 72px; font-size: 12px; padding: 8px; }
.seg-group button.active { background: var(--espresso); color: #fff; border-color: var(--espresso); }
.totals-box { background: var(--crema); padding: 14px; border-radius: 12px; }
.tot-line { display: flex; justify-content: space-between; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
.tot-line.discount { color: var(--matcha); font-weight: 600; }
.tot-grand { display: flex; justify-content: space-between; font-size: 20px; font-weight: 700; color: var(--espresso); border-top: 1px dashed #cbbba9; padding-top: 8px; margin-top: 6px; }
.quick-cash { display: flex; gap: 6px; margin-bottom: 8px; }
.quick-cash button { flex: 1; padding: 6px; min-height: 32px; font-size: 12px; }
.cash-input-row { display: flex; gap: 10px; align-items: center; }
.cash-input-row input { flex: 1; }
.change-pill { background: var(--matcha-bg); color: var(--matcha); padding: 10px 12px; border-radius: 10px; font-size: 13px; white-space: nowrap; }
.change-pill.short { background: var(--berry-bg); color: var(--berry); }
.charge-btn { width: 100%; padding: 15px; font-size: 16px; border-radius: 12px; }
.thermal-receipt { margin-top: 18px; padding: 16px; background: #fffdf9; border: 1px dashed #bfb0a0; border-radius: 10px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.receipt-head { text-align: center; display: flex; flex-direction: column; border-bottom: 1px dashed #bfb0a0; padding-bottom: 8px; margin-bottom: 8px; }
.r-line { display: flex; justify-content: space-between; margin: 4px 0; }
.r-line.bold { font-weight: 700; font-size: 13px; border-top: 1px dashed #bfb0a0; padding-top: 6px; }
.recipe-editor-list { background: var(--milk); padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); margin-top: 8px; }
.manage-list { margin-top: 12px; display: flex; flex-direction: column; gap: 6px; }
.manage-row { display: flex; justify-content: space-between; align-items: center; background: var(--milk); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); }
</style>