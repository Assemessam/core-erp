import { api } from './api'

export const auditActions = [
  { key: 'organization.created', label: 'Organization created' },
  { key: 'organization.renamed', label: 'Organization renamed' },
  { key: 'role.created', label: 'Role created' },
  { key: 'role.updated', label: 'Role updated' },
  { key: 'invitation.created', label: 'Invitation created' },
  { key: 'invitation.revoked', label: 'Invitation revoked' },
  { key: 'invitation.accepted', label: 'Invitation accepted' },
  { key: 'membership.roles_changed', label: 'Member roles changed' },
  { key: 'membership.suspended', label: 'Member suspended' },
  { key: 'membership.activated', label: 'Member reactivated' },
  { key: 'membership.removed', label: 'Member removed' },
] as const

export const auditSubjects = [
  'organization',
  'role',
  'invitation',
  'membership',
] as const
export type AuditAction = (typeof auditActions)[number]['key']
export type AuditSubjectType = (typeof auditSubjects)[number]

export interface AuditEvent {
  id: string
  action: string
  actor: { type: string; id: number | null }
  subject: { type: string; id: string }
  changes: {
    before: Record<string, unknown> | null
    after: Record<string, unknown> | null
  }
  payload_version: number
  created_at: string
}

export interface AuditPage {
  data: AuditEvent[]
  meta: { next_cursor: string | null; has_more: boolean; per_page: number }
}

export interface AuditFilters {
  action?: AuditAction
  subject_type?: AuditSubjectType
  subject_id?: string
}

export const auditApi = {
  async list(
    organizationId: string,
    filters: AuditFilters = {},
    cursor?: string,
  ): Promise<AuditPage> {
    const response = await api.get<AuditPage>(
      `/organizations/${encodeURIComponent(organizationId)}/audit-events`,
      { params: { ...filters, per_page: 25, ...(cursor ? { cursor } : {}) } },
    )
    return response.data
  },
}

export function actionLabel(action: string): string {
  return (
    auditActions.find((item) => item.key === action)?.label ??
    'Unrecognized activity'
  )
}

export function actorLabel(event: AuditEvent): string {
  if (event.actor.type === 'system') return 'System'
  return event.actor.type === 'user' && event.actor.id !== null
    ? `User #${event.actor.id}`
    : 'Unknown actor'
}

export function auditTime(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Time unavailable'
    : new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: 'UTC',
      }).format(date) + ' UTC'
}

const fields: Record<string, string> = {
  name: 'Name',
  owner_user_id: 'Owner user ID',
  owner_membership_id: 'Owner membership ID',
  permissions: 'Permissions',
  state: 'State',
  expires_at: 'Expires at',
  role_ids: 'Role IDs',
  replacement_invitation_id: 'Replacement invitation ID',
  reason: 'Reason',
  membership_id: 'Membership ID',
  user_id: 'User ID',
  status: 'Status',
}

function snapshotValue(value: unknown, field: string): string {
  if (value === undefined || value === null) return '—'
  if (typeof value === 'number') return String(value)
  if (typeof value === 'string')
    return field === 'expires_at' ? auditTime(value) : value
  if (Array.isArray(value) && value.every((item) => typeof item === 'string'))
    return value.join(', ') || 'None'
  return 'Details unavailable'
}

export function auditChanges(event: AuditEvent) {
  if (event.payload_version !== 1) return []
  return Object.entries(fields)
    .filter(
      ([key]) =>
        Object.hasOwn(event.changes.before ?? {}, key) ||
        Object.hasOwn(event.changes.after ?? {}, key),
    )
    .map(([key, label]) => ({
      key,
      label,
      before: snapshotValue(event.changes.before?.[key], key),
      after: snapshotValue(event.changes.after?.[key], key),
    }))
}
