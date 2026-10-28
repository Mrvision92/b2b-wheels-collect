/**
 * Coordonnées affichées sur le site et dans les emails.
 *
 * Valeurs reprises du site VBM Wheels Repair : à confirmer par VBM avant la
 * mise en ligne. Les variables NEXT_PUBLIC_CONTACT_PHONE, _EMAIL et
 * _WHATSAPP les remplacent si elles sont définies.
 */

export type Centre = {
  area: string;
  name: string;
  street: string;
  city: string;
  phone: string;
};

export const siteConfig = {
  contact: {
    /** Numéro WhatsApp, format international. */
    whatsapp: "+32 476 21 82 11",
    /** Ligne directe pour les garages. */
    phone: "+32 2 523 20 02",
    email: "info@vbmwheels.com",
  },
  centres: [
    { area: "Laeken", name: "Meram Pneus VBM", street: "Rue Van Gulick 3", city: "1020 Bruxelles", phone: "02 427 33 53" },
    {
      area: "Anderlecht",
      name: "Atlas Pneus VBM",
      street: "Raymond Vander Bruggenlaan 38/42",
      city: "1070 Anderlecht",
      phone: "02 523 20 02",
    },
    { area: "Evere", name: "Evere Pneus VBM", street: "Chaussée de Haecht 847", city: "1140 Evere", phone: "02 657 23 17" },
  ] satisfies Centre[],
};
