import type { ReactNode } from "react";

/** Line icons drawn on a 24 px grid with the same stroke as the logo. */
function Icon({ children, className = "size-6" }: { children: ReactNode; className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      aria-hidden="true"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {children}
    </svg>
  );
}

type IconProps = { className?: string };

export const CameraIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M4 8.5h3l1.5-2.5h7L17 8.5h3a1 1 0 0 1 1 1V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5a1 1 0 0 1 1-1z" />
    <circle cx="12" cy="13.3" r="3.3" />
  </Icon>
);

export const CloseIcon = (p: IconProps) => (
  <Icon {...p}>
    <path d="M6 6l12 12M18 6 6 18" />
  </Icon>
);

export const WhatsappIcon = ({ className = "size-6" }: IconProps) => (
  <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor">
    <path d="M12.04 2.5a9.45 9.45 0 0 0-8.1 14.3L2.6 21.5l4.83-1.27A9.45 9.45 0 1 0 12.04 2.5Zm0 17.27a7.82 7.82 0 0 1-3.99-1.09l-.29-.17-2.87.75.77-2.8-.19-.29a7.83 7.83 0 1 1 6.57 3.6Zm4.29-5.86c-.24-.12-1.4-.69-1.61-.77-.22-.08-.38-.12-.53.12-.16.23-.61.77-.75.93-.14.16-.28.18-.51.06-.24-.12-1-.37-1.9-1.17a7.1 7.1 0 0 1-1.32-1.64c-.14-.24-.02-.37.1-.48.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.53-1.28-.73-1.75-.19-.46-.39-.4-.53-.4h-.45a.87.87 0 0 0-.63.29 2.65 2.65 0 0 0-.83 1.97 4.6 4.6 0 0 0 .97 2.45c.12.16 1.67 2.55 4.05 3.58.57.24 1.01.39 1.35.5.57.18 1.09.15 1.5.09.46-.07 1.4-.57 1.6-1.12.2-.55.2-1.02.14-1.12-.06-.1-.22-.16-.45-.28Z" />
  </svg>
);
