import { api } from './api'

export interface Organization {
  id: string
  name: string
}

export const organizationApi = {
  async list(): Promise<Organization[]> {
    const response = await api.get<{ data: Organization[] }>('/organizations')
    return response.data.data
  },

  async create(name: string): Promise<Organization> {
    const response = await api.post<{ data: Organization }>('/organizations', {
      name,
    })
    return response.data.data
  },

  async get(id: string): Promise<Organization> {
    const response = await api.get<{ data: Organization }>(
      `/organizations/${encodeURIComponent(id)}`,
    )
    return response.data.data
  },
}
