/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The SPA talks to the API on the same origin: Vite proxies in development, nginx in the container.
const api = process.env.API_URL ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/graphql': api,
      '/api': api,
    },
  },
  test: {
    environment: 'jsdom',
  },
})
