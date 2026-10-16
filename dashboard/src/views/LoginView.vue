<script setup lang="ts">
import { ref } from 'vue'
import { login } from '../api/client'
import { signIn } from '../session'

const email = ref('manager@northwind.test')
const password = ref('')
const error = ref<string | null>(null)
const busy = ref(false)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    signIn(await login(email.value, password.value))
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="login">
    <h1>Shift scheduler</h1>
    <form @submit.prevent="submit">
      <label>Email <input v-model="email" type="email" autocomplete="username" required /></label>
      <label>Password <input v-model="password" type="password" autocomplete="current-password" required /></label>
      <button type="submit" :disabled="busy">Sign in</button>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
    </form>
    <p class="hint">Demo: manager@northwind.test or manager@harbour.test, password <code>password</code>.</p>
  </main>
</template>
