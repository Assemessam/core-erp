import {
  inject,
  onBeforeUnmount,
  readonly,
  ref,
  watch,
  type InjectionKey,
  type Ref,
} from 'vue'
import { notificationAccessLost, notificationApi } from '../lib/notifications'
import { formFeedback } from '../lib/httpError'

/** One mounted application shell; no module singleton, row cache or browser storage. */
export function useOrganizationNotifications(context: Ref<string | null>) {
  const organizationId = ref<string | null>(null)
  const unreadCount = ref<number | null>(null)
  const countLoading = ref(false)
  const countError = ref('')
  const accessError = ref('')
  const epoch = ref(0)
  let request = 0
  let controller: AbortController | null = null
  let timer: ReturnType<typeof setInterval> | undefined

  function stop() {
    clearInterval(timer)
    timer = undefined
    window.removeEventListener('focus', visibleRefresh)
    document.removeEventListener('visibilitychange', visibleRefresh)
    controller?.abort()
    controller = null
    request++
    countLoading.value = false
  }

  function reportAccessLoss(error: unknown, expectedEpoch: number) {
    if (expectedEpoch !== epoch.value || !notificationAccessLost(error)) return
    stop()
    unreadCount.value = null
    countError.value = ''
    accessError.value = formFeedback(error).message
    epoch.value++ // Synchronously clears page-local content and invalidates pending mutations too.
  }

  async function refreshCount(supersede = false) {
    if (
      !organizationId.value ||
      accessError.value ||
      (countLoading.value && !supersede)
    )
      return
    controller?.abort()
    controller = new AbortController()
    const current = ++request
    const scope = epoch.value
    const id = organizationId.value
    countLoading.value = true
    countError.value = ''
    try {
      const count = await notificationApi.unreadCount(id, controller.signal)
      if (current !== request || scope !== epoch.value) return
      unreadCount.value = count
    } catch (error) {
      if (current !== request || scope !== epoch.value) return
      if (notificationAccessLost(error)) reportAccessLoss(error, scope)
      else countError.value = 'Unread count could not be refreshed. Try again.'
    } finally {
      if (current === request && scope === epoch.value)
        countLoading.value = false
    }
  }

  function visibleRefresh() {
    if (!document.hidden) void refreshCount()
  }

  function reset(id: string | null) {
    stop()
    organizationId.value = id
    unreadCount.value = null
    countError.value = ''
    accessError.value = ''
    epoch.value++
    if (!id) return
    window.addEventListener('focus', visibleRefresh)
    document.addEventListener('visibilitychange', visibleRefresh)
    timer = setInterval(visibleRefresh, 60_000)
    visibleRefresh()
  }

  watch(context, reset, { immediate: true, flush: 'sync' })
  onBeforeUnmount(() => {
    stop()
    organizationId.value = null
    unreadCount.value = null
    epoch.value++
  })
  return {
    organizationId: readonly(organizationId),
    unreadCount: readonly(unreadCount),
    countLoading: readonly(countLoading),
    countError: readonly(countError),
    accessError: readonly(accessError),
    epoch: readonly(epoch),
    refreshCount,
    reportAccessLoss,
    retry: () => reset(context.value),
  }
}

export const notificationScopeKey: InjectionKey<
  ReturnType<typeof useOrganizationNotifications>
> = Symbol('organization-notifications')

export function useNotificationScope() {
  const scope = inject(notificationScopeKey)
  if (!scope)
    throw new Error('Notifications require the organization application shell.')
  return scope
}
