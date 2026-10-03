import { test, expect } from '@playwright/test'
import { mail, register, verify } from './support/invitations'

test('invited user registers verifies accepts and follows membership lifecycle', async ({
  page,
  browser,
}) => {
  const unique = Date.now()
  const ownerEmail = `users-owner-${unique}@example.test`
  const memberEmail = `users-member-${unique}@example.test`
  await page.goto('/register')
  await register(page, ownerEmail, 'Users Owner')
  await verify(page, ownerEmail)
  await expect(page).toHaveURL(/\/app\/organizations$/)
  await page.getByLabel('Organization name').fill('Lifecycle Workspace')
  await page.getByRole('button', { name: 'Create organization' }).click()
  await expect(
    page.getByRole('heading', { name: 'Lifecycle Workspace' }),
  ).toBeVisible()
  const workspace = page.url()
  await page.getByRole('link', { name: 'Roles & Permissions' }).click()
  await page.getByLabel('Role name').fill('Member Reader')
  await page.getByLabel('View organization members').check()
  await page.getByLabel('View organization audit history').check()
  await page.getByRole('button', { name: 'Save role' }).click()
  await expect(
    page.getByRole('heading', { name: 'Member Reader' }),
  ).toBeVisible()
  await page.goto(`${workspace}/users`)
  await page.getByLabel('Invite email').fill(memberEmail)
  await page
    .getByRole('group', { name: 'Invitation roles' })
    .getByLabel('Member Reader')
    .check()
  await page.getByRole('button', { name: 'Send invitation' }).click()
  await expect(
    page.getByText('Invitation sent.', { exact: true }),
  ).toBeVisible()
  const invitation = await mail(page, memberEmail, 'Organization invitation')
  const invitationUrl = invitation.HTML.match(
    /href="([^"]+\/invitations\/[^"]+)"/,
  )?.[1]?.replaceAll('&amp;', '&')
  expect(invitationUrl).toBeTruthy()
  const context = await browser.newContext()
  try {
    const memberPage = await context.newPage()
    await memberPage.goto(invitationUrl!)
    await expect(
      memberPage.getByRole('heading', { name: 'Accept invitation' }),
    ).toBeVisible()
    await expect(memberPage).not.toHaveURL(/token=/)
    await memberPage.getByRole('link', { name: 'Create account' }).click()
    await register(memberPage, memberEmail, 'Invited Member')
    await verify(memberPage, memberEmail)
    await expect(memberPage).toHaveURL(/\/invitations\/[^/]+\/accept$/)
    await memberPage
      .getByRole('button', { name: 'Accept invitation', exact: true })
      .click()
    await expect(memberPage).toHaveURL(workspace)
    await memberPage.reload()
    await expect(
      memberPage.getByRole('heading', { name: 'Lifecycle Workspace' }),
    ).toBeVisible()
    await memberPage
      .getByRole('link', { name: 'Audit Trail', exact: true })
      .click()
    await expect(
      memberPage.getByRole('list', { name: 'Audit events' }),
    ).toContainText('Invitation accepted')
    await expect(
      memberPage.getByRole('list', { name: 'Audit events' }),
    ).not.toContainText(memberEmail)
    // Revoke through existing role administration, then observe fresh capability and direct API denial.
    await page.goto(`${workspace}/roles`)
    await page
      .getByRole('button', { name: 'Edit Member Reader', exact: true })
      .click()
    await page.getByLabel('View organization audit history').uncheck()
    await page.getByRole('button', { name: 'Save role' }).click()
    await expect(page.getByText('Role saved.', { exact: true })).toBeVisible()
    await memberPage
      .getByRole('button', { name: 'Refresh', exact: true })
      .click()
    await expect(memberPage.getByRole('alert')).toHaveText(
      'You do not have permission to view this audit trail.',
    )
    await expect(
      memberPage.getByRole('list', { name: 'Audit events' }),
    ).toHaveCount(0)
    const organizationId = new URL(workspace).pathname.split('/').at(-1)!
    expect(
      await memberPage.evaluate(
        async (id) =>
          (
            await fetch(`/api/v1/organizations/${id}/audit-events`, {
              credentials: 'include',
              headers: { Accept: 'application/json' },
            })
          ).status,
        organizationId,
      ),
    ).toBe(403)
    await memberPage.goto(workspace)
    await expect(
      memberPage.getByRole('heading', { name: 'Lifecycle Workspace' }),
    ).toBeVisible()
    await expect(
      memberPage.getByRole('link', { name: 'Audit Trail', exact: true }),
    ).toHaveCount(0)
    await page
      .getByRole('button', { name: 'Edit Member Reader', exact: true })
      .click()
    await page.getByLabel('View organization audit history').check()
    await page.getByRole('button', { name: 'Save role' }).click()
    await expect(page.getByText('Role saved.', { exact: true })).toBeVisible()
    await page.goto(`${workspace}/users`)
    await memberPage.reload()
    await memberPage.getByRole('link', { name: 'Users', exact: true }).click()
    await expect(
      memberPage
        .getByRole('list', { name: 'Organization members' })
        .getByText(memberEmail),
    ).toBeVisible()
    await expect(
      memberPage.getByRole('button', { name: 'Suspend' }),
    ).toHaveCount(0)
    await page.reload()
    const row = page
      .getByRole('list', { name: 'Organization members' })
      .getByRole('listitem')
      .filter({ hasText: memberEmail })
    await row.getByRole('button', { name: 'Suspend', exact: true }).click()
    await expect(
      page.getByText('Member suspended.', { exact: true }),
    ).toBeVisible()
    await memberPage.goto(`${workspace}/audit`)
    await expect(memberPage.getByRole('alert')).toHaveText(
      'This organization could not be found or accessed.',
    )
    await expect(
      memberPage.getByRole('list', { name: 'Audit events' }),
    ).toHaveCount(0)
    await memberPage.goto(workspace)
    await expect(
      memberPage.getByRole('heading', { name: 'Organization unavailable' }),
    ).toBeVisible()
    await row.getByRole('button', { name: 'Reactivate' }).click()
    await expect(
      page.getByText('Member reactivated.', { exact: true }),
    ).toBeVisible()
    await memberPage.goto(`${workspace}/audit`)
    await expect(
      memberPage.getByRole('list', { name: 'Audit events' }),
    ).toContainText('Member reactivated')
    await memberPage.goto(`${workspace}/users`)
    await expect(
      memberPage
        .getByRole('list', { name: 'Organization members' })
        .getByText(memberEmail),
    ).toBeVisible()
    page.once('dialog', (dialog) => dialog.accept())
    await row.getByRole('button', { name: 'Remove', exact: true }).click()
    await expect(
      page.getByText('Member removed.', { exact: true }),
    ).toBeVisible()
    await memberPage.goto(`${workspace}/audit`)
    await expect(memberPage.getByRole('alert')).toHaveText(
      'This organization could not be found or accessed.',
    )
    await expect(
      memberPage.getByRole('list', { name: 'Audit events' }),
    ).toHaveCount(0)
    await memberPage.goto(workspace)
    await expect(
      memberPage.getByRole('heading', { name: 'Organization unavailable' }),
    ).toBeVisible()
    await memberPage.goto('/app/organizations')
    await expect(
      memberPage.getByRole('heading', {
        name: 'Create your first organization',
      }),
    ).toBeVisible()
  } finally {
    await context.close()
  }
})
