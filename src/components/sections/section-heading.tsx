/** Two-tone heading used by every section, as on the VBM site. */
export function SectionHeading({
  id,
  title,
  subtitle,
  tone = "light",
}: {
  id: string;
  title: string;
  subtitle: string;
  tone?: "light" | "dark";
}) {
  return (
    <h2
      id={id}
      className="text-balance text-[clamp(2rem,1.3rem+2.4vw,3.25rem)] font-semibold leading-[1.07] tracking-[-0.03em]"
    >
      {title}
      <br />
      <span className={tone === "dark" ? "text-white/60" : "text-muted"}>{subtitle}</span>
    </h2>
  );
}
