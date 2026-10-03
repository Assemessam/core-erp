import { test, expect } from '@playwright/test'
import { mail, register, verify } from './support/invitations'

test('owner reads the real invitation acceptance notification privately in the current organization', async ({
  page,
  browser,
}) => {
  const unique = Date.now()
  const ownerEmail = `notification-owner-${unique}@example.test`
  const inviteeEmail = `notification-invitee-${unique}@example.test`
  await page.goto('/register')
  await register(page, ownerEmail, 'Notification Owner')
  await verify(page, ownerEmail)
  await page.getByLabel('Organization name').fill('Notification Workspace A')
  await page.getByRole('button', { name: 'Create organization' }).click()
  await expect(
    page.getByRole('heading', { name: 'Notification Workspace A' }),
  ).toBeVisible()
  const workspace = page.url()
  const organizationId = new URL(workspace).pathname.split('/').at(-1)!
  await expect(
    page.getByRole('button', { name: 'Notifications, 0 unread', exact: true }),
  ).toBeVisible()
  await expect(
    page
      .getByRole('navigation', { name: 'Organization workspace' })
      .getByRole('link', { name: 'Notifications', exact: true }),
  ).toHaveAttribute('href', `${new URL(workspace).pathname}/notifications`)
  await page.getByRole('link', { name: 'Users', exact: true }).click()
  await page.getByLabel('Invite email').fill(inviteeEmail)
  await page.getByRole('button', { name: 'Send invitation' }).click()
  await expect(
    page.getByText('Invitation sent.', { exact: true }),
  ).toBeVisible()
  const invitation = await mail(page, inviteeEmail, 'Organization invitation')
  const invitationUrl = invitation.HTML.match(
    /href="([^"]+\/invitations\/[^"]+)"/,
  )?.[1]?.replaceAll('&amp;', '&')
  expect(Boolean(invitationUrl)).toBe(true)
  const token = new URL(invitationUrl!).hash.slice('#token='.length)
  const context = await browser.newContext()
  try {
    const invitee = await context.newPage()
    await invitee.goto(invitationUrl!)
    await invitee.getByRole('link', { name: 'Create account' }).click()
    await register(invitee, inviteeEmail, 'Notification Invitee')
    await verify(invitee, inviteeEmail)
    await invitee
      .getByRole('button', { name: 'Accept invitation', exact: true })
      .click()
    await expect(invitee).toHaveURL(workspace)
    const userId = await invitee.evaluate(
      async () =>
        (
          await (
            await fetch('/api/v1/me', {
              headers: { Accept: 'application/json' },
            })
          ).json()
        ).data.id as number,
    )
    await page.bringToFront()
    await page.evaluate(() => window.dispatchEvent(new Event('focus')))
    await expect(
      page.getByRole('button', {
        name: 'Notifications, 1 unread',
        exact: true,
      }),
    ).toBeVisible()
    const listResponse = page.waitForResponse(
      (response) =>
        response
          .url()
          .split('?')[0]!
          .endsWith(`/organizations/${organizationId}/notifications`) &&
        response.request().method() === 'GET',
    )
    await page
      .getByRole('button', { name: 'Notifications, 1 unread', exact: true })
      .focus()
    await page.keyboard.press('Space')
    const resource = (await (await listResponse).json()).data[0]
    expect(Object.keys(resource).sort()).toEqual(
      [
        'id',
        'type',
        'payload_version',
        'title',
        'body',
        'target',
        'read_at',
        'created_at',
      ].sort(),
    )
    const row = page
      .getByRole('list', { name: 'Notifications', exact: true })
      .getByRole('article')
    await expect(
      row.getByRole('heading', { name: 'Invitation accepted' }),
    ).toBeVisible()
    await expect(row).toContainText(
      `User #${userId} accepted an invitation and joined the organization.`,
    )
    await expect(row.getByText('Unread', { exact: true })).toBeVisible()
    const text = await row.textContent()
    for (const sensitive of [
      token,
      ownerEmail,
      inviteeEmail,
      invitationUrl!,
      'recipient_membership_id',
      '"payload"',
    ])
      expect(Boolean(text?.includes(sensitive))).toBe(false)
    const privateResult = await invitee.evaluate(
      async ({ organizationId, notificationId }) => {
        const base = `/api/v1/organizations/${organizationId}/notifications`
        const headers = { Accept: 'application/json' }
        const rows = (await (await fetch(base, { headers })).json()).data
        const count = (
          await (await fetch(`${base}/unread-count`, { headers })).json()
        ).data.unread_count
        const csrf = document.cookie
          .split('; ')
          .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
          ?.slice('XSRF-TOKEN='.length)
        const response = await fetch(`${base}/${notificationId}/read`, {
          method: 'POST',
          headers: {
            ...headers,
            'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
          },
        })
        return { rows, count, status: response.status }
      },
      { organizationId, notificationId: resource.id },
    )
    expect(privateResult).toEqual({ rows: [], count: 0, status: 404 })
    await row.getByRole('button', { name: 'Mark as read', exact: true }).click()
    await expect(row.getByText('Read', { exact: true })).toBeVisible()
    await expect(
      page.getByRole('button', {
        name: 'Notifications, 0 unread',
        exact: true,
      }),
    ).toBeVisible()
    await page.reload()
    await expect(row.getByText('Read', { exact: true })).toBeVisible()
    await expect(
      row.getByRole('button', { name: 'Mark as read', exact: true }),
    ).toHaveCount(0)
    // Review the actual UI at both sizes and exercise native keyboard navigation.
    await page.screenshot({
      path: '/tmp/coreerp-playwright-results/notification-desktop.png',
      fullPage: true,
    })
    await page.setViewportSize({ width: 390, height: 844 })
    await expect
      .poll(() =>
        page.evaluate(
          () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
      )
      .toBe(true)
    await page.screenshot({
      path: '/tmp/coreerp-playwright-results/notification-mobile.png',
      fullPage: true,
    })
    await page.keyboard.press('Tab')
    await expect
      .poll(() =>
        page.evaluate(() =>
          ['A', 'BUTTON'].includes(document.activeElement?.tagName ?? ''),
        ),
      )
      .toBe(true)
    await row
      .getByRole('link', { name: 'View Invitation accepted', exact: true })
      .focus()
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(`${workspace}/users`)
    await expect(
      page.getByRole('heading', { name: 'Organization Users' }),
    ).toBeVisible()
    await page.goto('/app/organizations')
    await page.getByLabel('Organization name').fill('Notification Workspace B')
    await page.getByRole('button', { name: 'Create organization' }).click()
    await expect(
      page.getByRole('heading', { name: 'Notification Workspace B' }),
    ).toBeVisible()
    await page
      .getByRole('button', { name: 'Notifications, 0 unread', exact: true })
      .click()
    await expect(
      page.getByText('No notifications yet.', { exact: true }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Invitation accepted' }),
    ).toHaveCount(0)
    await page.goto(`${workspace}/notifications`)
    await expect(row.getByText('Read', { exact: true })).toBeVisible()
  } finally {
    await context.close()
  }
})
