import { defineConfig, devices } from "@playwright/test";

const PORT = 3200;

export default defineConfig({
  testDir: "tests/e2e",
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  use: {
    baseURL: `http://localhost:${PORT}`,
    locale: "fr-BE",
    timezoneId: "Europe/Brussels",
    trace: "retain-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], viewport: { width: 1360, height: 900 } } },
    { name: "mobile", use: { ...devices["Pixel 7"] } },
  ],
  webServer: {
    // Production build in demo mode with a fresh, throw-away agenda.
    command: `rm -rf .data/e2e && npx next build && npx next start --port ${PORT}`,
    url: `http://localhost:${PORT}/api/health`,
    timeout: 240_000,
    reuseExistingServer: false,
    env: {
      DEMO_MODE: "true",
      DEMO_DATA_DIR: ".data/e2e",
      APP_URL: `http://localhost:${PORT}`,
    },
  },
});
