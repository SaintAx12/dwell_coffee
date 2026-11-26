<script setup>
import { ref, onMounted } from 'vue'
import api from '../api/axios'

const users = ref([])
const form = ref({ name: '', employee_no: '', email: '', role: 'cashier', password: '' })

const loadUsers = async () => {
  users.value = (await api.get('/users')).data
}

const createUser = async () => {
  if (!form.value.name || !form.value.employee_no || !form.value.password) {
    return alert('Name, Employee No., and Password are required.')
  }
  try {
    await api.post('/users', form.value)
    form.value = { name: '', employee_no: '', email: '', role: 'cashier', password: '' }
    loadUsers()
  } catch (e) {
    alert(e.response?.data?.message || 'Error creating user.')
  }
}

const toggleActive = async (u) => {
  try {
    await api.patch(`/users/${u.id}/toggle-active`)
    loadUsers()
  } catch (e) {
    alert(e.response?.data?.message || 'Action failed.')
  }
}

const resetPassword = async (u) => {
  const newPass = prompt(`Enter new password for ${u.name} (${u.employee_no}):`)
  if (!newPass) return
  await api.patch(`/users/${u.id}/reset-password`, { password: newPass })
  alert('Password reset successfully.')
}

onMounted(loadUsers)
</script>

<template>
  <div class="card">
    <h3>Barista & Staff Accounts</h3>
    <p class="muted">Manage counter access, roles, and password resets.</p>
    <table>
      <thead><tr><th>Employee No.</th><th>Full Name</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <tr v-for="u in users" :key="u.id">
          <td><strong>{{ u.employee_no }}</strong></td>
          <td>{{ u.name }}</td>
          <td><span class="pill-badge pill-warn">{{ u.role.toUpperCase() }}</span></td>
          <td><span :class="['pill-badge', u.is_active ? 'pill-ok' : 'pill-err']">{{ u.is_active ? 'Active' : 'Deactivated' }}</span></td>
          <td class="row-gap">
            <button @click="toggleActive(u)">{{ u.is_active ? 'Deactivate' : 'Activate' }}</button>
            <button @click="resetPassword(u)">Reset Password</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="sub-box">
      <h4>Create New Staff Account</h4>
      <div class="row-gap">
        <input v-model="form.employee_no" placeholder="Employee No. (e.g., EMP-003)" />
        <input v-model="form.name" placeholder="Full Name" />
        <select v-model="form.role">
          <option value="cashier">Cashier</option>
          <option value="owner">Owner</option>
        </select>
        <input v-model="form.password" type="password" placeholder="Initial Password" />
        <button class="btn-primary" @click="createUser">Create Account</button>
      </div>
    </div>
  </div>
</template>