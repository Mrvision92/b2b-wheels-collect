import { expect, test, type Page } from "@playwright/test";

let counter = 0;

async function fillGarage(page: Page) {
  counter++;
  await page.getByLabel("Nom du garage").fill(`Garage Test ${counter}`);
  await page.getByLabel("Nom et prénom").fill("Marie Dupont");
  await page.getByLabel("Téléphone").fill("0470 12 34 56");
  await page.getByLabel("Email").fill(`atelier${counter}-${Date.now()}@garage-test.be`);
  await page.getByLabel("Rue et numéro").fill(`Rue de la Station ${counter}`);
  await page.getByLabel("Code postal").fill("1000");
  await page.getByLabel("Ville").fill("Bruxelles");
}

/** Clicks the visible pill of a visually hidden radio. */
async function choose(_page: Page, radio: ReturnType<Page["locator"]>) {
  await radio.locator("xpath=..").click();
  await expect(radio).toBeChecked();
}

async function pickFirstFreeSlot(page: Page) {
  const date = page.locator('input[name="date"]:not(:disabled)').first();
  await expect(date).toBeAttached();
  await choose(page, date);
  await choose(page, page.locator('input[name="slot"]:not(:disabled)').first());
}

async function expectNoHorizontalScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

test("a garage books a pickup and sees a real confirmation", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1 })).toContainText("Vos jantes.");
  await expect(page.getByText("Mode démonstration")).toBeVisible();

  await fillGarage(page);
  await choose(page, page.getByRole("radio", { name: "4 jantes" }));
  await page.getByLabel("Commentaire").fill("Jantes avec pneus.");
  await pickFirstFreeSlot(page);

  await page.getByRole("button", { name: "Confirmer mon enlèvement" }).click();
  await expect(page.getByRole("heading", { name: "Votre enlèvement est confirmé." })).toBeVisible();
  await expect(page.getByText(/^BWC-[0-9A-Z]{4}-[0-9A-Z]{4}$/)).toBeVisible();
  await expect(page.getByText("4 jantes", { exact: true })).toBeVisible();
  await expect(page.getByText("aucun email réel n'a été envoyé")).toBeVisible();
  await expectNoHorizontalScroll(page);

  // The simulated confirmation email can be opened.
  const preview = page.getByRole("link", { name: "Voir l'email du garage" });
  const href = await preview.getAttribute("href");
  const response = await page.request.get(href!);
  expect(response.status()).toBe(200);
  expect(await response.text()).toContain("Confirmation de votre enlèvement");
});

test("a custom number of rims can be entered", async ({ page }) => {
  await page.goto("/");
  await fillGarage(page);
  await choose(page, page.getByRole("radio", { name: "Autre quantité" }));
  await page.getByLabel("Quantité exacte").fill("12");
  await pickFirstFreeSlot(page);
  await page.getByRole("button", { name: "Confirmer mon enlèvement" }).click();
  await expect(page.getByRole("heading", { name: "Votre enlèvement est confirmé." })).toBeVisible();
  await expect(page.getByText("12 jantes", { exact: true })).toBeVisible();
});

test("missing information is flagged before anything is sent", async ({ page }) => {
  await page.goto("/");
  let posted = false;
  page.on("request", (r) => {
    if (/\/api\/bookings(\.php)?$/.test(r.url())) posted = true;
  });
  await page.getByRole("button", { name: "Confirmer mon enlèvement" }).click();
  await expect(page.getByText("Indiquez le nom du garage.")).toBeVisible();
  await expect(page.getByText("Choisissez une date.")).toBeVisible();
  await expect(page.getByLabel("Nom du garage")).toBeFocused();
  await page.getByLabel("Email").fill("pas-un-email");
  await page.getByLabel("Email").blur();
  await expect(page.getByText("Adresse email invalide")).toBeVisible();
  expect(posted).toBe(false);
});

test("a double click creates a single booking", async ({ page }) => {
  await page.goto("/");
  await fillGarage(page);
  await choose(page, page.getByRole("radio", { name: "2 jantes" }));
  await pickFirstFreeSlot(page);
  let posts = 0;
  page.on("request", (r) => {
    if (/\/api\/bookings(\.php)?$/.test(r.url()) && r.method() === "POST") posts++;
  });
  await page.getByRole("button", { name: "Confirmer mon enlèvement" }).dblclick();
  await expect(page.getByRole("heading", { name: "Votre enlèvement est confirmé." })).toBeVisible();
  expect(posts).toBe(1);
});

test("the privacy notice opens on the same page", async ({ page }) => {
  await page.goto("/");
  await page.getByRole("link", { name: "Confidentialité" }).first().click();
  const dialog = page.getByRole("dialog", { name: "Confidentialité" });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText("Données collectées")).toBeVisible();
  await expect(dialog.getByText("à compléter par VBM").first()).toBeVisible();
  await dialog.getByRole("button", { name: "Fermer" }).click();
  await expect(dialog).toBeHidden();
});

test("the layout fits the screen without horizontal scrolling", async ({ page }) => {
  await page.goto("/");
  await expect(page.locator('input[name="date"]').first()).toBeAttached();
  await expectNoHorizontalScroll(page);
  const button = page.getByRole("button", { name: "Confirmer mon enlèvement" });
  const box = await button.boundingBox();
  expect(box!.height).toBeGreaterThanOrEqual(44);
  await choose(page, page.locator('input[name="date"]:not(:disabled)').first());
  await expectNoHorizontalScroll(page);
});

test("a garage can add its reference and photos", async ({ page }) => {
  await page.goto("/");
  await fillGarage(page);
  await choose(page, page.getByRole("radio", { name: "3 jantes" }));
  await page.getByLabel("Votre référence").fill("DOSSIER-2041");

  // A real 200 x 150 PNG, generated in the page so no fixture file is needed.
  const png = await page.evaluate(async () => {
    const canvas = document.createElement("canvas");
    canvas.width = 200;
    canvas.height = 150;
    const ctx = canvas.getContext("2d")!;
    ctx.fillStyle = "#1d1d1f";
    ctx.fillRect(0, 0, 200, 150);
    const blob = await new Promise<Blob>((resolve) => canvas.toBlob((b) => resolve(b!), "image/png"));
    return Array.from(new Uint8Array(await blob.arrayBuffer()));
  });
  const file = { mimeType: "image/png", buffer: Buffer.from(png) };
  await page.locator("#photos").setInputFiles([
    { name: "jante-avant.png", ...file },
    { name: "jante-arriere.png", ...file },
  ]);
  await expect(page.getByRole("button", { name: /Retirer la photo/ })).toHaveCount(2);
  await page.getByRole("button", { name: "Retirer la photo 2" }).click();
  await expect(page.getByRole("button", { name: /Retirer la photo/ })).toHaveCount(1);

  await pickFirstFreeSlot(page);
  await page.getByRole("button", { name: "Confirmer mon enlèvement" }).click();
  await expect(page.getByRole("heading", { name: "Votre enlèvement est confirmé." })).toBeVisible();
  await expect(page.getByText("DOSSIER-2041")).toBeVisible();
  await expect(page.getByText("1 photo transmise", { exact: true })).toBeVisible();

  // The simulated email to VBM carries the photo as an attachment.
  const vbm = await page.getByRole("link", { name: "Voir la notification VBM" }).getAttribute("href");
  await expect.poll(async () => (await page.request.get(vbm!)).status()).toBe(200);
  const html = await (await page.request.get(vbm!)).text();
  expect(html).toContain("Pièces jointes (1)");
  expect(html).toContain("photo-1.jpg");
  expect(html).toContain("DOSSIER-2041");
});

test("the WhatsApp button opens a conversation", async ({ page }) => {
  await page.goto("/");
  const button = page.getByRole("link", { name: "Nous écrire sur WhatsApp" });
  await expect(button).toBeVisible();
  await expect(button).toHaveAttribute("href", /^https:\/\/wa\.me\/\d{8,15}\?text=/);
  await expect(button).toHaveAttribute("target", "_blank");
});

test("the page ends after the pickup steps and its photo loads", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { name: /Comment se passe un enlèvement/ })).toBeVisible();
  await expect(page.locator("#savoir-faire, #questions, #contact")).toHaveCount(0);

  const broken = await page.evaluate(async () => {
    const images = [...document.images];
    for (const img of images) {
      img.scrollIntoView({ block: "center", behavior: "instant" });
      await new Promise<void>((resolve) => {
        if (img.complete) return resolve();
        img.addEventListener("load", () => resolve(), { once: true });
        img.addEventListener("error", () => resolve(), { once: true });
        setTimeout(resolve, 5000);
      });
    }
    return images.filter((img) => img.naturalWidth === 0).map((img) => img.currentSrc || img.src);
  });
  expect(broken).toEqual([]);
});
