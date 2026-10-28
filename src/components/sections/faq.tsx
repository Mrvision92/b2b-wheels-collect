import { bookingConfig } from "@/config/booking";
import { PlusIcon } from "../icons";
import { SectionHeading } from "./section-heading";

const QUESTIONS: { question: string; answer: string }[] = [
  {
    question: "Comment modifier ou annuler un enlèvement ?",
    answer:
      "Répondez simplement à l'email de confirmation en indiquant votre référence, ou contactez-nous par téléphone ou WhatsApp. Nous adaptons la réservation avec vous.",
  },
  {
    question: "Combien de temps à l'avance faut-il réserver ?",
    answer: `Au moins ${bookingConfig.minNoticeHours} heures avant le créneau souhaité, et jusqu'à ${bookingConfig.maxAdvanceDays} jours à l'avance. Pour une demande plus urgente, appelez-nous.`,
  },
  {
    question: "Combien de jantes par enlèvement ?",
    answer: `De 1 à ${bookingConfig.maxRimsPerBooking} jantes par réservation. Pour un volume plus important, contactez-nous afin d'organiser l'enlèvement.`,
  },
  {
    question: "Comment préparer les jantes ?",
    answer:
      "Rassemblez-les à un endroit accessible et précisez dans le commentaire ce qui facilite notre passage : jantes avec ou sans pneus, accès à l'atelier, personne à demander.",
  },
  {
    question: "Puis-je obtenir un devis avant l'enlèvement ?",
    answer:
      "Envoyez-nous quelques photos par WhatsApp ou par email : elles permettent une première orientation. Le devis définitif est établi après examen, car certains défauts, comme un voile léger ou une fissure fine, ne se voient pas sur une photo.",
  },
  {
    question: "Combien de temps les jantes restent-elles à l'atelier ?",
    answer:
      "Cela dépend de leur état, de l'intervention et de la disponibilité de l'atelier. Le délai vous est confirmé au moment du devis, après examen.",
  },
  {
    question: "Que se passe-t-il si une jante ne peut pas être réparée ?",
    answer:
      "Nous vous le disons après examen et vous expliquons pourquoi. Nous convenons ensuite avec vous de la suite : remplacement de la jante ou retour en l'état.",
  },
  {
    question: "Où intervenez-vous ?",
    answer:
      "Partout en Belgique, et hors de Belgique sur demande. Vous pouvez aussi déposer vos jantes dans l'un de nos trois centres bruxellois, à Laeken, Anderlecht ou Evere.",
  },
];

export function FaqSection() {
  return (
    <section id="questions" aria-labelledby="questions-title" className="scroll-mt-16">
      <div className="mx-auto grid max-w-[1120px] gap-10 px-5 py-20 sm:px-6 sm:py-28 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-20">
        <div className="lg:sticky lg:top-24 lg:self-start">
          <SectionHeading id="questions-title" title="Questions fréquentes." subtitle="Avant votre premier enlèvement." />
        </div>
        <div className="border-t border-hairline">
          {QUESTIONS.map((item) => (
            <details key={item.question} className="group border-b border-hairline">
              <summary className="flex cursor-pointer list-none items-start justify-between gap-6 py-5 text-[17px] font-medium tracking-[-0.01em] [&::-webkit-details-marker]:hidden">
                {item.question}
                <PlusIcon className="mt-[3px] size-5 shrink-0 text-muted transition-transform duration-200 group-open:rotate-45" />
              </summary>
              <p className="max-w-[62ch] pb-6 pr-10 text-[16px] leading-relaxed text-muted">{item.answer}</p>
            </details>
          ))}
        </div>
      </div>
    </section>
  );
}
