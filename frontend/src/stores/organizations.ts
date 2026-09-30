import { defineStore } from 'pinia'
import { ref } from 'vue'
import { organizationApi, type Organization } from '../lib/organizations'

export const useOrganizationStore = defineStore('organizations', () => {
  const organizations = ref<Organization[]>([])
  const current = ref<Organization | null>(null)
  const loading = ref(false)

  async function load(): Promise<void> {
    loading.value = true
    organizations.value = []
    current.value = null
    try {
      organizations.value = await organizationApi.list()
    } finally {
      loading.value = false
    }
  }

  async function create(name: string): Promise<Organization> {
    const organization = await organizationApi.create(name)
    organizations.value = [...organizations.value, organization]
    return organization
  }

  async function select(id: string): Promise<void> {
    loading.value = true
    current.value = null
    try {
      current.value = await organizationApi.get(id)
    } finally {
      loading.value = false
    }
  }

  function clear(): void {
    organizations.value = []
    current.value = null
  }

  return { organizations, current, loading, load, create, select, clear }
})
