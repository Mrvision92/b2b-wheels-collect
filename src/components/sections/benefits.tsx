import Image from "next/image";
import type { ReactNode } from "react";
import finishing from "@/assets/images/finition-soin-jante.webp";
import { BoxIcon, CalendarCheckIcon, DropIcon, InspectIcon, MapPinIcon, TruckIcon } from "../icons";
import { SectionHeading } from "./section-heading";

const BENEFITS: { icon: ReactNode; title: string; text: string }[] = [
  {
    icon: <TruckIcon />,
    title: "Sans déplacement",
    text: "Vous réservez en ligne, nous venons chercher les jantes au garage et vous les rapportons.",
  },
  {
    icon: <CalendarCheckIcon />,
    title: "Un créneau confirmé",
    text: "Une plage horaire choisie à l'avance, confirmée par email avec votre référence.",
  },
  {
    icon: <BoxIcon />,
    title: "Emballage soigné",
    text: "Pour le transport, chaque jante est emballée afin d'arriver comme elle est partie.",
  },
  {
    icon: <InspectIcon />,
    title: "Devis après examen",
    text: "Devis et délai confirmés après examen, et validés par vous avant le début des travaux.",
  },
  {
    icon: <MapPinIcon />,
    title: "Partout en Belgique",
    text: "Trois centres à Bruxelles, enlèvement et livraison dans tout le pays.",
  },
  {
    icon: <DropIcon />,
    title: "Teintes d'origine",
    text: "Nous recherchons la teinte d'origine de la jante, ou réalisons la couleur choisie.",
  },
];

export function BenefitsSection() {
  return (
    <section aria-labelledby="avantages-title" className="border-y border-hairline bg-surface">
      <div className="mx-auto grid max-w-[1120px] gap-12 px-5 py-20 sm:px-6 sm:py-28 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)] lg:gap-20">
        <Image
          src={finishing}
          alt="Finition d'une roue à la brosse, main gantée"
          placeholder="blur"
          sizes="(min-width: 1024px) 420px, 100vw"
          className="aspect-[4/3] w-full object-cover object-[50%_60%] lg:aspect-auto lg:h-full lg:max-h-[44rem]"
        />
        <div>
          <SectionHeading id="avantages-title" title="Pensé pour les garages." subtitle="Simple chez vous, rigoureux chez nous." />
          <ul className="mt-12 grid gap-x-10 gap-y-9 sm:grid-cols-2">
            {BENEFITS.map((benefit) => (
              <li key={benefit.title}>
                <span className="flex size-11 items-center justify-center rounded-full border border-line text-ink">
                  {benefit.icon}
                </span>
                <h3 className="mt-4 text-[17px] font-semibold tracking-[-0.01em]">{benefit.title}</h3>
                <p className="mt-1.5 text-[15.5px] leading-relaxed text-muted">{benefit.text}</p>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
