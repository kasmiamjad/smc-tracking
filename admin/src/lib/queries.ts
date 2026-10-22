import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from './api'
import type { Department, DocType, Paginated, Role, User, UserDocument, UserStatus } from './types'

export interface UserFilters {
  search?: string
  status?: UserStatus | ''
  role?: Role | ''
  departmentId?: string
  blocked?: '' | '0' | '1'
  page?: number
  perPage?: number
}

function qs(params: object): string {
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null)
  return entries.length ? '?' + new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString() : ''
}

// ---- reads ----------------------------------------------------------------

export const useUsers = (filters: UserFilters) =>
  useQuery({
    queryKey: ['users', filters],
    queryFn: () => api.get<Paginated<User>>('/admin/users' + qs(filters)),
    placeholderData: (prev) => prev,
  })

/** Just the total for a filter, for dashboard tiles and badges. */
export const useUserCount = (filters: UserFilters) =>
  useQuery({
    queryKey: ['users', 'count', filters],
    queryFn: () => api.get<Paginated<User>>('/admin/users' + qs({ ...filters, perPage: 1 })),
    select: (res) => res.meta.total,
  })

export const usePendingUsers = () =>
  useQuery({
    queryKey: ['users', 'pending'],
    queryFn: () => api.get<{ data: User[] }>('/admin/users/pending'),
    select: (res) => res.data,
    refetchInterval: 30_000,
  })

export const useUser = (id: string) =>
  useQuery({
    queryKey: ['users', 'detail', id],
    queryFn: () => api.get<{ data: User }>(`/admin/users/${id}`),
    select: (res) => res.data,
  })

export const useDepartments = () =>
  useQuery({
    queryKey: ['departments'],
    queryFn: () => api.get<{ data: Department[] }>('/departments'),
    select: (res) => res.data,
    staleTime: 60_000,
  })

// ---- writes ---------------------------------------------------------------

/** Every user mutation refreshes all user lists, counts and details. */
function useUserMutation<TVars, TResult = { data: User }>(fn: (vars: TVars) => Promise<TResult>) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }) })
}

export const useApproval = () =>
  useUserMutation((v: { id: string; status: 'ACTIVE' | 'REJECTED'; reason?: string }) =>
    api.patch<{ data: User }>(`/admin/users/${v.id}/approval`, { status: v.status, reason: v.reason }),
  )

export const useBlock = () =>
  useUserMutation((v: { id: string; isBlocked: boolean; reason?: string }) =>
    api.patch<{ data: User }>(`/admin/users/${v.id}/block`, { isBlocked: v.isBlocked, reason: v.reason }),
  )

export const useResetPin = () =>
  useUserMutation((id: string) => api.post<{ data: User; temporaryPin: string }>(`/admin/users/${id}/reset-pin`))

export const useUnlock = () => useUserMutation((id: string) => api.post<{ data: User }>(`/admin/users/${id}/unlock`))

export const useUpdateUser = () =>
  useUserMutation((v: { id: string; fullName?: string; phone?: string; departmentId?: string | null }) => {
    const { id, ...body } = v
    return api.patch<{ data: User }>(`/admin/users/${id}`, body)
  })

export interface CreateUserInput {
  role: Role
  fullName: string
  departmentId?: string | null
  phone?: string
  pin?: string
  email?: string
  password?: string
}

export const useCreateUser = () =>
  useUserMutation((body: CreateUserInput) => api.post<{ data: User; temporaryPin: string | null }>('/admin/users', { ...body }))

export const useUploadDocument = () =>
  useUserMutation((v: { userId: string; file: File; docType: DocType }) => {
    const form = new FormData()
    form.append('file', v.file)
    form.append('docType', v.docType)
    return api.post<{ data: UserDocument }>(`/users/${v.userId}/documents`, form)
  })

export const useVerifyDocument = () =>
  useUserMutation((v: { id: string; status: 'APPROVED' | 'REJECTED'; reason?: string }) =>
    api.patch<{ data: UserDocument }>(`/admin/documents/${v.id}/verification`, { status: v.status, reason: v.reason }),
  )

export const useDeleteDocument = () => useUserMutation((id: string) => api.delete<void>(`/documents/${id}`))

function useDepartmentMutation<TVars>(fn: (vars: TVars) => Promise<unknown>) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['departments'] })
      qc.invalidateQueries({ queryKey: ['users'] })
    },
  })
}

export interface DepartmentInput {
  name: string
  colorHex: string
  iconCode?: string | null
}

export const useSaveDepartment = () =>
  useDepartmentMutation((v: DepartmentInput & { id?: string }) => {
    const { id, ...body } = v
    return id ? api.put(`/departments/${id}`, { ...body }) : api.post('/departments', { ...body })
  })

export const useDeleteDepartment = () => useDepartmentMutation((id: string) => api.delete(`/departments/${id}`))
