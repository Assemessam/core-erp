import { expect, test } from '@playwright/test'

test('first-party session survives refresh and ends on logout', async ({
  page,
  context,
}) => {
  const email = `e2e-${Date.now()}@example.test`
  const password = 'Correct Horse 123'

  await page.goto('/register')
  await page.getByLabel('Name').fill('E2E Example')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByLabel('Confirm password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page).toHaveURL(/\/verify-email$/)
  await expect(page.getByText(email)).toBeVisible()

  const me = await page.evaluate(async () => {
    const response = await fetch('/api/v1/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return { status: response.status, body: await response.json() }
  })
  expect(me.status).toBe(200)
  expect(me.body).toEqual({
    data: {
      id: expect.any(Number),
      name: 'E2E Example',
      email,
      email_verified: false,
    },
  })

  const sessionCookie = (await context.cookies()).find(
    (cookie) => cookie.name === 'coreerp-session',
  )
  expect(sessionCookie?.httpOnly).toBe(true)

  // The browser's same-origin Fetch Metadata is itself accepted by Laravel 13.
  // APIRequestContext shares cookies but omits that signal and any XSRF header.
  const rejectedWithoutCsrf = await context.request.post('/logout', {
    headers: { Accept: 'application/json' },
  })
  expect(rejectedWithoutCsrf.status()).toBe(419)

  await page.reload()
  await expect(
    page.getByRole('heading', { name: 'Verify your email' }),
  ).toBeVisible()
  await page.goto('/app')
  await expect(page).toHaveURL(/\/verify-email$/)

  const mailpit = 'http://127.0.0.1:8026/api/v1'
  let messageId: string | undefined
  await expect
    .poll(async () => {
      const response = await page.request.get(`${mailpit}/messages`)
      const data = (await response.json()) as {
        messages: { ID: string; To: { Address: string }[] }[]
      }
      messageId = data.messages.find((message) =>
        message.To.some((recipient) => recipient.Address === email),
      )?.ID
      return messageId
    })
    .toBeTruthy()
  const messageResponse = await page.request.get(
    `${mailpit}/message/${messageId}`,
  )
  const message = (await messageResponse.json()) as { Text: string }
  const verificationUrl = message.Text.split('\n')
    .find((line) => line.startsWith('Verify Email Address: '))
    ?.replace('Verify Email Address: ', '')
    .trim()
  expect(verificationUrl).toMatch(/^http:\/\/localhost:8088\/email\/verify\//)
  await page.goto(verificationUrl!)
  await expect(page).toHaveURL(/\/app\/organizations\?verified=1$/)
  await expect(
    page.getByRole('heading', { name: 'Organizations' }),
  ).toBeVisible()
  await page.reload()
  await expect(
    page.getByRole('heading', { name: 'Organizations' }),
  ).toBeVisible()

  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/login$/)

  const afterLogout = await page.evaluate(async () => {
    const response = await fetch('/api/v1/me', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return response.status
  })
  expect(afterLogout).toBe(401)
})
