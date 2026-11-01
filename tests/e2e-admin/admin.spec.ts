import { expect, test, type Page } from "@playwright/test";

/**
 * Administration (version PHP uniquement). Le compte de test est créé par
 * php/tests/reset-db.php au démarrage du serveur de test.
 */

const ADMIN = { email: "admin@test.local", password: "Test-admin-2026!" };
let counter = 0;

async function login(page: Page, path = "/admin/") {
  await page.goto(path);
  await expect(page).toHaveURL(/connexion\.php/);
  await page.getByLabel("Email").fill(ADMIN.email);
  await page.getByLabel("Mot de passe").fill(ADMIN.password);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page).not.toHaveURL(/connexion\.php/);
}

/** Réservation passée par le site public, comme un garage. */
async function publicBooking(page: Page, garageName: string) {
  let data = await (await page.request.get("/api/availability.php")).json();
  let day = data.days.find((d: { slots: { available: boolean }[] }) => d.slots.some((s) => s.available));
  if (!day) {
    const [y, m] = data.month.split("-").map(Number);
    data = await (await page.request.get(`/api/availability.php?month=${new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 7)}`)).json();
    day = data.days.find((d: { slots: { available: boolean }[] }) => d.slots.some((s) => s.available));
  }
  const slot = day.slots.find((s: { available: boolean }) => s.available);
  counter++;
  const response = await page.request.post("/api/bookings.php", {
    multipart: {
      data: JSON.stringify({
        requestId: crypto.randomUUID(),
        garageName,
        contactName: "Marie Dupont",
        phone: "0470 12 34 56",
        email: `atelier${counter}-${Date.now()}@garage-test.be`,
        street: `Rue de la Station ${counter}`,
        postalCode: "1000",
        city: "Bruxelles",
        rimCount: "4",
        date: day.date,
        slot: slot.key,
      }),
    },
  });
  expect(response.status()).toBe(201);
  return (await response.json()).booking.reference as string;
}

async function expectNoHorizontalScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

test("the admin requires a login and rejects a wrong password", async ({ page }) => {
  await page.goto("/admin/planning.php");
  await expect(page).toHaveURL(/connexion\.php\?retour=/);
  await page.getByLabel("Email").fill(ADMIN.email);
  await page.getByLabel("Mot de passe").fill("pas-le-bon");
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByText("Email ou mot de passe incorrect.")).toBeVisible();
  await page.getByLabel("Mot de passe").fill(ADMIN.password);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page).toHaveURL(/planning\.php/);
});

test("a public booking appears in the admin and is marked as collected after confirmation", async ({ page }) => {
  const garage = `Garage Public ${Date.now()}`;
  const reference = await publicBooking(page, garage);
  await login(page, "/admin/enlevements.php");
  await expect(page.getByRole("heading", { level: 1 })).toContainText(/enlèvements/i);

  await page.getByRole("searchbox", { name: "Rechercher" }).fill(reference);
  await expect(page).toHaveURL(new RegExp(`q=${reference}`));
  await page.getByRole("button", { name: "Détails" }).first().click();
  const sheet = page.getByRole("dialog", { name: "Détails de l'enlèvement" });
  await expect(sheet.getByRole("heading", { name: garage })).toBeVisible();
  await expect(sheet.getByText(reference)).toBeVisible();

  await sheet.getByRole("button", { name: "Marquer enlevé" }).click();
  const confirm = page.getByRole("dialog", { name: "Confirmer l'enlèvement ?" });
  await expect(confirm).toBeVisible();
  await confirm.getByRole("button", { name: "Retour" }).click();
  await expect(sheet.getByRole("button", { name: "Marquer enlevé" })).toBeVisible();

  await sheet.getByRole("button", { name: "Marquer enlevé" }).click();
  await confirm.getByRole("button", { name: "Oui, marquer enlevé" }).click();
  await expect(page.getByRole("status").filter({ hasText: "Email envoyé au garage" })).toBeVisible();
  await expect(sheet.getByText(/Enlevé le .* par Équipe VBM/)).toBeVisible();
  await expect(sheet.locator(".mail-list li").filter({ hasText: "Confirmation d'enlèvement" })).toContainText("Envoyé le");
});

test("VBM creates a pickup for a new garage and finds it in the planning", async ({ page }) => {
  await login(page);
  const garage = `Garage Téléphone ${Date.now()}`;
  await page.getByRole("button", { name: "Nouvel enlèvement" }).first().click();
  const sheet = page.getByRole("dialog", { name: "Nouvel enlèvement" });
  await sheet.getByLabel("Nom du garage").fill(garage);
  await sheet.getByLabel("Nom et prénom").fill("Paul Martin");
  await sheet.getByLabel("Téléphone").fill("02 123 45 67");
  await sheet.getByLabel("Rue et numéro").fill("Rue Haute 12");
  await sheet.getByLabel("Code postal").fill("1000");
  await sheet.getByLabel("Ville").fill("Bruxelles");
  await sheet.getByLabel("Nombre de jantes").fill("2");

  // Premier jour ouvré dans une semaine, pour être sûr d'avoir des créneaux.
  const date = await page.evaluate(() => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    while (d.getDay() === 0 || d.getDay() === 6) d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
  });
  await sheet.getByLabel("Date").fill(date);
  const slot = sheet.locator('.slot-choice:has(input:not(:disabled))').first();
  await expect(slot).toBeVisible();
  await slot.click();
  await sheet.getByRole("button", { name: "Enregistrer l'enlèvement" }).click();
  await expect(page.getByRole("status").filter({ hasText: /Enlèvement BWC-[0-9A-Z]{4}-[0-9A-Z]{4} enregistré/ })).toBeVisible();

  await page.goto(`/admin/planning.php?vue=day&date=${date}`);
  await expect(page.getByRole("button", { name: new RegExp(garage) })).toBeVisible();
  await page.goto(`/admin/garages.php?q=${encodeURIComponent(garage)}`);
  await expect(page.getByRole("link", { name: garage }).first()).toBeVisible();
});

test("every admin page fits the screen", async ({ page }) => {
  await login(page);
  for (const path of ["/admin/", "/admin/enlevements.php", "/admin/garages.php", "/admin/planning.php", "/admin/planning.php?vue=month", "/admin/compte.php"]) {
    await page.goto(path);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expectNoHorizontalScroll(page);
  }
});

test("logging out closes the session", async ({ page }) => {
  await login(page, "/admin/compte.php");
  await page.getByRole("button", { name: "Se déconnecter" }).click();
  await expect(page).toHaveURL(/connexion\.php/);
  await page.goto("/admin/");
  await expect(page).toHaveURL(/connexion\.php/);
});
