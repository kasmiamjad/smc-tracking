export type Role = 'ADMIN' | 'DISPATCHER' | 'TECHNICIAN'
export type UserStatus = 'PENDING_APPROVAL' | 'ACTIVE' | 'REJECTED'
export type DocType = 'NATIONAL_ID' | 'DRIVING_LICENSE' | 'CERTIFICATE' | 'OTHER'
export type VerificationStatus = 'PENDING' | 'APPROVED' | 'REJECTED'

export interface Department {
  id: string
  name: string
  colorHex: string
  iconCode: string | null
  usersCount?: number
}

export interface UserDocument {
  id: string
  userId: string
  docType: DocType
  originalName: string
  mimeType: string
  sizeBytes: number
  verificationStatus: VerificationStatus
  rejectionReason: string | null
  verifiedAt: string | null
  uploadedBy: string | null
  uploadedAt: string
  downloadUrl: string
}

export interface User {
  id: string
  fullName: string
  role: Role
  status: UserStatus
  phone: string | null
  email: string | null
  departmentId: string | null
  department?: Department | null
  mustChangePin: boolean
  isBlocked: boolean
  blockedAt: string | null
  blockReason: string | null
  isLocked: boolean
  approvedAt: string | null
  rejectionReason: string | null
  lastLoginAt: string | null
  createdAt: string
  documents?: UserDocument[]
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
}

export interface TokenPair {
  token: string
  tokenExpiresAt: string
  refreshToken: string
  refreshTokenExpiresAt: string
}

export type AssignmentStatus = 'SCHEDULED' | 'ACTIVE' | 'COMPLETED' | 'ABSENT'
export type Freshness = 'LIVE' | 'LAGGING' | 'OFFLINE'

export interface LatLng {
  lat: number
  lng: number
}

export interface Shift {
  id: string
  name: string
  startTime: string // "08:00", wall clock in `timezone`
  endTime: string
  crossesMidnight: boolean
  lengthMinutes: number
  daysOfWeek: number[] // ISO: 1 = Monday ... 7 = Sunday
  geofence: LatLng[] | null
  isActive: boolean
  timezone: string
  assignmentsCount?: number
}

export interface ShiftAssignment {
  id: string
  userId: string
  user?: User
  shiftId: string
  shift?: Shift
  shiftDate: string
  scheduledStartAt: string
  scheduledEndAt: string
  status: AssignmentStatus
  checkedInAt: string | null
  checkIn: LatLng | null
  checkInOutsideZone: boolean
  checkedOutAt: string | null
  checkOut: LatLng | null
  autoCheckedOut: boolean
  workedHours: number | null
  regularHours: number | null
  overtimeHours: number | null
}

export interface FleetMember {
  userId: string
  fullName: string
  phone: string | null
  department: { id: string; name: string; colorHex: string } | null
  assignmentId: string
  shiftName: string
  checkedInAt: string
  scheduledEndAt: string
  position: {
    latitude: number
    longitude: number
    accuracy: number | null
    speed: number | null
    heading: number | null
    batteryLevel: number | null
    isCharging: boolean | null
    recordedAt: string
  } | null
  ageSeconds: number | null
  freshness: Freshness
}

export const WEEKDAYS = [
  { iso: 1, short: 'Mon' },
  { iso: 2, short: 'Tue' },
  { iso: 3, short: 'Wed' },
  { iso: 4, short: 'Thu' },
  { iso: 5, short: 'Fri' },
  { iso: 6, short: 'Sat' },
  { iso: 7, short: 'Sun' },
]

export const DOC_TYPE_LABELS: Record<DocType, string> = {
  NATIONAL_ID: 'National ID',
  DRIVING_LICENSE: 'Driving licence',
  CERTIFICATE: 'Certificate',
  OTHER: 'Other',
}
