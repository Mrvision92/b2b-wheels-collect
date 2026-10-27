"use client";

import { describedBy, ErrorText } from "./field";

export type RimChoice = "1" | "2" | "3" | "4" | "other" | "";

type Props = {
  choice: RimChoice;
  other: string;
  max: number;
  onChoice: (choice: RimChoice) => void;
  onOther: (value: string) => void;
  onOtherBlur: () => void;
  error?: string;
};

const CHOICES: { value: RimChoice; label: string; aria: string }[] = [
  { value: "1", label: "1", aria: "1 jante" },
  { value: "2", label: "2", aria: "2 jantes" },
  { value: "3", label: "3", aria: "3 jantes" },
  { value: "4", label: "4", aria: "4 jantes" },
  { value: "other", label: "Autre", aria: "Autre quantité" },
];

export function RimCount({ choice, other, max, onChoice, onOther, onOtherBlur, error }: Props) {
  const errorId = "rimCount-error";
  return (
    <fieldset aria-describedby={error && choice !== "other" ? errorId : undefined}>
      <legend className="mb-2.5 text-[14px] font-medium text-ink">Nombre de jantes à enlever</legend>
      <div className="grid grid-cols-5 gap-2">
        {CHOICES.map((c) => (
          <label key={c.value} className="relative block">
            <input
              type="radio"
              name="rimChoice"
              value={c.value}
              checked={choice === c.value}
              onChange={() => onChoice(c.value)}
              aria-label={c.aria}
              className="peer sr-only"
            />
            <span
              aria-hidden="true"
              className={[
                "flex h-12 cursor-pointer items-center justify-center rounded-full border border-line text-[16px] font-medium text-ink transition-colors duration-150",
                "hover:border-ink peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white",
                "peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ink",
                c.value === "other" ? "text-[15px]" : "",
              ].join(" ")}
            >
              {c.label}
            </span>
          </label>
        ))}
      </div>

      {choice === "other" ? (
        <div className="mt-3 flex items-center gap-3 [animation:rc-fade-up_220ms_var(--ease-out-soft)]">
          <label htmlFor="rimCount" className="text-[15px] text-muted">
            Quantité exacte
          </label>
          <input
            id="rimCount"
            name="rimCount"
            type="text"
            inputMode="numeric"
            pattern="[0-9]*"
            autoComplete="off"
            maxLength={3}
            placeholder={`1 à ${max}`}
            value={other}
            onChange={(e) => onOther(e.target.value.replace(/\D/g, ""))}
            onBlur={onOtherBlur}
            className="field-input w-28 text-center"
            {...describedBy("rimCount", error)}
            autoFocus
          />
        </div>
      ) : null}
      {error ? <ErrorText id={errorId}>{error}</ErrorText> : null}
    </fieldset>
  );
}
