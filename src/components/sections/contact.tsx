import type { ReactNode } from "react";
import type { Centre } from "@/config/site";
import { formatPhone, normalizePhone } from "@/lib/phone";
import { MailIcon, PhoneIcon, WhatsappIcon } from "../icons";
import { SectionHeading } from "./section-heading";

const WHATSAPP_MESSAGE = "Bonjour, j'ai une question au sujet d'un enlèvement de jantes.";

type Channel = { icon: ReactNode; label: string; value: string; action: string; href: string; external?: boolean };

function channels(contact: { whatsapp?: string; phone?: string; email?: string }): Channel[] {
  const list: Channel[] = [];
  const whatsapp = contact.whatsapp ? normalizePhone(contact.whatsapp) : null;
  if (whatsapp) {
    list.push({
      icon: <WhatsappIcon />,
      label: "WhatsApp",
      value: formatPhone(whatsapp),
      action: "Écrire sur WhatsApp",
      href: `https://wa.me/${whatsapp.slice(1)}?text=${encodeURIComponent(WHATSAPP_MESSAGE)}`,
      external: true,
    });
  }
  const phone = contact.phone ? normalizePhone(contact.phone) : null;
  if (phone) {
    list.push({ icon: <PhoneIcon />, label: "Téléphone", value: formatPhone(phone), action: "Appeler", href: `tel:${phone}` });
  }
  if (contact.email) {
    list.push({
      icon: <MailIcon />,
      label: "Email",
      value: contact.email,
      action: "Écrire un email",
      href: `mailto:${contact.email}?subject=${encodeURIComponent("Enlèvement de jantes")}`,
    });
  }
  return list;
}

function directions(centre: Centre) {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${centre.name}, ${centre.street}, ${centre.city}`)}`;
}

export function ContactSection({
  contact,
  centres,
}: {
  contact: { whatsapp?: string; phone?: string; email?: string };
  centres: Centre[];
}) {
  const list = channels(contact);
  return (
    <section id="contact" aria-labelledby="contact-title" className="scroll-mt-16 border-t border-hairline bg-surface">
      <div className="mx-auto max-w-[1120px] px-5 py-20 sm:px-6 sm:py-28">
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)] lg:items-end">
          <SectionHeading id="contact-title" title="Une question ?" subtitle="Parlons-en directement." />
          <p className="max-w-[46ch] text-[17px] leading-relaxed text-muted lg:justify-self-end">
            Enlèvement urgent, volume important, photos d&apos;une jante à nous montrer&nbsp;: l&apos;équipe VBM vous répond
            directement.
          </p>
        </div>

        {list.length > 0 ? (
          <ul className={`mt-12 grid gap-4 ${list.length === 3 ? "md:grid-cols-3" : "md:grid-cols-2"}`}>
            {list.map((channel) => (
              <li key={channel.label}>
                <a
                  href={channel.href}
                  {...(channel.external ? { target: "_blank", rel: "noopener noreferrer" } : {})}
                  className="group flex h-full items-center gap-4 border border-hairline bg-surface p-5 transition-colors duration-150 hover:border-ink md:flex-col md:items-start md:gap-0 md:p-7"
                >
                  <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-ink text-white">
                    {channel.icon}
                  </span>
                  <span className="flex min-w-0 flex-col md:mt-6">
                    <span className="text-[14px] text-muted">{channel.label}</span>
                    <span className="mt-0.5 break-words text-[18px] font-semibold tracking-[-0.015em] md:text-[20px]">
                      {channel.value}
                    </span>
                    <span className="mt-1.5 text-[15px] font-medium text-ink underline decoration-line underline-offset-4 group-hover:decoration-ink md:mt-5">
                      {channel.action}
                    </span>
                  </span>
                </a>
              </li>
            ))}
          </ul>
        ) : null}

        <div className="mt-16">
          <h3 className="text-[19px] font-semibold tracking-[-0.015em]">Nos centres à Bruxelles</h3>
          <ul className="mt-5 grid border-t border-hairline md:grid-cols-3 md:border-t-0">
            {centres.map((centre) => {
              const phone = normalizePhone(centre.phone);
              return (
                <li key={centre.area} className="border-b border-hairline py-6 md:border-b-0 md:border-t md:pr-8">
                  <p className="text-[17px] font-semibold tracking-[-0.01em]">{centre.area}</p>
                  <p className="mt-1 text-[15.5px] leading-relaxed text-muted">
                    {centre.name}
                    <br />
                    {centre.street}
                    <br />
                    {centre.city}
                  </p>
                  <p className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[15px] font-medium">
                    {phone ? (
                      <a href={`tel:${phone}`} className="underline decoration-line underline-offset-4 hover:decoration-ink">
                        {centre.phone}
                      </a>
                    ) : null}
                    <a
                      href={directions(centre)}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="underline decoration-line underline-offset-4 hover:decoration-ink"
                    >
                      Itinéraire
                    </a>
                  </p>
                </li>
              );
            })}
          </ul>
        </div>

        <div className="mt-20 flex flex-col items-start gap-6 border-t border-hairline pt-12 sm:flex-row sm:items-center sm:justify-between">
          <p className="max-w-[24ch] text-balance text-[clamp(1.6rem,1.2rem+1.4vw,2.25rem)] font-semibold leading-[1.12] tracking-[-0.025em]">
            Votre prochain enlèvement se planifie en une minute.
          </p>
          <a
            href="#reservation"
            className="inline-flex h-[3.2rem] shrink-0 items-center justify-center rounded-full bg-ink px-7 text-[16.5px] font-medium text-white transition-colors hover:bg-black"
          >
            Planifier un enlèvement
          </a>
        </div>
      </div>
    </section>
  );
}
