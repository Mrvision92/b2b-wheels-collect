import Image from "next/image";
import van from "@/assets/images/vbm-camionnette-bruxelles.jpg";
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
      <div className="mx-auto max-w-[1120px] px-5 py-20 sm:px-6 sm:py-28">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)] lg:items-end">
          <SectionHeading
            id="fonctionnement-title"
            title="Comment se passe un enlèvement."
            subtitle="Vous restez au garage."
          />
          <p className="max-w-[46ch] text-[17px] leading-relaxed text-muted lg:justify-self-end">
            Nous venons chercher les jantes à votre garage, à Bruxelles comme partout en Belgique, et vous les rapportons
            une fois le travail terminé. Hors de Belgique, sur demande.
          </p>
        </div>

        <figure className="-mx-5 mt-12 sm:mx-0 sm:mt-14">
          <Image
            src={van}
            alt="Camionnette VBM Wheels aux couleurs de l'atelier, sur la route à Bruxelles, l'Atomium en arrière-plan"
            placeholder="blur"
            sizes="(min-width: 1120px) 1072px, 100vw"
            className="aspect-[16/10] w-full object-cover object-[40%_55%] sm:aspect-[16/9] lg:aspect-[21/9]"
          />
        </figure>

        <ol className="mt-10 grid border-t border-hairline sm:mt-12 sm:grid-cols-2 sm:gap-x-10 lg:grid-cols-4 lg:border-t-0">
          {STEPS.map((step, index) => (
            <li key={step.title} className="border-b border-hairline py-6 sm:border-b-0 lg:border-t lg:pt-7">
              <span className="text-[15px] font-medium text-subtle">{String(index + 1).padStart(2, "0")}</span>
              <h3 className="mt-2 text-[19px] font-semibold tracking-[-0.015em]">{step.title}</h3>
              <p className="mt-1.5 max-w-[46ch] text-[16px] leading-relaxed text-muted">{step.text}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
