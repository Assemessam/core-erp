import { expect, test, type Page } from '@playwright/test'

async function registerAndVerify(page: Page, label: string) {
  const email = `org-${label.toLowerCase()}-${Date.now()}@example.test`
  await page.goto('/register')
  await page.getByLabel('Name').fill(label)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Correct Horse 123')
  await page.getByLabel('Confirm password').fill('Correct Horse 123')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/verify-email$/)

  let messageId: string | undefined
  await expect
    .poll(async () => {
      const response = await page.request.get(
        'http://127.0.0.1:8026/api/v1/messages',
      )
      const data = (await response.json()) as {
        messages: { ID: string; To: { Address: string }[] }[]
      }
      messageId = data.messages.find((message) =>
        message.To.some((recipient) => recipient.Address === email),
      )?.ID
      return messageId
    })
    .toBeTruthy()
  const response = await page.request.get(
    `http://127.0.0.1:8026/api/v1/message/${messageId}`,
  )
  const message = (await response.json()) as { Text: string }
  const verificationUrl = message.Text.split('\n')
    .find((line) => line.startsWith('Verify Email Address: '))
    ?.replace('Verify Email Address: ', '')
    .trim()
  expect(verificationUrl).toBeTruthy()
  await page.goto(verificationUrl!)
  await expect(page).toHaveURL(/\/app\/organizations\?verified=1$/)
  await expect(
    page.getByRole('heading', { name: 'Organizations' }),
  ).toBeVisible()
}

test('organization onboarding survives refresh and denies another user', async ({
  page,
  browser,
}) => {
  await registerAndVerify(page, 'Alice')
  await expect(
    page.getByRole('heading', { name: 'Create your first organization' }),
  ).toBeVisible()
  await page.getByLabel('Organization name').fill('Alice Works')
  await page.getByRole('button', { name: 'Create organization' }).click()
  await expect(page.getByRole('heading', { name: 'Alice Works' })).toBeVisible()
  const organizationId = new URL(page.url()).pathname.split('/').at(-1)!
  expect(organizationId).toMatch(/^[a-z0-9]{26}$/)

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Alice Works' })).toBeVisible()
  await page.goto('/app/organizations')
  await page.getByRole('link', { name: 'Alice Works' }).click()
  await expect(page.getByRole('heading', { name: 'Alice Works' })).toBeVisible()
  const ownerResponse = await page.evaluate(async (id) => {
    const response = await fetch(`/api/v1/organizations/${id}`, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return { status: response.status, body: await response.json() }
  }, organizationId)
  expect(ownerResponse).toEqual({
    status: 200,
    body: {
      data: { id: organizationId, name: 'Alice Works' },
      meta: { can_view_audit: true },
    },
  })

  const secondContext = await browser.newContext()
  try {
    const secondPage = await secondContext.newPage()
    await registerAndVerify(secondPage, 'Bob')
    await expect(
      secondPage.getByRole('heading', {
        name: 'Create your first organization',
      }),
    ).toBeVisible()
    const list = await secondPage.evaluate(async () => {
      const response = await fetch('/api/v1/organizations', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })
      return response.json()
    })
    expect(list).toEqual({ data: [] })
    await secondPage.goto(`/app/organizations/${organizationId}`)
    await expect(
      secondPage.getByRole('heading', { name: 'Organization unavailable' }),
    ).toBeVisible()
    await expect(
      secondPage.getByText('This organization could not be found or accessed.'),
    ).toBeVisible()
    const response = await secondPage.evaluate(
      async (id) =>
        fetch(`/api/v1/organizations/${id}`, {
          credentials: 'include',
          headers: { Accept: 'application/json' },
        }).then((result) => result.status),
      organizationId,
    )
    expect(response).toBe(404)
  } finally {
    await secondContext.close()
  }
})

test('owner creates and edits organization roles that persist after refresh', async ({
  page,
}) => {
  await registerAndVerify(page, 'RolesOwner')
  await page.getByLabel('Organization name').fill('Roles Workspace')
  await page.getByRole('button', { name: 'Create organization' }).click()
  await expect(
    page.getByRole('heading', { name: 'Roles Workspace' }),
  ).toBeVisible()
  const workspaceUrl = page.url()
  await page.getByRole('link', { name: 'Roles & Permissions' }).click()
  await expect(page).toHaveURL(`${workspaceUrl}/roles`)
  await page.getByLabel('Role name').fill('Organization Editor')
  await page.getByLabel('Update organization details').check()
  await page.getByRole('button', { name: 'Save role' }).click()
  await expect(
    page.getByRole('heading', { name: 'Organization Editor' }),
  ).toBeVisible()
  await page
    .getByRole('button', { name: 'Edit Organization Editor', exact: true })
    .click()
  await page.getByLabel('Role name').fill('Workspace Administrator')
  await page.getByLabel('View roles and permissions').check()
  await page.getByRole('button', { name: 'Save role' }).click()
  await expect(
    page.getByRole('heading', { name: 'Workspace Administrator' }),
  ).toBeVisible()
  await page.reload()
  await expect(
    page.getByRole('heading', { name: 'Workspace Administrator' }),
  ).toBeVisible()
  await page
    .getByRole('button', { name: 'Edit Workspace Administrator', exact: true })
    .click()
  await expect(page.getByLabel('Update organization details')).toBeChecked()
  await expect(page.getByLabel('View roles and permissions')).toBeChecked()
  await page.getByRole('link', { name: 'Organization workspace' }).click()
  await expect(page).toHaveURL(workspaceUrl)
})
