import { api } from './api'

export interface Role {
  id: string
  name: string
  permissions: string[]
}

export interface Permission {
  key: string
  label: string
}

export interface RoleInput {
  name: string
  permissions: string[]
}

const base = (organizationId: string) =>
  `/organizations/${encodeURIComponent(organizationId)}`

export const roleApi = {
  async list(organizationId: string) {
    const response = await api.get<{
      data: Role[]
      meta: { can_manage: boolean }
    }>(`${base(organizationId)}/roles`)
    return response.data
  },
  async permissions(organizationId: string): Promise<Permission[]> {
    const response = await api.get<{ data: Permission[] }>(
      `${base(organizationId)}/permissions`,
    )
    return response.data.data
  },
  async save(organizationId: string, roleId: string | null, input: RoleInput) {
    const url = `${base(organizationId)}/roles`
    const response = roleId
      ? await api.patch<{ data: Role }>(
          `${url}/${encodeURIComponent(roleId)}`,
          input,
        )
      : await api.post<{ data: Role }>(url, input)
    return response.data.data
  },
}
