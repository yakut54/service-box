import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

// Отдельный файл, а не test-блок в vite.config.ts — чтобы не трогать
// боевой конфиг сборки ради тестов (см. memory о тестовом окружении).
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test-setup.ts'],
    },
  })
)
