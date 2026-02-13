import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  server: {
    port: 3001,
  },
  build: {
    lib: {
      entry: resolve(__dirname, 'src/main.ts'),
      name: 'ServiceBoxWidget',
      formats: ['iife'],
      fileName: () => 'widget.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'widget[extname]',
      },
    },
    cssCodeSplit: false,
  },
})
