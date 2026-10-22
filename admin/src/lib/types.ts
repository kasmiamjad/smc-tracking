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

export const DOC_TYPE_LABELS: Record<DocType, string> = {
  NATIONAL_ID: 'National ID',
  DRIVING_LICENSE: 'Driving licence',
  CERTIFICATE: 'Certificate',
  OTHER: 'Other',
}
