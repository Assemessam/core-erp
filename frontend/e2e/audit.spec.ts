import { test, expect, type Page } from '@playwright/test'

async function register(page: Page, label: string) {
  const email = `audit-${label.toLowerCase()}-${Date.now()}@example.test`
  await page.goto('/register')
  await page.getByLabel('Name', { exact: true }).fill(label)
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Correct Horse 123')
  await page.getByLabel('Confirm password').fill('Correct Horse 123')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/verify-email$/)
  return email
}
async function verify(page: Page, email: string) {
  let messageId: string | undefined
  await expect
    .poll(async () => {
      const response = await page.request.get(
        'http://127.0.0.1:8026/api/v1/messages',
      )
      const data = (await response.json()) as {
        messages: { ID: string; To: { Address: string }[] }[]
      }
      messageId = data.messages.find((m) =>
        m.To.some((r) => r.Address === email),
      )?.ID
      return messageId
    })
    .toBeTruthy()
  const message = (await (
    await page.request.get(`http://127.0.0.1:8026/api/v1/message/${messageId}`)
  ).json()) as { Text: string }
  const url = message.Text.split('\n')
    .find((line) => line.startsWith('Verify Email Address: '))!
    .replace('Verify Email Address: ', '')
    .trim()
  await page.goto(url)
  await expect(page).toHaveURL(/verified=1/)
}

test('owner browses filters paginates refreshes and isolates audit history', async ({
  page,
  browser,
}) => {
  await verify(page, await register(page, 'HistoryOwner'))
  await page.getByLabel('Organization name').fill('Audit Workspace A')
  await page.getByRole('button', { name: 'Create organization' }).click()
  await expect(
    page.getByRole('heading', { name: 'Audit Workspace A' }),
  ).toBeVisible()
  const workspace = page.url()
  const organizationId = new URL(workspace).pathname.split('/').at(-1)!
  const auditUrl = `${workspace}/audit`
  // Real authorized HTTP commands generate enough facts for two pages; no raw audit fixtures.
  const statuses = await page.evaluate(async (id) => {
    const xsrf =
      document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length) ?? ''
    const result: number[] = []
    for (let index = 1; index <= 29; index++) {
      result.push(
        (
          await fetch(`/api/v1/organizations/${id}`, {
            method: 'PATCH',
            credentials: 'include',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-XSRF-TOKEN': decodeURIComponent(xsrf),
            },
            body: JSON.stringify({ name: `Audit Workspace A ${index}` }),
          })
        ).status,
      )
    }
    return result
  }, organizationId)
  expect(statuses).toEqual(Array(29).fill(200))
  await page.getByRole('link', { name: 'Audit Trail', exact: true }).click()
  await expect(page).toHaveURL(auditUrl)
  const history = page.getByRole('list', { name: 'Audit events' })
  await expect(history.getByRole('listitem')).toHaveCount(25)
  const first = history.getByRole('listitem').first()
  await expect(first.getByRole('heading')).toHaveText('Organization renamed')
  await first.getByText('View changes', { exact: true }).click()
  await expect(
    first.getByRole('cell', { name: 'Audit Workspace A 29', exact: true }),
  ).toBeVisible()
  await expect(
    first.getByRole('cell', { name: 'Audit Workspace A 28', exact: true }),
  ).toBeVisible()
  await page.setViewportSize({ width: 1280, height: 1000 })
  await page.screenshot({
    path: '/tmp/coreerp-playwright-results/audit-trail.png',
  })
  await page.setViewportSize({ width: 390, height: 844 })
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true)
  await page.screenshot({
    path: '/tmp/coreerp-playwright-results/audit-trail-mobile.png',
  })
  await page.setViewportSize({ width: 1280, height: 720 })
  await page.getByRole('button', { name: 'Load more' }).click()
  await expect(history.getByRole('listitem')).toHaveCount(30)
  const ids = await history
    .getByRole('listitem')
    .evaluateAll((rows) => rows.map((row) => row.getAttribute('data-event-id')))
  expect(new Set(ids).size).toBe(30)
  await expect(
    page.getByText('End of audit history.', { exact: true }),
  ).toBeVisible()
  await page
    .getByLabel('Action', { exact: true })
    .selectOption('organization.created')
  await page.getByRole('button', { name: 'Apply filters' }).click()
  await expect(history.getByRole('listitem')).toHaveCount(1)
  await expect(history.getByRole('heading')).toHaveText('Organization created')
  await page
    .getByLabel('Subject type', { exact: true })
    .selectOption('organization')
  await page.getByLabel('Subject ID', { exact: true }).fill(organizationId)
  await page.getByRole('button', { name: 'Apply filters' }).click()
  await expect(history.getByRole('listitem')).toHaveCount(1)
  await page.getByRole('button', { name: 'Refresh', exact: true }).click()
  await expect(history.getByRole('listitem')).toHaveCount(1)
  await page.reload()
  await expect(history.getByRole('listitem')).toHaveCount(25)
  await page.getByRole('link', { name: 'Organization workspace' }).click()
  await page.getByRole('link', { name: '← Organizations', exact: true }).click()
  await page.getByLabel('Organization name').fill('Audit Workspace B')
  await page.getByRole('button', { name: 'Create organization' }).click()
  await expect(
    page.getByRole('heading', { name: 'Audit Workspace B' }),
  ).toBeVisible()
  await page.getByRole('link', { name: 'Audit Trail', exact: true }).click()
  await expect(history.getByRole('listitem')).toHaveCount(1)
  await history.getByText('View changes', { exact: true }).click()
  await expect(
    history.getByRole('cell', { name: 'Audit Workspace B', exact: true }),
  ).toBeVisible()
  await expect(history).not.toContainText('Audit Workspace A')
  await page
    .getByLabel('Subject type', { exact: true })
    .selectOption('organization')
  await page.getByLabel('Subject ID', { exact: true }).fill(organizationId)
  await page.getByRole('button', { name: 'Apply filters' }).click()
  await expect(
    page.getByText('No audit events match these filters.', { exact: true }),
  ).toBeVisible()
  await page.getByRole('button', { name: 'Clear filters' }).click()
  await expect(history.getByRole('listitem')).toHaveCount(1)
  const context = await browser.newContext()
  try {
    const outsider = await context.newPage()
    await outsider.goto(auditUrl)
    await expect(outsider).toHaveURL(/\/login\?redirect=/)
    const email = await register(outsider, 'HistoryOutsider')
    await outsider.goto(auditUrl)
    await expect(outsider).toHaveURL(/\/verify-email$/)
    await verify(outsider, email)
    await outsider.goto(auditUrl)
    await expect(outsider.getByRole('alert')).toHaveText(
      'This organization could not be found or accessed.',
    )
    await expect(
      outsider.getByRole('list', { name: 'Audit events' }),
    ).toHaveCount(0)
    const status = await outsider.evaluate(
      async (id) =>
        (
          await fetch(`/api/v1/organizations/${id}/audit-events`, {
            credentials: 'include',
            headers: { Accept: 'application/json' },
          })
        ).status,
      organizationId,
    )
    expect(status).toBe(404)
  } finally {
    await context.close()
  }
})
