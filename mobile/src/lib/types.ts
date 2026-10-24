export interface User {
  id: string
  fullName: string
  role: 'ADMIN' | 'DISPATCHER' | 'TECHNICIAN'
  status: 'PENDING_APPROVAL' | 'ACTIVE' | 'REJECTED'
  phone: string | null
  departmentId: string | null
  department?: { id: string; name: string; colorHex: string } | null
  mustChangePin: boolean
}

export interface Assignment {
  id: string
  shift: { id: string; name: string; startTime: string; endTime: string; geofence: unknown } | null
  shiftDate: string
  scheduledStartAt: string
  scheduledEndAt: string
  status: 'SCHEDULED' | 'ACTIVE' | 'COMPLETED' | 'ABSENT'
  checkedInAt: string | null
  checkInOutsideZone: boolean
}

export interface MyShifts {
  active: Assignment | null
  upcoming: Assignment[]
  checkInOpensMinutesBefore: number
}

export interface Department {
  id: string
  name: string
  colorHex: string
}
