import { normalizePhone } from "@/lib/phone";
import { WhatsappIcon } from "./icons";

const MESSAGE = "Bonjour, j'ai une question au sujet d'un enlèvement de jantes.";

/** Floating WhatsApp shortcut, bottom right, on every screen size. */
export function WhatsappButton({ number }: { number?: string }) {
  const phone = number ? normalizePhone(number) : null;
  if (!phone) return null;
  return (
    <a
      href={`https://wa.me/${phone.slice(1)}?text=${encodeURIComponent(MESSAGE)}`}
      target="_blank"
      rel="noopener noreferrer"
      aria-label="Nous écrire sur WhatsApp"
      title="Nous écrire sur WhatsApp"
      className="fixed bottom-[max(1rem,env(safe-area-inset-bottom))] right-4 z-40 flex size-14 items-center justify-center rounded-full bg-[#25d366] text-white shadow-[0_8px_24px_rgb(0_0_0/0.18)] transition-transform duration-150 hover:scale-105 focus-visible:outline-offset-4 sm:right-6 sm:bottom-6"
    >
      <WhatsappIcon className="size-7" />
    </a>
  );
}
