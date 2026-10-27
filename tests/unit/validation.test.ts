import { describe, expect, it } from "vitest";
import { formatPhone, normalizePhone } from "@/lib/phone";
import { bookingSchema, fieldErrorsFrom } from "@/lib/validation";

const valid = {
  requestId: "6f1f0d4e-3a4b-4c1d-9e2f-1a2b3c4d5e6f",
  garageName: "Garage Dupont",
  contactName: "Marie Dupont",
  phone: "0470 12 34 56",
  email: "atelier@garage-dupont.be",
  street: "Rue de la Station 12",
  postalCode: "1000",
  city: "Bruxelles",
  rimCount: "4",
  date: "2026-11-03",
  slot: "0900",
  comment: "",
};

describe("phone numbers", () => {
  it.each([
    ["0470 12 34 56", "+32470123456"],
    ["0470/12.34.56", "+32470123456"],
    ["+32 470 12 34 56", "+32470123456"],
    ["0032 2 123 45 67", "+3221234567"],
    ["02 123 45 67", "+3221234567"],
    ["+33 6 12 34 56 78", "+33612345678"],
    ["+352 621 123 456", "+352621123456"],
  ])("accepts %s", (raw, expected) => {
    expect(normalizePhone(raw)).toBe(expected);
  });

  it.each(["", "12345", "0470 12 34", "+32 0470 12 34 56", "abc", "+32 470 12 34 56 78"])("rejects %s", (raw) => {
    expect(normalizePhone(raw)).toBeNull();
  });

  it("formats Belgian numbers for display", () => {
    expect(formatPhone("+32470123456")).toBe("+32 470 12 34 56");
    expect(formatPhone("+3221234567")).toBe("+32 2 123 45 67");
    expect(formatPhone("+3281234567")).toBe("+32 81 23 45 67");
  });
});

describe("booking form validation", () => {
  it("accepts a complete form and normalises it", () => {
    const result = bookingSchema.safeParse({ ...valid, garageName: "  Garage   Dupont ", comment: "  Avec pneus  " });
    expect(result.success).toBe(true);
    if (!result.success) return;
    expect(result.data.garageName).toBe("Garage Dupont");
    expect(result.data.phone).toBe("+32470123456");
    expect(result.data.rimCount).toBe(4);
    expect(result.data.comment).toBe("Avec pneus");
  });

  it("reports every missing required field with a French message", () => {
    const result = bookingSchema.safeParse({ requestId: valid.requestId });
    expect(result.success).toBe(false);
    if (result.success) return;
    const errors = fieldErrorsFrom(result.error);
    expect(Object.keys(errors).sort()).toEqual(
      ["city", "contactName", "date", "email", "garageName", "phone", "postalCode", "rimCount", "slot", "street"].sort(),
    );
    expect(errors.garageName).toBe("Indiquez le nom du garage.");
    expect(errors.rimCount).toBe("Indiquez le nombre de jantes.");
  });

  it.each([
    ["email", "pas-un-email", "Adresse email invalide"],
    ["phone", "123", "Numéro invalide"],
    ["postalCode", "75001", "Code postal belge"],
    ["street", "Rue de la Station", "numéro de rue"],
    ["contactName", "Dupont", "prénom et le nom"],
    ["rimCount", "0", "au moins 1 jante"],
    ["rimCount", "500", "contactez-nous"],
    ["rimCount", "2.5", "nombre entier"],
    ["date", "2026-02-30", "Choisissez une date"],
    ["slot", "9h", "Choisissez un créneau"],
    ["requestId", "abc", "Requête invalide"],
  ])("rejects an invalid %s", (field, value, message) => {
    const result = bookingSchema.safeParse({ ...valid, [field]: value });
    expect(result.success).toBe(false);
    if (result.success) return;
    expect(fieldErrorsFrom(result.error)[field as keyof typeof valid]).toContain(message);
  });

  it("strips control characters and limits lengths", () => {
    const result = bookingSchema.safeParse({ ...valid, garageName: "Garage\u0000\nDupont", comment: "a".repeat(501) });
    expect(result.success).toBe(false);
    const ok = bookingSchema.safeParse({ ...valid, garageName: "Garage\u0000\nDupont" });
    expect(ok.success && ok.data.garageName).toBe("Garage Dupont");
  });

  it("ignores unknown fields", () => {
    const result = bookingSchema.safeParse({ ...valid, isAdmin: true, status: "collected" });
    expect(result.success).toBe(true);
    expect(result.success && "isAdmin" in result.data).toBe(false);
  });
});
