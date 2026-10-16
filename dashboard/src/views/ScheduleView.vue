<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError, type SchedulerApi } from '../api/client'
import type { Location, NewShift, RuleViolation, ScheduleWeek, StaffMember } from '../api/types'
import NewShiftForm from '../components/NewShiftForm.vue'
import ShiftCard from '../components/ShiftCard.vue'
import ViolationList from '../components/ViolationList.vue'
import { addDays, dayLabel, groupByDay, mondayOf, todayIn, weekDays } from '../week'

const props = defineProps<{ api: SchedulerApi }>()
const emit = defineEmits<{ unauthenticated: [] }>()

const locations = ref<Location[]>([])
const staff = ref<StaffMember[]>([])
const locationId = ref('')
const weekStart = ref('')
const week = ref<ScheduleWeek | null>(null)
const feedback = ref<Record<string, RuleViolation[]>>({})
const notice = ref<{ kind: 'ok' | 'error'; text: string } | null>(null)
const busy = ref(false)

const location = computed(() => locations.value.find((l) => l.id === locationId.value) ?? null)
const days = computed(() => (weekStart.value ? weekDays(weekStart.value) : []))
const byDay = computed(() => groupByDay(week.value?.shifts ?? [], days.value))
const blocking = computed(() => week.value?.violations.filter((v) => v.severity === 'BLOCK').length ?? 0)

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true
  try {
    await action()
  } catch (e) {
    if (e instanceof ApiError && e.unauthenticated) {
      emit('unauthenticated')
      return
    }
    notice.value = { kind: 'error', text: e instanceof Error ? e.message : String(e) }
  } finally {
    busy.value = false
  }
}

async function reload(): Promise<void> {
  if (locationId.value && weekStart.value) {
    week.value = await props.api.schedule(locationId.value, weekStart.value)
  }
}

onMounted(() =>
  run(async () => {
    ;[locations.value, staff.value] = await Promise.all([props.api.locations(), props.api.staff()])
    const first = locations.value[0]
    if (first) {
      locationId.value = first.id
      weekStart.value = mondayOf(todayIn(first.timezone))
      await reload()
    }
  }),
)

watch([locationId, weekStart], (_, old) => {
  if (old[0] !== '' && old[1] !== '') {
    feedback.value = {}
    notice.value = null
    void run(reload)
  }
})

function shiftWeek(delta: number): void {
  weekStart.value = addDays(weekStart.value, delta * 7)
}

function assign(shiftId: string, staffMemberId: string): Promise<void> {
  return run(async () => {
    const result = await props.api.assign(shiftId, staffMemberId)
    feedback.value = { ...feedback.value, [shiftId]: result.violations }
    notice.value = result.blocked ? { kind: 'error', text: 'Assignment refused by a labour rule.' } : null
    await reload()
  })
}

function unassign(assignmentId: string): Promise<void> {
  return run(async () => {
    await props.api.unassign(assignmentId)
    await reload()
  })
}

function create(shift: NewShift): Promise<void> {
  return run(async () => {
    await props.api.createShift(shift)
    notice.value = { kind: 'ok', text: 'Shift added.' }
    await reload()
  })
}

function publish(): Promise<void> {
  return run(async () => {
    const result = await props.api.publish(locationId.value, weekStart.value)
    notice.value = result.published
      ? { kind: 'ok', text: `Published version ${result.week.publishedVersion}.` }
      : { kind: 'error', text: 'Not published: fix the blocking violations first.' }
    await reload()
  })
}
</script>

<template>
  <main class="schedule">
    <div class="toolbar">
      <label>Location
        <select v-model="locationId" :disabled="busy">
          <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
        </select>
      </label>
      <span v-if="location" class="meta">{{ location.timezone }} · rules {{ location.rulePack }}</span>
      <div class="week-nav">
        <button type="button" :disabled="busy" aria-label="Previous week" @click="shiftWeek(-1)">‹</button>
        <span class="week-label">Week of {{ weekStart && dayLabel(weekStart) }}</span>
        <button type="button" :disabled="busy" aria-label="Next week" @click="shiftWeek(1)">›</button>
      </div>
      <span v-if="week" :class="['status', week.status.toLowerCase()]">
        {{ week.status === 'PUBLISHED' ? `Published v${week.publishedVersion}` : week.publishedVersion ? `Draft (changed since v${week.publishedVersion})` : 'Draft' }}
      </span>
      <button type="button" class="primary" :disabled="busy || !week?.id || blocking > 0" @click="publish">Publish week</button>
    </div>

    <p v-if="notice" :class="['notice', notice.kind]" role="status">{{ notice.text }}</p>

    <ViolationList v-if="week" :violations="week.violations" title="Rule check for this week" />

    <div class="grid" role="grid" aria-label="Week schedule">
      <section v-for="day in days" :key="day" class="day" role="gridcell">
        <h2>{{ dayLabel(day) }}</h2>
        <ShiftCard
          v-for="shift in byDay[day]"
          :key="shift.id"
          :shift="shift"
          :staff="staff"
          :feedback="feedback[shift.id]"
          :busy="busy"
          @assign="(staffId) => assign(shift.id, staffId)"
          @unassign="unassign"
        />
        <p v-if="!byDay[day]?.length" class="empty">No shifts</p>
      </section>
    </div>

    <details v-if="locationId && days.length" class="add">
      <summary>Add a shift</summary>
      <NewShiftForm :key="weekStart" :location-id="locationId" :days="days" :busy="busy" @create="create" />
    </details>
  </main>
</template>
