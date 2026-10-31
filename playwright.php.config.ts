import path from "node:path";
import { defineConfig, devices } from "@playwright/test";

/**
 * Mêmes tests navigateur que la version Next.js, joués contre la version
 * PHP / MySQL (dossier php/). Nécessite la base MySQL de test (voir php/README.md).
 */
const PORT = 3301;
const root = process.cwd();

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
    command: `php php/tests/reset-db.php && php -S localhost:${PORT} -t php/public_html php/dev-router.php`,
    url: `http://localhost:${PORT}/api/health.php`,
    timeout: 30_000,
    reuseExistingServer: false,
    stdout: "ignore",
    stderr: "ignore",
    env: {
      BWC_CONFIG_FILE: path.join(root, "php/tests/config.test.php"),
      BWC_STORAGE_DIR: path.join(root, ".data/php-e2e"),
      BWC_TEST_URL: `http://localhost:${PORT}`,
      PHP_CLI_SERVER_WORKERS: "4",
    },
  },
});
