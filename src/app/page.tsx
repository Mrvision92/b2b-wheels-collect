import Link from "next/link";
import { bookingConfig } from "@/config/booking";
import { siteConfig } from "@/config/site";
import { BookingForm } from "@/components/booking/booking-form";
import { Logo } from "@/components/logo";
import { PrivacyDialog } from "@/components/privacy-dialog";
import { BenefitsSection } from "@/components/sections/benefits";
import { ContactSection } from "@/components/sections/contact";
import { FaqSection } from "@/components/sections/faq";
import { ProcessSection } from "@/components/sections/process";
import { ServicesSection } from "@/components/sections/services";
import { WorkshopSection } from "@/components/sections/workshop";
import { getPublicSettings } from "@/lib/env";

const NAV = [
  { href: "#fonctionnement", label: "Fonctionnement" },
  { href: "#savoir-faire", label: "Savoir-faire" },
  { href: "#questions", label: "Questions" },
  { href: "#contact", label: "Contact" },
];

export default function Home() {
  const settings = getPublicSettings();
  const demoParts = [settings.demo.calendar ? "Google Agenda" : null, settings.demo.email ? "les emails" : null].filter(Boolean);

  return (
    <>
      {demoParts.length > 0 ? (
        <div className="border-b border-hairline bg-fill px-5 py-2 text-center text-[13px] leading-snug text-graphite">
          Mode démonstration&nbsp;: {demoParts.join(" et ")} {demoParts.length > 1 ? "sont simulés" : "est simulé"}.
          {settings.demo.email ? " Aucun email réel n'est envoyé." : ""}
        </div>
      ) : null}

      <header className="sticky top-0 z-40 border-b border-hairline/70 bg-paper/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 w-full max-w-[1120px] items-center justify-between gap-6 px-5 sm:px-6">
          <Link href="/" aria-label="B2B Wheels Collect, accueil" className="rounded-sm text-ink">
            <Logo />
          </Link>
          <nav aria-label="Sections" className="flex items-center gap-7 text-[14px]">
            <ul className="hidden items-center gap-7 lg:flex">
              {NAV.map((item) => (
                <li key={item.href}>
                  <a href={item.href} className="text-graphite transition-colors hover:text-ink">
                    {item.label}
                  </a>
                </li>
              ))}
            </ul>
            <a
              href="#reservation"
              className="inline-flex h-9 items-center rounded-full bg-ink px-4 font-medium text-white transition-colors hover:bg-black"
            >
              Réserver
            </a>
          </nav>
        </div>
      </header>

      <main id="contenu">
        <div className="pb-20 sm:pb-28">
          <div className="px-5 pb-9 pt-9 text-center sm:pb-16 sm:pt-16">
            <p className="text-[15px] font-medium text-ink sm:text-[17px]">Enlèvement professionnel de jantes</p>
            <h1 className="mx-auto mt-3 text-[clamp(2.6rem,1.45rem+4.6vw,5.25rem)] font-semibold leading-[1.02] tracking-[-0.035em] text-ink">
              Vos jantes.
              <br />
              <span className="text-muted">Notre logistique.</span>
            </h1>
            <p className="mx-auto mt-5 max-w-[36ch] text-balance text-[18px] leading-[1.45] text-muted sm:mt-6 sm:text-[21px]">
              Planifiez l&apos;enlèvement de vos jantes directement dans votre garage, en quelques clics.
            </p>
          </div>

          <BookingForm maxRims={bookingConfig.maxRimsPerBooking} turnstileSiteKey={settings.turnstileSiteKey} />
        </div>

        <ProcessSection />
        <WorkshopSection />
        <ServicesSection />
        <BenefitsSection />
        <FaqSection />
        <ContactSection
          contact={{ whatsapp: settings.contactWhatsapp, phone: settings.contactPhone, email: settings.contactEmail }}
          centres={siteConfig.centres}
        />
      </main>

      <footer className="border-t border-hairline">
        <div className="mx-auto flex w-full max-w-[1120px] flex-col gap-4 px-5 py-9 text-[13px] text-muted sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div className="flex items-center gap-3">
            <Logo className="scale-90 text-ink" />
          </div>
          <p>Le service d&apos;enlèvement de jantes de VBM Wheels Repair.</p>
          <nav aria-label="Informations" className="flex flex-wrap gap-x-5 gap-y-2">
            <a href="#contact" className="hover:text-ink">
              Contact
            </a>
            <a href="#confidentialite" className="hover:text-ink">
              Confidentialité
            </a>
          </nav>
        </div>
      </footer>

      <PrivacyDialog legal={settings.legal} turnstile={!!settings.turnstileSiteKey} />
    </>
  );
}
