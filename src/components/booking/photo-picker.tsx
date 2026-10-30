"use client";

import { useRef } from "react";
import { PHOTO_LIMITS } from "@/lib/photo-limits";
import { CameraIcon, CloseIcon } from "../icons";
import { ErrorText } from "./field";

export type PickedPhoto = {
  id: string;
  /** Resized JPEG when the browser can decode the image, otherwise the original file. */
  file: Blob;
  preview: string;
  ready: boolean;
};

/** Shrinks a photo to the configured longest side and re-encodes it as JPEG. */
async function shrink(file: File): Promise<Blob> {
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
    const scale = Math.min(1, PHOTO_LIMITS.maxDimension / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext("2d")?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.82));
    if (!blob) return file;
    return file.type === "image/jpeg" && file.size <= blob.size ? file : blob;
  } catch {
    // Formats the browser cannot decode (HEIC on some browsers) are sent as is.
    return file;
  }
}

type Props = {
  photos: PickedPhoto[];
  onChange: (update: (photos: PickedPhoto[]) => PickedPhoto[]) => void;
  error?: string;
  onError: (message: string | undefined) => void;
};

export function PhotoPicker({ photos, onChange, error, onError }: Props) {
  const input = useRef<HTMLInputElement>(null);
  const remaining = PHOTO_LIMITS.maxCount - photos.length;
  const errorId = "photos-error";

  function add(files: FileList | null) {
    if (!files?.length) return;
    const images = [...files].filter((f) => f.type.startsWith("image/") || /\.(heic|heif)$/i.test(f.name));
    if (images.length < files.length) onError("Seules les photos peuvent être jointes.");
    else if (images.length > remaining) onError(`${PHOTO_LIMITS.maxCount} photos maximum : les suivantes n'ont pas été ajoutées.`);
    else onError(undefined);

    for (const file of images.slice(0, Math.max(0, remaining))) {
      const id = crypto.randomUUID();
      onChange((list) => [...list, { id, file, preview: URL.createObjectURL(file), ready: false }]);
      void shrink(file).then((blob) => {
        onChange((list) =>
          list.map((p) => {
            if (p.id !== id) return p;
            if (blob === file) return { ...p, ready: true };
            URL.revokeObjectURL(p.preview);
            return { ...p, file: blob, preview: URL.createObjectURL(blob), ready: true };
          }),
        );
      });
    }
    if (input.current) input.current.value = "";
  }

  function remove(id: string) {
    onChange((list) => {
      const target = list.find((p) => p.id === id);
      if (target) URL.revokeObjectURL(target.preview);
      return list.filter((p) => p.id !== id);
    });
    onError(undefined);
  }

  return (
    <div>
      <div className="mb-1.5 flex items-baseline justify-between gap-3 text-[14px] font-medium text-ink">
        <span id="photos-label">Photos des jantes</span>
        <span className="text-[13px] font-normal text-muted">Facultatif</span>
      </div>
      <p className="mb-3 text-[13.5px] leading-snug text-muted">
        Une vue d&apos;ensemble et un gros plan des dégâts aident l&apos;atelier à préparer l&apos;intervention.{" "}
        {PHOTO_LIMITS.maxCount} photos maximum.
      </p>

      <ul className="flex flex-wrap gap-2.5" aria-labelledby="photos-label">
        {photos.map((photo, index) => (
          <li key={photo.id} className="relative size-[4.5rem] overflow-hidden rounded-[10px] border border-hairline bg-fill">
            {/* eslint-disable-next-line @next/next/no-img-element -- local blob preview */}
            <img
              src={photo.preview}
              alt={`Photo ${index + 1}`}
              className={`size-full object-cover transition-opacity ${photo.ready ? "" : "opacity-50"}`}
            />
            <button
              type="button"
              onClick={() => remove(photo.id)}
              aria-label={`Retirer la photo ${index + 1}`}
              className="absolute right-1 top-1 flex size-6 items-center justify-center rounded-full bg-black/70 text-white transition-colors hover:bg-black"
            >
              <CloseIcon className="size-3.5" />
            </button>
          </li>
        ))}
        {remaining > 0 ? (
          <li>
            <label
              htmlFor="photos"
              className="flex size-[4.5rem] cursor-pointer flex-col items-center justify-center gap-1 rounded-[10px] border border-dashed border-line text-[12px] font-medium text-graphite transition-colors hover:border-ink hover:text-ink has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ink"
            >
              <CameraIcon className="size-5" />
              Ajouter
              <input
                ref={input}
                id="photos"
                type="file"
                accept="image/*,.heic,.heif"
                multiple
                onChange={(e) => add(e.target.files)}
                aria-describedby={error ? errorId : undefined}
                className="sr-only"
              />
            </label>
          </li>
        ) : null}
      </ul>
      {error ? <ErrorText id={errorId}>{error}</ErrorText> : null}
    </div>
  );
}
