import { ref } from 'vue'
import type { LoginResult } from './api/client'

const KEY = 'scheduler.session'

function load(): LoginResult | null {
  try {
    const raw = sessionStorage.getItem(KEY)
    return raw ? (JSON.parse(raw) as LoginResult) : null
  } catch {
    return null
  }
}

/** Token lives in sessionStorage (cleared when the tab closes); a short-lived token from /api/login. */
export const session = ref<LoginResult | null>(load())

export function signIn(result: LoginResult): void {
  session.value = result
  try {
    sessionStorage.setItem(KEY, JSON.stringify(result))
  } catch {
    // storage unavailable: the session lasts until reload
  }
}

export function signOut(): void {
  session.value = null
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    // ignore
  }
}
