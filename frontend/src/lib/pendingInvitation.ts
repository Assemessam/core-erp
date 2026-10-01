// Memory only. Reloading after URL cleanup requires reopening the invitation email.
let pending: { id: string; token: string } | null = null
export const pendingInvitation = {
  capture(id: string, hash: string) {
    const token = new URLSearchParams(hash.replace(/^#/, '')).get('token')
    if (token && /^[a-f0-9]{64}$/.test(token)) pending = { id, token }
  },
  token(id: string) {
    return pending?.id === id ? pending.token : null
  },
  path() {
    return pending
      ? `/invitations/${encodeURIComponent(pending.id)}/accept`
      : null
  },
  clear() {
    pending = null
  },
}
