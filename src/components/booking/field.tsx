import type { ReactNode } from "react";

export function ErrorText({ id, children }: { id: string; children: ReactNode }) {
  return (
    <p id={id} className="mt-1.5 flex items-start gap-1.5 text-[13.5px] leading-snug text-danger">
      <svg viewBox="0 0 16 16" aria-hidden="true" className="mt-[2px] size-[14px] shrink-0" fill="currentColor">
        <path d="M8 1.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Zm0 8.75a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8ZM8 4.5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 8 4.5Z" />
      </svg>
      <span>{children}</span>
    </p>
  );
}

type FieldProps = {
  id: string;
  label: string;
  error?: string;
  optional?: boolean;
  className?: string;
  children: ReactNode;
};

export function Field({ id, label, error, optional, className = "", children }: FieldProps) {
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 flex items-baseline justify-between gap-3 text-[14px] font-medium text-ink">
        <span>{label}</span>
        {optional ? <span className="text-[13px] font-normal text-muted">Facultatif</span> : null}
      </label>
      {children}
      {error ? <ErrorText id={`${id}-error`}>{error}</ErrorText> : null}
    </div>
  );
}

/** Accessibility attributes for an input tied to a Field. */
export function describedBy(id: string, error?: string) {
  return error ? { "aria-invalid": true as const, "aria-describedby": `${id}-error` } : { "aria-invalid": false as const };
}

export function SectionTitle({ children, id }: { children: ReactNode; id?: string }) {
  return (
    <h2 id={id} className="text-[19px] font-semibold tracking-[-0.015em] text-ink">
      {children}
    </h2>
  );
}
