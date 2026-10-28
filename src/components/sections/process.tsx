import Image from "next/image";
import van from "@/assets/images/livraison-camionnette.webp";
import { SectionHeading } from "./section-heading";

const STEPS = [
  {
    title: "Vous réservez",
    text: "Choisissez une date, un créneau et le nombre de jantes. La confirmation arrive aussitôt par email, avec votre référence.",
  },
  {
    title: "Nous venons au garage",
    text: "Nous passons à l'adresse indiquée, pendant le créneau choisi. Les jantes sont emballées avec soin pour le trajet.",
  },
  {
    title: "Examen et devis",
    text: "À l'atelier, chaque jante est examinée. Le devis repose sur ce constat, avec un délai confirmé : vous le validez avant le début des travaux.",
  },
  {
    title: "Retour au garage",
    text: "Le travail terminé, vos jantes sont emballées avec soin puis livrées à l'adresse convenue.",
  },
];

export function ProcessSection() {
  return (
    <section
      id="fonctionnement"
      aria-labelledby="fonctionnement-title"
      className="scroll-mt-16 border-y border-hairline bg-surface"
    >
      <div className="mx-auto grid max-w-[1120px] gap-12 px-5 py-20 sm:px-6 sm:py-28 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.9fr)] lg:gap-20">
        <div>
          <SectionHeading
            id="fonctionnement-title"
            title="Comment se passe un enlèvement."
            subtitle="Vous restez au garage."
          />
          <ol className="mt-12 border-t border-hairline">
            {STEPS.map((step, index) => (
              <li key={step.title} className="grid grid-cols-[2.75rem_minmax(0,1fr)] gap-x-3 border-b border-hairline py-6">
                <span className="pt-[3px] text-[15px] font-medium text-subtle">{String(index + 1).padStart(2, "0")}</span>
                <div>
                  <h3 className="text-[19px] font-semibold tracking-[-0.015em]">{step.title}</h3>
                  <p className="mt-1.5 max-w-[52ch] text-[16px] leading-relaxed text-muted">{step.text}</p>
                </div>
              </li>
            ))}
          </ol>
        </div>
        <figure className="self-start lg:sticky lg:top-24">
          <Image
            src={van}
            alt="Camionnette d'enlèvement en route"
            placeholder="blur"
            sizes="(min-width: 1024px) 470px, 100vw"
            className="aspect-[4/3] w-full object-cover object-[60%_50%] lg:aspect-[4/5]"
          />
          <figcaption className="mt-3 text-[13.5px] text-muted">
            Enlèvement et livraison partout en Belgique, hors de Belgique sur demande.
          </figcaption>
        </figure>
      </div>
    </section>
  );
}
