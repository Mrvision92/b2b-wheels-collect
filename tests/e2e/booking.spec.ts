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
    if (r.url().endsWith("/api/bookings")) posted = true;
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
    if (r.url().endsWith("/api/bookings") && r.method() === "POST") posts++;
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

test("the contact section offers WhatsApp, phone and email", async ({ page }) => {
  await page.goto("/");
  const contact = page.locator("#contact");
  await expect(contact.getByRole("link", { name: /Écrire sur WhatsApp/ })).toHaveAttribute("href", /^https:\/\/wa\.me\/\d{8,15}\?text=/);
  await expect(contact.getByRole("link", { name: /Appeler/ })).toHaveAttribute("href", /^tel:\+32\d{8,9}$/);
  await expect(contact.getByRole("link", { name: /Écrire un email/ })).toHaveAttribute("href", /^mailto:.+@.+/);
  await expect(contact.getByRole("link", { name: "Itinéraire" })).toHaveCount(3);

  await contact.getByRole("link", { name: "Planifier un enlèvement" }).click();
  await expect(page.getByLabel("Nom du garage")).toBeInViewport();
});

test("questions open on demand and every image loads", async ({ page }) => {
  await page.goto("/");
  const question = page.getByText("Comment modifier ou annuler un enlèvement ?");
  const answer = page.getByText(/Répondez simplement à l'email de confirmation/);
  await expect(answer).toBeHidden();
  await question.click();
  await expect(answer).toBeVisible();

  // Scroll through the page so lazy images load, then check none is broken.
  for (let y = 0; y < 12; y++) await page.mouse.wheel(0, 900);
  await page.waitForLoadState("networkidle");
  const broken = await page.evaluate(async () => {
    const images = [...document.images];
    await Promise.all(images.map((img) => img.decode().catch(() => undefined)));
    return images.filter((img) => img.naturalWidth === 0).map((img) => img.currentSrc);
  });
  expect(broken).toEqual([]);
});
