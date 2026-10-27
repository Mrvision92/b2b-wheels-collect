import Link from "next/link";
import { bookingConfig } from "@/config/booking";
import { BookingForm } from "@/components/booking/booking-form";
import { Logo } from "@/components/logo";
import { PrivacyDialog } from "@/components/privacy-dialog";
import { getPublicSettings } from "@/lib/env";
import { formatPhone, normalizePhone } from "@/lib/phone";

function contactLinks(phone?: string, email?: string) {
  const normalized = phone ? normalizePhone(phone) : null;
  return {
    phone: normalized ? { href: `tel:${normalized}`, label: formatPhone(normalized) } : null,
    email: email ? { href: `mailto:${email}`, label: email } : null,
  };
}

export default function Home() {
  const settings = getPublicSettings();
  const contact = contactLinks(settings.contactPhone, settings.contactEmail);
  const primaryContact = contact.phone ?? contact.email;
  const demoParts = [settings.demo.calendar ? "Google Agenda" : null, settings.demo.email ? "les emails" : null].filter(Boolean);

  return (
    <>
      {demoParts.length > 0 ? (
        <div className="border-b border-hairline bg-fill px-5 py-2 text-center text-[13px] leading-snug text-graphite">
          Mode démonstration&nbsp;: {demoParts.join(" et ")} {demoParts.length > 1 ? "sont simulés" : "est simulé"}.
          {settings.demo.email ? " Aucun email réel n'est envoyé." : ""}
        </div>
      ) : null}

      <header className="mx-auto flex w-full max-w-[1120px] items-center justify-between px-5 py-5 sm:px-6">
        <Link href="/" aria-label="RimCollect, accueil" className="rounded-sm text-ink">
          <Logo />
        </Link>
        <div className="flex items-center gap-5 text-[14px]">
          <span className="hidden text-muted sm:inline">Service professionnel</span>
          {primaryContact ? (
            <a
              href={primaryContact.href}
              className="rounded-full border border-line px-3.5 py-1.5 font-medium text-ink transition-colors hover:border-ink"
            >
              Contact
            </a>
          ) : (
            <span className="text-muted sm:hidden">Service professionnel</span>
          )}
        </div>
      </header>

      <main id="contenu">
        <div className="px-5 pb-9 pt-7 text-center sm:pb-16 sm:pt-16">
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
      </main>

      <footer className="mx-auto mt-16 w-full max-w-[1120px] px-5 pb-10 sm:px-6">
        <div className="flex flex-col gap-3 border-t border-hairline pt-6 text-[13px] text-muted sm:flex-row sm:items-center sm:justify-between">
          <p>RimCollect, le service d&apos;enlèvement de VBM Wheels Repair.</p>
          <nav aria-label="Informations" className="flex flex-wrap gap-x-5 gap-y-2">
            {contact.phone ? (
              <a href={contact.phone.href} className="hover:text-ink">
                {contact.phone.label}
              </a>
            ) : null}
            {contact.email ? (
              <a href={contact.email.href} className="hover:text-ink">
                {contact.email.label}
              </a>
            ) : null}
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
