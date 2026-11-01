/** Bouton « œil » des champs mot de passe : afficher ou masquer ce qui est tapé. */
document.addEventListener("click", (event) => {
  const button = event.target.closest(".password-toggle");
  if (!button) return;
  const input = document.getElementById(button.getAttribute("aria-controls"));
  const show = input.type === "password";
  input.type = show ? "text" : "password";
  button.setAttribute("aria-pressed", String(show));
  button.setAttribute("aria-label", show ? "Masquer le mot de passe" : "Afficher le mot de passe");
  input.focus();
});
