<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'

const router = useRouter()
const auth = useAuthStore()
const employeeNo = ref('')
const password = ref('')
const error = ref('')

const fillDemo = (emp, pass) => {
  employeeNo.value = emp
  password.value = pass
}

const submitLogin = async () => {
  error.value = ''
  try {
    await auth.login(employeeNo.value, password.value)
    router.push('/pos')
  } catch (e) {
    error.value = e.response?.data?.message || 'Login failed.'
  }
}
</script>

<template>
  <div class="login-wrapper">
    <div class="login-card">
      <div class="logo-badge">☕</div>
      <h2 class="serif">Dwell Coffee</h2>
      <p class="sub">Specialty Coffee POS & Inventory System</p>

      <form @submit.prevent="submitLogin" class="form">
        <label>
          <span>Employee Number</span>
          <input v-model="employeeNo" placeholder="e.g., EMP-001" required />
        </label>
        <label>
          <span>Password</span>
          <input v-model="password" type="password" placeholder="••••••••" required />
        </label>
        <button type="submit" class="btn-primary submit-btn">Sign In to Counter</button>
        <p v-if="error" class="err">{{ error }}</p>
      </form>

      <div class="quick-fill">
        <span class="muted">Quick Demo Fill:</span>
        <button type="button" @click="fillDemo('EMP-001', 'owner123')">Owner (EMP-001)</button>
        <button type="button" @click="fillDemo('EMP-002', 'cashier123')">Cashier (EMP-002)</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-wrapper {
  min-height: 82vh;
  display: grid;
  place-items: center;
}
.login-card {
  width: 100%;
  max-width: 400px;
  background: var(--milk);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 36px 32px;
  box-shadow: 0 18px 40px rgba(35, 23, 18, 0.1);
  text-align: center;
}
.logo-badge {
  width: 54px;
  height: 54px;
  margin: 0 auto 14px;
  background: var(--espresso);
  color: #fff;
  border-radius: 16px;
  display: grid;
  place-items: center;
  font-size: 26px;
}
h2 {
  font-size: 26px;
  margin-bottom: 4px;
}
.sub {
  color: var(--muted);
  font-size: 14px;
  margin: 0 0 24px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  text-align: left;
}
label span {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: var(--roast);
  margin-bottom: 5px;
}
input {
  width: 100%;
}
.submit-btn {
  width: 100%;
  padding: 13px;
  font-size: 15px;
  margin-top: 6px;
}
.err {
  color: var(--berry);
  background: var(--berry-bg);
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 13px;
  margin: 0;
}
.quick-fill {
  margin-top: 22px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.quick-fill button {
  font-size: 12px;
  padding: 5px 10px;
}
</style>