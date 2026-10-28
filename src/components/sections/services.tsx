import Image, { type StaticImageData } from "next/image";
import biTone from "@/assets/images/bi-ton-face-polie.webp";
import stripping from "@/assets/images/decapage-preparation-etincelles.webp";
import straightening from "@/assets/images/devoilage-roue-main-gantee.webp";
import powder from "@/assets/images/peinture-pistolet-cabine.webp";
import custom from "@/assets/images/personnalisation-jante-cuivre.webp";
import renovation from "@/assets/images/renovation-jante-argent.webp";
import welding from "@/assets/images/soudure-tig.webp";
import diamond from "@/assets/images/usinage-diamant-faces-usinees.webp";
import { SectionHeading } from "./section-heading";

const SERVICES: { title: string; text: string; image: StaticImageData }[] = [
  { title: "Rénovation", text: "Rayures, frottements, corrosion : une finition reprise jusqu'au métal.", image: renovation },
  { title: "Usinage diamant", text: "L'éclat du métal usiné, retrouvé au tour puis protégé par un vernis.", image: diamond },
  { title: "Jante peinte, face polie", text: "La couleur dans les creux, l'éclat du métal usiné sur la face.", image: biTone },
  { title: "Personnalisation", text: "Mat, brillant, bi-ton ou couleur sur mesure, selon ce que permet la jante.", image: custom },
  { title: "Dévoilage", text: "Mesurer le voile, puis redresser lorsque la jante le permet.", image: straightening },
  { title: "Soudure", text: "Une fissure examinée avant d'être soudée, ou un conseil de remplacement.", image: welding },
  { title: "Décapage", text: "Retour au métal nu, pour repartir sur une base saine.", image: stripping },
  { title: "Peinture poudre", text: "Une couleur cuite au four, lorsque la jante s'y prête.", image: powder },
];

export function ServicesSection() {
  return (
    <section id="savoir-faire" aria-labelledby="savoir-faire-title" className="scroll-mt-16">
      <div className="mx-auto max-w-[1120px] px-5 py-20 sm:px-6 sm:py-28">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)] lg:items-end">
          <SectionHeading
            id="savoir-faire-title"
            title="Ce que nous faisons de vos jantes."
            subtitle="Huit savoir-faire, une même exigence."
          />
          <p className="max-w-[46ch] text-[17px] leading-relaxed text-muted lg:justify-self-end">
            Chaque jante reçoit l&apos;intervention qui lui convient, après examen. Notre référence reste l&apos;aspect
            d&apos;origine&nbsp;: un résultat net, homogène, soigné jusque dans les détails.
          </p>
        </div>
        <ul className="mt-12 grid grid-cols-2 gap-x-4 gap-y-9 sm:gap-x-6 lg:grid-cols-4">
          {SERVICES.map((service) => (
            <li key={service.title}>
              <Image
                src={service.image}
                alt=""
                placeholder="blur"
                sizes="(min-width: 1024px) 260px, (min-width: 640px) 45vw, 50vw"
                className="aspect-[4/5] w-full bg-fill object-cover"
              />
              <h3 className="mt-4 text-[16.5px] font-semibold tracking-[-0.01em] sm:text-[17px]">{service.title}</h3>
              <p className="mt-1 text-[14.5px] leading-snug text-muted sm:text-[15px]">{service.text}</p>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
