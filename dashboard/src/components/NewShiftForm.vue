<script setup lang="ts">
import { reactive } from 'vue'
import type { NewShift } from '../api/types'
import { dayLabel } from '../week'

const props = defineProps<{ locationId: string; days: string[]; busy?: boolean }>()
const emit = defineEmits<{ create: [shift: NewShift] }>()

const form = reactive({ localDate: props.days[0] ?? '', startLocal: '09:00', endLocal: '17:00', jobRole: 'barista', requiredSkill: '', headcount: 1 })

function submit(): void {
  emit('create', {
    locationId: props.locationId,
    localDate: form.localDate || props.days[0]!,
    startLocal: form.startLocal,
    endLocal: form.endLocal,
    jobRole: form.jobRole,
    requiredSkill: form.requiredSkill.trim() || null,
    headcount: Number(form.headcount),
  })
}
</script>

<template>
  <form class="new-shift" @submit.prevent="submit">
    <label>Day
      <select v-model="form.localDate">
        <option v-for="d in days" :key="d" :value="d">{{ dayLabel(d) }}</option>
      </select>
    </label>
    <label>Start <input v-model="form.startLocal" type="time" required /></label>
    <label>End <input v-model="form.endLocal" type="time" required /></label>
    <label>Role <input v-model="form.jobRole" required maxlength="100" /></label>
    <label>Skill <input v-model="form.requiredSkill" placeholder="optional" maxlength="100" /></label>
    <label>People <input v-model.number="form.headcount" type="number" min="1" max="20" /></label>
    <button type="submit" :disabled="busy">Add shift</button>
  </form>
</template>
