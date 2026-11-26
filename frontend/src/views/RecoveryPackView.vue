<script setup>
import { ref, onMounted } from 'vue'
import api from '../api/axios'

const estimate = ref({ rebuild_estimate: 0, unvalued_items: [] })
const backups = ref([])
const packs = ref([])
const settings = ref({ owner_email: '', send_time: '22:00', pack_password: '' })
const discounts = ref({ tumbler_discount: 10, senior_discount_pct: 20, pwd_discount_pct: 20 })

const loadAll = async () => {
  estimate.value = (await api.get('/recovery-packs/estimate')).data
  backups.value = (await api.get('/backups')).data
  packs.value = (await api.get('/recovery-packs')).data
  const s = (await api.get('/settings/recovery')).data
  settings.value.owner_email = s.owner_email || ''
  settings.value.send_time = s.send_time || '22:00'
  discounts.value = (await api.get('/settings/discounts')).data
}

const createBackup = async () => {
  await api.post('/backups')
  loadAll()
}

const restoreBackup = async (id) => {
  if (prompt('Type RESTORE to overwrite current records:') !== 'RESTORE') return
  try {
    const { data } = await api.post(`/backups/${id}/restore`)
    alert(data.message)
    loadAll()
  } catch (e) {
    alert(e.response?.data?.message || 'Restore failed.')
  }
}

const generatePack = async (sendEmail = false, simulateFail = false) => {
  await api.post('/recovery-packs/generate', { send_email: sendEmail, simulate_fail: simulateFail })
  loadAll()
}

const retryEmail = async (id) => {
  await api.post(`/recovery-packs/${id}/retry-email`)
  loadAll()
}

const downloadPack = async (id) => {
  const res = await api.get(`/recovery-packs/${id}/download`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = `DwellCoffee_RecoveryPack_${id}.zip`
  a.click()
  URL.revokeObjectURL(url)
}

const saveSettings = async () => {
  await api.put('/settings/recovery', settings.value)
  settings.value.pack_password = ''
  alert('Recovery Pack settings saved.')
}

const saveDiscounts = async () => {
  await api.put('/settings/discounts', discounts.value)
  alert('Discount rates updated.')
}

onMounted(loadAll)
</script>

<template>
  <div class="grid-2">
    <div class="card">
      <h3>Off-Site Disaster Recovery Pack</h3>
      <div class="estimate-banner num">
        <small>Estimated Rebuild Cost (Equipment + Current Stock)</small>
        <div class="est-val serif">₱{{ Number(estimate.rebuild_estimate).toLocaleString() }}</div>
        <div v-if="estimate.unvalued_items?.length" class="muted" style="font-size: 12px; margin-top: 4px">
          Excluded ("no value"): {{ estimate.unvalued_items.join(', ') }}
        </div>
      </div>

      <div class="row-gap">
        <button class="btn-primary" @click="generatePack(false)">Generate Encrypted ZIP</button>
        <button class="btn-accent" @click="generatePack(true, false)">Generate & Email Owner</button>
        <button class="btn-danger" @click="generatePack(true, true)">Simulate Email Failure</button>
      </div>

      <h4 style="margin-top: 18px">Generated Recovery Packs</h4>
      <div v-for="p in packs" :key="p.id" class="list-row num">
        <div>
          <strong>Pack #{{ p.id }}</strong> <span class="muted">({{ (p.size / 1024).toFixed(1) }} KB)</span>
          <span :class="['pill-badge', p.status === 'failed' ? 'pill-err' : 'pill-ok']" style="margin-left: 6px">
            {{ p.status.toUpperCase() }}
          </span>
          <span v-if="p.attempts > 0" class="muted"> • {{ p.attempts }} attempt/s</span>
          <div v-if="p.photos_omitted" class="muted" style="font-size: 11px">Photos excluded to stay &le;10 MB</div>
        </div>
        <div class="row-gap">
          <button v-if="p.status === 'failed'" class="btn-danger" @click="retryEmail(p.id)">Retry Email</button>
          <button @click="downloadPack(p.id)">Download ZIP</button>
        </div>
      </div>

      <div class="sub-box">
        <h4>Off-Site Delivery & Encryption Settings</h4>
        <div class="stack">
          <input v-model="settings.owner_email" placeholder="Owner Email" />
          <input v-model="settings.send_time" type="time" />
          <input v-model="settings.pack_password" type="password" placeholder="New AES-256 ZIP Password (default: dwell2026)" />
          <button class="btn-primary" @click="saveSettings">Save Recovery Settings</button>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Database Backup & Integrity Restore</h3>
      <p class="muted">Automated daily snapshot runs at 23:55 with SHA-256 tamper protection.</p>
      <button class="btn-primary" @click="createBackup">Create Manual Backup</button>

      <div style="margin-top: 12px">
        <div v-for="b in backups" :key="b.id" class="list-row num">
          <span><strong>Backup #{{ b.id }}</strong> [{{ b.type }}] • {{ new Date(b.created_at).toLocaleString() }}</span>
          <button @click="restoreBackup(b.id)">Restore</button>
        </div>
      </div>

      <div class="sub-box">
        <h4>POS Discount Rates</h4>
        <div class="stack">
          <label class="muted">Tumbler Discount (₱): <input v-model.number="discounts.tumbler_discount" type="number" /></label>
          <label class="muted">Senior Citizen (%): <input v-model.number="discounts.senior_discount_pct" type="number" /></label>
          <label class="muted">PWD (%): <input v-model.number="discounts.pwd_discount_pct" type="number" /></label>
          <button class="btn-primary" @click="saveDiscounts">Save Discount Rates</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.grid-2 { display: grid; grid-template-columns: 1.15fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 860px) { .grid-2 { grid-template-columns: 1fr; } }
.estimate-banner {
  background: linear-gradient(135deg, var(--espresso), var(--roast));
  color: #fff;
  padding: 18px;
  border-radius: 14px;
  margin-bottom: 14px;
}
.estimate-banner small { color: #d6c5b8; }
.est-val { font-size: 28px; font-weight: 700; color: #fff; margin-top: 2px; }
.estimate-banner .muted { color: #e5d5c8; }
.list-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.stack { display: flex; flex-direction: column; gap: 10px; }
.stack label { display: flex; justify-content: space-between; align-items: center; }
</style>