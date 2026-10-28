import Image from "next/image";
import workshop from "@/assets/images/atelier-vue-large.webp";
import { SectionHeading } from "./section-heading";

export function WorkshopSection() {
  return (
    <section aria-labelledby="atelier-title" className="relative isolate overflow-hidden bg-ink text-white">
      <Image src={workshop} alt="" fill placeholder="blur" sizes="100vw" className="-z-10 object-cover opacity-75" />
      <div aria-hidden="true" className="absolute inset-0 -z-10 bg-gradient-to-t from-black/85 via-black/45 to-black/10" />
      <div className="mx-auto flex min-h-[30rem] max-w-[1120px] flex-col justify-end px-5 py-14 sm:min-h-[38rem] sm:px-6 sm:py-20">
        <SectionHeading id="atelier-title" title="Un atelier spécialisé." subtitle="Trois centres à Bruxelles." tone="dark" />
        <p className="mt-5 max-w-[50ch] text-[17px] leading-relaxed text-white/80 sm:text-[18px]">
          Réparation, rénovation et personnalisation de jantes en aluminium&nbsp;: vos jantes sont prises en charge par
          l&apos;équipe de VBM Wheels Repair, à Laeken, Anderlecht et Evere.
        </p>
      </div>
    </section>
  );
}
