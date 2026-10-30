import Link from "next/link";
import { bookingConfig } from "@/config/booking";
import { BookingForm } from "@/components/booking/booking-form";
import { Logo } from "@/components/logo";
import { PrivacyDialog } from "@/components/privacy-dialog";
import { ProcessSection } from "@/components/sections/process";
import { WhatsappButton } from "@/components/whatsapp-button";
import { getPublicSettings } from "@/lib/env";
import { formatPhone, normalizePhone } from "@/lib/phone";

export default function Home() {
  const settings = getPublicSettings();
  const demoParts = [settings.demo.calendar ? "Google Agenda" : null, settings.demo.email ? "les emails" : null].filter(Boolean);
  const phone = settings.contactPhone ? normalizePhone(settings.contactPhone) : null;

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
            <a href="#fonctionnement" className="hidden text-graphite transition-colors hover:text-ink sm:inline">
              Comment ça marche
            </a>
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
      </main>

      <footer>
        <div className="mx-auto flex w-full max-w-[1120px] flex-col gap-4 px-5 py-9 text-[13px] text-muted sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <p>B2B Wheels Collect, le service d&apos;enlèvement de jantes de VBM Wheels Repair.</p>
          <nav aria-label="Informations" className="flex flex-wrap gap-x-5 gap-y-2 sm:pr-16">
            {phone ? (
              <a href={`tel:${phone}`} className="hover:text-ink">
                {formatPhone(phone)}
              </a>
            ) : null}
            {settings.contactEmail ? (
              <a href={`mailto:${settings.contactEmail}`} className="hover:text-ink">
                {settings.contactEmail}
              </a>
            ) : null}
            <a href="#confidentialite" className="hover:text-ink">
              Confidentialité
            </a>
          </nav>
        </div>
      </footer>

      <WhatsappButton number={settings.contactWhatsapp} />
      <PrivacyDialog legal={settings.legal} turnstile={!!settings.turnstileSiteKey} />
    </>
  );
}
