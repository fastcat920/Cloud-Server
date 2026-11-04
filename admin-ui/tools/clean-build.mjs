import { rm } from 'node:fs/promises'
import path from 'node:path'

await rm(path.resolve(import.meta.dirname, '../../public/assets/admin-app'), { recursive: true, force: true })
