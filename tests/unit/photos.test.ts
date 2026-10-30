import { describe, expect, it } from "vitest";
import { readPhotos, sniffImageType } from "@/lib/photos";

const bytes = (...values: number[]) => new Uint8Array(values);
const ascii = (text: string) => new Uint8Array([...text].map((c) => c.charCodeAt(0)));

describe("photo type detection", () => {
  it("recognises JPEG, PNG, WebP and HEIC from their content", () => {
    expect(sniffImageType(bytes(0xff, 0xd8, 0xff, 0xe1))?.mime).toBe("image/jpeg");
    expect(sniffImageType(bytes(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a))?.mime).toBe("image/png");
    expect(sniffImageType(ascii("RIFF\0\0\0\0WEBPVP8 "))?.mime).toBe("image/webp");
    expect(sniffImageType(ascii("\0\0\0\x18ftypheic"))?.mime).toBe("image/heic");
  });

  it("rejects anything else", () => {
    expect(sniffImageType(ascii("%PDF-1.7"))).toBeNull();
    expect(sniffImageType(ascii("<svg xmlns"))).toBeNull();
    expect(sniffImageType(ascii("GIF89a"))).toBeNull();
  });
});

describe("readPhotos", () => {
  const jpeg = () => new File([bytes(0xff, 0xd8, 0xff, 0xe0, 1, 2, 3)], "../../etc/passwd.jpg", { type: "image/jpeg" });

  it("renames files instead of trusting their names", async () => {
    const result = await readPhotos([jpeg(), jpeg()]);
    expect(result.ok && result.photos.map((p) => p.filename)).toEqual(["photo-1.jpg", "photo-2.jpg"]);
  });

  it("enforces the count and total size limits", async () => {
    expect((await readPhotos(Array.from({ length: 6 }, jpeg))).ok).toBe(false);
    const heavy = new File([new Uint8Array(5 * 1024 * 1024)], "big.jpg", { type: "image/jpeg" });
    expect((await readPhotos([heavy])).ok).toBe(false);
  });
});
