<script setup>
import { ref, onMounted } from 'vue'
import api from '../api/axios'

const equipment = ref([])
const form = ref({ name: '', quantity: 1, purchase_date: '', value: '', value_type: 'estimated', photo: null })

const loadEquipment = async () => {
  equipment.value = (await api.get('/equipment')).data
}

const handleFile = (e) => {
  form.value.photo = e.target.files[0] || null
}

const addEquipment = async () => {
  if (!form.value.name) return
  const fd = new FormData()
  fd.append('name', form.value.name)
  fd.append('quantity', form.value.quantity)
  if (form.value.purchase_date) fd.append('purchase_date', form.value.purchase_date)
  if (form.value.value !== '') fd.append('value', form.value.value)
  fd.append('value_type', form.value.value_type)
  if (form.value.photo) fd.append('photo', form.value.photo)

  await api.post('/equipment', fd)
  form.value = { name: '', quantity: 1, purchase_date: '', value: '', value_type: 'estimated', photo: null }
  loadEquipment()
}

const printPage = () => window.print()

const exportCsv = () => {
  const rows = [['Name', 'Quantity', 'Purchase Date', 'Value', 'Type']]
  equipment.value.forEach((e) => rows.push([e.name, e.quantity, e.purchase_date || '', e.value ?? 'No value', e.value_type]))
  const csv = rows.map((r) => r.join(',')).join('\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
  a.download = 'dwell_coffee_equipment.csv'
  a.click()
}

onMounted(loadEquipment)
</script>

<template>
  <div class="card">
    <div class="head-flex">
      <div>
        <h3>Shop Equipment Register</h3>
        <p class="muted" style="margin: 0">Asset valuation & photo documentation for insurance and rebuild estimates</p>
      </div>
      <div class="row-gap no-print">
        <button @click="exportCsv">Export CSV</button>
        <button class="btn-primary" @click="printPage">🖨️ Print Register</button>
      </div>
    </div>

    <table style="margin-top: 14px">
      <thead><tr><th>Photo</th><th>Equipment</th><th>Qty</th><th>Purchase Date</th><th>Unit Value</th><th>Valuation</th></tr></thead>
      <tbody>
        <tr v-for="e in equipment" :key="e.id" class="num">
          <td>
            <img v-if="e.photo_path" :src="`http://127.0.0.1:8000/storage/${e.photo_path}`" class="thumb" />
            <div v-else class="no-thumb">☕</div>
          </td>
          <td><strong>{{ e.name }}</strong></td>
          <td>{{ e.quantity }}</td>
          <td>{{ e.purchase_date || 'N/A' }}</td>
          <td>{{ e.value !== null ? `₱${Number(e.value).toLocaleString()}` : 'No value' }}</td>
          <td><span class="pill-badge pill-ok">{{ e.value_type.toUpperCase() }}</span></td>
        </tr>
      </tbody>
    </table>

    <div class="sub-box no-print">
      <h4>Add Equipment Asset</h4>
      <div class="row-gap">
        <input v-model="form.name" placeholder="Equipment name" />
        <input v-model.number="form.quantity" type="number" min="1" style="width: 65px" />
        <input v-model="form.purchase_date" type="date" />
        <input v-model="form.value" type="number" placeholder="Value (optional)" style="width: 135px" />
        <select v-model="form.value_type">
          <option value="exact">Exact</option>
          <option value="estimated">Estimated</option>
        </select>
        <input type="file" accept="image/*" @change="handleFile" />
        <button class="btn-accent" @click="addEquipment">Add Asset</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.head-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
.thumb { width: 52px; height: 52px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border); }
.no-thumb { width: 52px; height: 52px; border-radius: 10px; background: var(--crema); display: grid; place-items: center; color: var(--muted); }
</style>