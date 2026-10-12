import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/**/*.test.ts'],
    globalSetup: ['tests/global-setup.ts'],
    fileParallelism: false, // all files share one test database
    env: {
      DATABASE_URL: process.env.TEST_DATABASE_URL ?? 'postgres://bms:bms@localhost:5432/bms_test',
      JWT_SECRET: 'test-secret-test-secret-test-secret-123',
      BCRYPT_COST: '4',
      NODE_ENV: 'test',
      LOGIN_ATTEMPTS_PER_15_MIN: '1000',
    },
  },
});
