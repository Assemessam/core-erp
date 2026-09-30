import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import App from '../App.vue'
import { api } from '../lib/api'
import { routes } from '../router'

async function mountApp(path = '/') {
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()
  return mount(App, { global: { plugins: [createPinia(), router] } })
}

describe('application shell', () => {
  it('renders the foundation and confirms API readiness', async () => {
    const request = vi
      .spyOn(api, 'get')
      .mockResolvedValue({ data: { data: { status: 'ok' } } })
    const wrapper = await mountApp()
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('A secure start for CoreERP.')
    expect(wrapper.get('[role="status"]').text()).toBe(
      'API and data services are ready.',
    )
    expect(request).toHaveBeenCalledWith('/ready')
    wrapper.unmount()
  })

  it('shows a useful failure state and can retry', async () => {
    const request = vi
      .spyOn(api, 'get')
      .mockRejectedValueOnce(new Error('Network error'))
    const wrapper = await mountApp()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain(
      'API is unavailable.',
    )

    request.mockResolvedValueOnce({ data: { data: { status: 'ok' } } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe(
      'API and data services are ready.',
    )
    wrapper.unmount()
  })

  it('renders a not-found route without requesting the API', async () => {
    const request = vi.spyOn(api, 'get')
    const wrapper = await mountApp('/missing')
    expect(wrapper.get('h1').text()).toBe('Page not found')
    expect(request).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
