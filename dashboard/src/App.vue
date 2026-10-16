<script setup lang="ts">
import { computed } from 'vue'
import { SchedulerApi } from './api/client'
import { session, signOut } from './session'
import LoginView from './views/LoginView.vue'
import ScheduleView from './views/ScheduleView.vue'

const api = computed(() => (session.value ? new SchedulerApi(session.value.token) : null))
</script>

<template>
  <header v-if="session" class="app-header">
    <strong>{{ session.tenant.name }}</strong>
    <span>{{ session.user.name }}</span>
    <button type="button" class="link" @click="signOut">Sign out</button>
  </header>
  <ScheduleView v-if="api" :key="session!.token" :api="api" @unauthenticated="signOut" />
  <LoginView v-else />
</template>
