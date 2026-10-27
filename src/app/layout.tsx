import type { Metadata, Viewport } from "next";
import { Mona_Sans } from "next/font/google";
import "./globals.css";

const mona = Mona_Sans({
  variable: "--font-mona",
  subsets: ["latin", "latin-ext"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "RimCollect — Enlèvement professionnel de jantes",
  description:
    "Planifiez l'enlèvement de vos jantes directement dans votre garage, en quelques clics. Service réservé aux professionnels de l'automobile.",
  applicationName: "RimCollect",
  formatDetection: { telephone: false, email: false, address: false },
  robots: { index: true, follow: true },
};

export const viewport: Viewport = {
  themeColor: "#f8f8f8",
  colorScheme: "light",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="fr-BE" className={mona.variable}>
      <body className="min-h-dvh">{children}</body>
    </html>
  );
}
