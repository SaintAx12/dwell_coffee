import Dexie from 'dexie'

export const db = new Dexie('DwellCoffeePOS')
db.version(1).stores({
  pendingOrders: 'order_id, created_at',
  cachedMenu: 'id',
})