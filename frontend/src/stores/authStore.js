import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../api/axios'

export const useAuthStore = defineStore('auth', () => {
  const savedToken = localStorage.getItem('dwell_token') || ''
  if (!savedToken) {
    localStorage.removeItem('dwell_user')
  }

  const user = ref(savedToken ? JSON.parse(localStorage.getItem('dwell_user') || 'null') : null)
  const token = ref(savedToken)

  const login = async (employee_no, password) => {
    const { data } = await api.post('/login', { employee_no, password })
    user.value = data.user
    token.value = data.token
    localStorage.setItem('dwell_user', JSON.stringify(data.user))
    localStorage.setItem('dwell_token', data.token)
  }

  const logout = async () => {
    try {
      await api.post('/logout')
    } catch (_) {
      // Clear local session even if token already expired
    }
    user.value = null
    token.value = ''
    localStorage.removeItem('dwell_user')
    localStorage.removeItem('dwell_token')
  }

  return { user, token, login, logout }
})