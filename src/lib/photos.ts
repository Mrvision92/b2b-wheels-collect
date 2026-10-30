import type { EmailAttachment } from "./email/mailer";
import { PHOTO_LIMITS } from "./photo-limits";

type ImageType = { mime: string; extension: string };

/** Identifies the image format from its first bytes, ignoring the declared type. */
export function sniffImageType(bytes: Uint8Array): ImageType | null {
  const ascii = (start: number, end: number) => String.fromCharCode(...bytes.subarray(start, end));
  if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return { mime: "image/jpeg", extension: "jpg" };
  if (ascii(0, 8) === "\x89PNG\r\n\x1a\n") return { mime: "image/png", extension: "png" };
  if (ascii(0, 4) === "RIFF" && ascii(8, 12) === "WEBP") return { mime: "image/webp", extension: "webp" };
  if (ascii(4, 8) === "ftyp" && /^(heic|heix|hevc|mif1|msf1)$/.test(ascii(8, 12))) {
    return { mime: "image/heic", extension: "heic" };
  }
  return null;
}

export type PhotoResult = { ok: true; photos: EmailAttachment[] } | { ok: false; message: string };

/** Validates uploaded photos and turns them into email attachments with neutral names. */
export async function readPhotos(files: File[]): Promise<PhotoResult> {
  if (files.length > PHOTO_LIMITS.maxCount) {
    return { ok: false, message: `${PHOTO_LIMITS.maxCount} photos maximum par enlèvement.` };
  }
  const total = files.reduce((sum, file) => sum + file.size, 0);
  if (total > PHOTO_LIMITS.maxTotalBytes) {
    return { ok: false, message: "Les photos sont trop lourdes. Retirez-en une ou deux puis réessayez." };
  }
  const photos: EmailAttachment[] = [];
  for (const [index, file] of files.entries()) {
    const content = Buffer.from(await file.arrayBuffer());
    const type = sniffImageType(content);
    if (!type || content.length === 0) {
      return { ok: false, message: "Seules les photos (JPEG, PNG, WebP ou HEIC) peuvent être jointes." };
    }
    photos.push({ filename: `photo-${index + 1}.${type.extension}`, contentType: type.mime, content });
  }
  return { ok: true, photos };
}
