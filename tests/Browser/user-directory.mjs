import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import path from "node:path";
import process from "node:process";
import { after, before, test } from "node:test";
import { fileURLToPath } from "node:url";
import puppeteer from "puppeteer";
import { createServer } from "vite";

const root = fileURLToPath(new URL("../../", import.meta.url));
const executablePath =
    process.env.PUPPETEER_EXECUTABLE_PATH ??
    ["/usr/bin/chromium", "/usr/bin/chromium-browser", "/usr/bin/google-chrome", puppeteer.executablePath()].find(existsSync);
let server, browser, baseUrl;
before(
    async () => {
        assert.ok(executablePath, "Installed Chromium required.");
        server = await createServer({
            configFile: false,
            root,
            appType: "custom",
            plugins: [tailwindcss(), react()],
            resolve: {
                alias: [
                    { find: "@/components/administrators/admin-layout", replacement: path.join(root, "tests/Fixtures/user-directory-layout.tsx") },
                    { find: "@", replacement: path.join(root, "resources/js") },
                ],
                dedupe: ["react", "react-dom"],
            },
            server: { host: "127.0.0.1", port: 0 },
        });
        server.middlewares.use(async (request, response, next) => {
            if (!request.url?.startsWith("/user-directory-fixture")) return next();
            response.setHeader("Content-Type", "text/html");
            response.end(
                await server.transformIndexHtml(
                    request.url,
                    '<!doctype html><html><head><title>User directory</title><meta name="viewport" content="width=device-width, initial-scale=1" /></head><body><div id="root"></div><script type="module" src="/tests/Fixtures/user-directory.tsx"></script></body></html>',
                ),
            );
        });
        await server.listen();
        baseUrl = `http://127.0.0.1:${server.httpServer.address().port}`;
        browser = await puppeteer.launch({ executablePath, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
    },
    { timeout: 120_000 },
);
after(async () => {
    await browser?.close();
    await server?.close();
});

async function directory(run, width = 1440) {
    const page = await browser.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.setViewport({ width, height: 1050 });
    try {
        await page.goto(`${baseUrl}/user-directory-fixture`, { waitUntil: "networkidle0" });
        await page.waitForSelector("#users-search", { timeout: 15000 }).catch(() => {
            throw new Error(JSON.stringify(errors));
        });
        await run(page);
        if (process.env.DIRECTORY_SCREENSHOT_DIR) {
            await page.screenshot({ path: path.join(process.env.DIRECTORY_SCREENSHOT_DIR, `directory-${width}.png`), fullPage: true });
        }
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}
test("directory exposes account details, access actions and review queues", { timeout: 120000 }, async () =>
    directory(async (page) => {
        assert.ok(await page.$('a[href$="/users/1"]'));
        await page.click('[aria-label="Actions for Mika Santos"]');
        await page.waitForSelector('[role="menu"]');
        const button = await page.waitForFunction(() =>
            [...document.querySelectorAll('[role="menuitem"]')].find((el) => el.textContent.includes("Suspend account")),
        );
        await button.click();
        await page.waitForSelector('[role="alertdialog"]');
        assert.equal(await page.$eval('[role="alertdialog"] button:last-child', (el) => el.disabled), true);
        await page.keyboard.press("Escape");
        await page.waitForSelector('[role="alertdialog"]', { hidden: true });
        const suspended = await page.waitForFunction(() =>
            [...document.querySelectorAll('nav[aria-label="Directory views"] button')].find((el) => el.textContent.startsWith("Suspended")),
        );
        await suspended.evaluate((el) => el.click());
        await page.waitForFunction(() => !document.querySelector("tbody").textContent.includes("Mika Santos"));
        assert.match(await page.$eval("tbody", (el) => el.textContent), /Arun Reyes/);
    }),
);
test("mobile directory contains wide table without page overflow and supports empty search", { timeout: 120000 }, async () =>
    directory(async (page) => {
        await page.evaluate(() => document.documentElement.classList.add("dark"));
        await page.type("#users-search", "no-matching-account");
        await page.waitForFunction(() => document.querySelector("tbody").textContent.includes("No users found"));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    }, 390),
);
