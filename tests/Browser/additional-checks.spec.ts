import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";

test.beforeAll(() => {
    execFileSync("php", ["tests/Browser/prepare.php"], { timeout: 30000 });
});

test.beforeEach(async ({ page }) => {
    await page.goto("/login");
    await page.getByLabel("Email pegawai").fill("browser@example.test");
    await page
        .getByLabel("Password", { exact: true })
        .fill("BrowserTestPassword2026!");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Halo, Pemeriksa!" })
    ).toBeVisible();
});

test("supplemental checks show honest coverage and preserve existing findings", async ({
    page,
}) => {
    await page
        .getByRole("link")
        .filter({ hasText: "Publikasi Pemeriksaan Belum Lengkap" })
        .click();

    await expect(
        page.getByRole("button", { name: "Periksa tambahan dengan aturan terbaru" })
    ).toBeVisible();

    await expect(page.getByText("Cakupan gabungan")).toBeVisible();

    await expect(
        page.getByText("Volume edisi sebelumnya belum terbaca", { exact: false })
    ).toBeVisible();

    await expect(
        page.getByRole("button", { name: "Buat Excel sementara" })
    ).toBeEnabled();
});
