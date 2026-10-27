/** RimCollect wordmark: a five-spoke rim drawn with the same stroke as the type. */
export function Logo({ className = "" }: { className?: string }) {
  return (
    <span className={`inline-flex items-center gap-2 ${className}`}>
      <svg viewBox="0 0 24 24" aria-hidden="true" className="size-[22px] shrink-0" fill="none" stroke="currentColor">
        <circle cx="12" cy="12" r="10" strokeWidth="2" />
        <circle cx="12" cy="12" r="3.1" strokeWidth="1.8" />
        <path
          strokeWidth="1.6"
          strokeLinecap="round"
          d="M12 8.9V2.6M15 11 21 9M13.8 14.5l3.8 5.2M10.2 14.5l-3.8 5.2M9 11 3 9"
        />
      </svg>
      <span className="text-[19px] font-semibold tracking-[-0.03em]">RimCollect</span>
    </span>
  );
}
