import { api } from './api'
import type { Role } from './roles'
import type { Organization } from './organizations'

export interface Member {
  id: number
  user_id: number
  name: string
  email: string
  status: 'active' | 'suspended'
  is_owner: boolean
  roles: Role[]
}
export interface Invitation {
  id: string
  email: string
  state: 'pending' | 'expired' | 'accepted' | 'revoked'
  expires_at: string
  roles: Role[]
}
export interface UsersAccess {
  can_view: boolean
  can_invite: boolean
  can_manage: boolean
}
const base = (id: string) => `/organizations/${encodeURIComponent(id)}`
export const usersApi = {
  async access(id: string): Promise<UsersAccess> {
    return (await api.get<{ data: UsersAccess }>(`${base(id)}/users-access`))
      .data.data
  },
  async members(id: string): Promise<Member[]> {
    return (await api.get<{ data: Member[] }>(`${base(id)}/members`)).data.data
  },
  async invitations(id: string): Promise<Invitation[]> {
    return (await api.get<{ data: Invitation[] }>(`${base(id)}/invitations`))
      .data.data
  },
  async invite(id: string, email: string, roles: string[]) {
    return (
      await api.post<{ data: Invitation }>(`${base(id)}/invitations`, {
        email,
        roles,
      })
    ).data.data
  },
  async revoke(id: string, invitation: string) {
    await api.delete(
      `${base(id)}/invitations/${encodeURIComponent(invitation)}`,
    )
  },
  async roles(id: string, member: number, roles: string[]) {
    await api.put(`${base(id)}/members/${member}/roles`, { roles })
  },
  async lifecycle(
    id: string,
    member: number,
    action: 'suspend' | 'activate' | 'remove',
  ) {
    const url = `${base(id)}/members/${member}`
    if (action === 'remove') await api.delete(url)
    else await api.post(`${url}/${action}`)
  },
  async accept(id: string, token: string): Promise<Organization> {
    return (
      await api.post<{ data: Organization }>(
        `/invitations/${encodeURIComponent(id)}/accept`,
        { token },
      )
    ).data.data
  },
}
