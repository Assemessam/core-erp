import { expect, type Page } from '@playwright/test'

const password = 'Correct Horse 123'
export async function mail(page: Page, email: string, subject: string) {
  let messageId: string | undefined
  await expect
    .poll(async () => {
      const response = await page.request.get(
        'http://127.0.0.1:8026/api/v1/messages',
      )
      const data = (await response.json()) as {
        messages: { ID: string; Subject: string; To: { Address: string }[] }[]
      }
      messageId = data.messages.find(
        (message) =>
          message.Subject === subject &&
          message.To.some((recipient) => recipient.Address === email),
      )?.ID
      return messageId
    })
    .toBeTruthy()
  return (await (
    await page.request.get(`http://127.0.0.1:8026/api/v1/message/${messageId}`)
  ).json()) as { Text: string; HTML: string }
}
export async function register(page: Page, email: string, name: string) {
  await page.getByLabel('Name', { exact: true }).fill(name)
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByLabel('Confirm password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/verify-email$/)
}
export async function verify(page: Page, email: string) {
  const message = await mail(page, email, 'Verify your email address')
  const url = message.Text.split('\n')
    .find((line) => line.startsWith('Verify Email Address: '))!
    .replace('Verify Email Address: ', '')
    .trim()
  // Preserve the invitation's memory in the original tab through verification.
  const verification = await page.context().newPage()
  await verification.goto(url)
  await expect(verification).toHaveURL(/verified=1/)
  await verification.close()
  await page.getByRole('button', { name: "I've verified" }).click()
}
