import axios from 'axios'

const apiOrigin = import.meta.env.VITE_API_ORIGIN?.replace(/\/$/, '') ?? ''

export const http = axios.create({
  baseURL: apiOrigin,
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export const api = axios.create({
  baseURL: `${apiOrigin}/api/v1`,
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})
