<script setup lang="ts">
import type { RuleViolation } from '../api/types'

defineProps<{ violations: RuleViolation[]; title?: string }>()
</script>

<template>
  <section v-if="violations.length" class="violations" :aria-label="title ?? 'Rule violations'">
    <h3 v-if="title">{{ title }}</h3>
    <ul>
      <li v-for="v in violations" :key="v.rule + v.message" :class="['violation', v.severity.toLowerCase()]">
        <span class="badge">{{ v.severity === 'BLOCK' ? 'Blocked' : 'Warning' }}</span>
        <span class="message">{{ v.message }}</span>
      </li>
    </ul>
  </section>
</template>
