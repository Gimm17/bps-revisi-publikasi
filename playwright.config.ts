import { defineConfig } from "@playwright/test";
import { resolve } from "node:path";
export default defineConfig({
    testDir: "./tests/Browser",
    testMatch: "*.spec.ts",
    workers: 1,
    use: {
        baseURL: "http://127.0.0.1:8010",
        channel: "chrome",
        trace: "retain-on-failure",
    },
    webServer: {
        command: "php artisan serve --host=127.0.0.1 --port=8010",
        url: "http://127.0.0.1:8010/login",
        reuseExistingServer: false,
        env: {
            APP_ENV: "testing",
            DB_CONNECTION: "sqlite",
            DB_DATABASE: resolve("storage/framework/testing/browser.sqlite"),
            CACHE_STORE: "array",
            SESSION_DRIVER: "file",
            QUEUE_CONNECTION: "database",
            HASH_DRIVER: "bcrypt",
            AI_ALLOWED_HOSTS: "9router.com,limitrouter.com",
        },
        timeout: 30000,
    },
});
