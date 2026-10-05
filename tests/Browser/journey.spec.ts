import { test, expect } from "@playwright/test";
import { resolve } from "node:path";
import { execFileSync } from "node:child_process";
import AxeBuilder from "@axe-core/playwright";

test("login is accessible with keyboard-labelled controls", async ({
    browser,
}) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto("http://127.0.0.1:8010/login");
    await expect(page.getByRole('heading', {name:'Masuk ke ruang kerja'})).toBeVisible();
    const results = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa"])
        .analyze();
    expect(results.violations).toEqual([]);
    await context.close();
});
test.beforeEach(async ({ page }) => {
    await page.goto("/login");
    await page.getByLabel("Email pegawai").fill("browser@example.test");
    await page
        .getByLabel("Password", { exact: true })
        .fill("BrowserTestPassword2026!");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Halo, Pemeriksa!" }),
    ).toBeVisible();
});
test("upload creates real per-page manifest and cannot pretend audit is complete", async ({
    page,
}) => {
    await page.goto("/publications/create");
    await page
        .locator("#pdf")
        .setInputFiles(resolve("storage/framework/testing/browser.pdf"));
    await page.getByRole("button", { name: "Lanjutkan" }).click();
    await page
        .getByLabel("Judul publikasi")
        .fill(`Publikasi Browser ${Date.now()}`);
    await page.getByLabel("Daerah", { exact: true }).fill("Daerah Uji");
    await page
        .getByRole("button", { name: "Simpan & validasi dokumen" })
        .click();
    await expect(
        page.getByText("0 dari 3 halaman selesai diperiksa"),
    ).toBeVisible();
    await page.getByRole("button", { name: "Mulai pemeriksaan" }).click();
    await expect(page.getByRole("alert")).toContainText("Admin perlu");
});
test("edit evidence-backed finding, see revision, regenerate and download actual Excel", async ({
    page,
}) => {
    await page
        .getByRole("link")
        .filter({ hasText: "Publikasi Pengujian Browser" })
        .click();
    await page
        .getByRole("button")
        .filter({ hasText: "Temuan pengujian antarmuka" })
        .click();
    await page
        .getByLabel("Saran revisi")
        .fill(`Catatan revisi melalui browser ${Date.now()}`);
    await page.getByLabel("Keputusan pemeriksa").selectOption("accepted");
    await page.getByRole("button", { name: "Simpan temuan" }).click();
    await expect(
        page.getByRole("status").filter({ hasText: "Perubahan tersimpan" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Lihat riwayat perubahan" }).click();
    await expect(
        page.getByRole("heading", { name: "Riwayat perubahan" }),
    ).toBeVisible();
    await page.getByRole("button", { name: /Hasil Excel/ }).click();
    await page.getByRole("button", { name: "Buat Excel versi baru" }).click();
    await expect(
        page.getByText("Versi Excel baru sedang dibuat"),
    ).toBeVisible();
    execFileSync("php", ["tests/Browser/prepare.php", "--work"], {
        timeout: 30000,
    });
    await page.reload();
    await page.getByRole("button", { name: /Hasil Excel/ }).click();
    const downloadPromise = page.waitForEvent("download");
    await page
        .locator(".export-item")
        .first()
        .getByRole("link", { name: "Unduh", exact: true })
        .click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    expect(await download.failure()).toBeNull();
});
for (const width of [1440, 1024, 768, 390])
    test(`responsive workspace ${width}px has no horizontal page overflow`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        await page
            .getByRole("link")
            .filter({ hasText: "Publikasi Pengujian Browser" })
            .click();
        if (width <= 800) {
            await page
                .getByRole("button", { name: "Temuan", exact: true })
                .click();
            await expect(
                page
                    .getByRole("button")
                    .filter({ hasText: "Temuan pengujian antarmuka" }),
            ).toBeVisible();
            await page
                .getByRole("button", { name: "Dokumen", exact: true })
                .click();
        }
        await expect(
            page.getByRole("img", { name: /Halaman PDF 1 dari/ }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBeTruthy();
        await page.screenshot({
            path: `test-results/workspace-${width}.png`,
            fullPage: true,
        });
    });
