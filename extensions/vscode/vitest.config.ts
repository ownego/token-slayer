import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/__tests__/**/*.test.ts'],
    alias: {
      vscode: resolve(__dirname, 'src/__tests__/stubs/vscode.ts'),
    },
  },
});
