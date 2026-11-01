/**
 * B2B Wheels Collect — administration, sans framework.
 * Panneau de détail, confirmations, formulaire d'enlèvement, recherche de
 * garage, cloche. Les pages elles-mêmes sont rendues par le serveur.
 */
(() => {
  "use strict";

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
  const today = document.body.dataset.today ?? "";
  const sheet = document.getElementById("sheet");
  const sheetBody = document.getElementById("sheet-body");
  const sheetTitle = document.getElementById("sheet-title");
  const confirmDialog = document.getElementById("confirm");
  const toastBox = document.querySelector(".toast");
  let dirty = false;
  let currentBooking = null;

  /* Outils --------------------------------------------------------------- */

  const esc = (value) =>
    String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  function uuid() {
    if (window.crypto && typeof crypto.randomUUID === "function") return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, "0")).join("");
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  }

  let toastTimer = null;
  function toast(message, isError = false) {
    if (!toastBox || !message) return;
    toastBox.textContent = message;
    toastBox.classList.toggle("is-error", isError);
    toastBox.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toastBox.hidden = true), isError ? 7000 : 4500);
  }

  /** Recharge la page et affiche le message ensuite. */
  function reloadWith(message) {
    try {
      sessionStorage.setItem("bwc-toast", message);
    } catch {
      /* navigation privée */
    }
    window.location.reload();
  }

  try {
    const pending = sessionStorage.getItem("bwc-toast");
    if (pending) {
      sessionStorage.removeItem("bwc-toast");
      toast(pending);
    }
  } catch {
    /* navigation privée */
  }

  async function api(action, { body, params } = {}) {
    const query = new URLSearchParams({ action, ...(params ?? {}) });
    const response = await fetch(`api.php?${query}`, {
      method: body ? "POST" : "GET",
      headers: body ? { "Content-Type": "application/json", "X-CSRF-Token": csrf } : {},
      body: body ? JSON.stringify(body) : undefined,
      credentials: "same-origin",
    });
    if (response.status === 401) {
      window.location.href = `connexion.php?retour=${encodeURIComponent(location.pathname + location.search)}`;
      throw new Error("Session expirée.");
    }
    const json = await response.json().catch(() => ({ message: "Réponse illisible du serveur." }));
    return { ok: response.ok && json.ok !== false, status: response.status, json };
  }

  /* Fenêtre de confirmation ---------------------------------------------- */

  /**
   * @returns {Promise<FormData|null>} les champs ajoutés, ou null si annulé
   */
  function ask({ title, text, ok, danger = false, extra = "" }) {
    confirmDialog.querySelector("#confirm-title").textContent = title;
    confirmDialog.querySelector(".confirm-text").textContent = text;
    confirmDialog.querySelector(".confirm-extra").innerHTML = extra;
    const okButton = confirmDialog.querySelector(".confirm-ok");
    okButton.textContent = ok;
    okButton.classList.toggle("btn-danger-solid", danger);
    okButton.style.background = danger ? "var(--red)" : "";
    confirmDialog.returnValue = "";
    confirmDialog.showModal();
    okButton.focus();
    return new Promise((resolve) => {
      confirmDialog.addEventListener(
        "close",
        () => resolve(confirmDialog.returnValue === "ok" ? new FormData(confirmDialog.querySelector("form")) : null),
        { once: true },
      );
    });
  }

  /* Panneau de détail ------------------------------------------------------ */

  function openSheet(title) {
    sheetTitle.textContent = title;
    sheetBody.innerHTML = '<p class="loading">Chargement…</p>';
    if (!sheet.open) sheet.showModal();
  }

  async function openBooking(id) {
    openSheet("Enlèvement");
    try {
      const { ok, json } = await api("booking", { params: { id } });
      if (!ok) throw new Error(json.message);
      currentBooking = json.booking;
      sheetTitle.textContent = "Détails de l'enlèvement";
      sheetBody.innerHTML = json.html;
    } catch (error) {
      sheetBody.innerHTML = `<p class="form-error">${esc(error.message || "Impossible d'afficher cet enlèvement.")}</p>`;
    }
  }

  sheet.addEventListener("click", (event) => {
    if (event.target === sheet) sheet.close();
  });
  sheet.addEventListener("close", () => {
    if (dirty) reloadWith("");
  });

  /** Après une action : message, détail rafraîchi, page rechargée à la fermeture. */
  async function afterAction(result, bookingId) {
    if (!result.ok) {
      toast(result.json.message || "L'action n'a pas abouti.", true);
      return;
    }
    if (sheet.open && bookingId) {
      dirty = true;
      toast(result.json.message);
      await openBooking(bookingId);
    } else {
      reloadWith(result.json.message);
    }
  }

  /* Actions --------------------------------------------------------------- */

  async function complete(button) {
    const rims = Number(button.dataset.rims);
    const answer = await ask({
      title: "Confirmer l'enlèvement ?",
      text: `${button.dataset.garage} · ${rims} jante${rims > 1 ? "s" : ""}. ${
        button.dataset.email ? "Le garage recevra un email confirmant que ses jantes ont été récupérées." : "Ce garage n'a pas d'adresse email : il ne sera pas prévenu."
      }`,
      ok: "Oui, marquer enlevé",
    });
    if (!answer) return;
    button.disabled = true;
    await afterAction(await api("complete", { body: { id: Number(button.dataset.id) } }), sheet.open ? Number(button.dataset.id) : null);
    button.disabled = false;
  }

  const notifyBox = (label) =>
    `<label class="check"><input type="checkbox" name="notify" checked> ${esc(label)}</label>`;

  async function confirmBooking(button) {
    const answer = await ask({
      title: "Confirmer cet enlèvement ?",
      text: `${button.dataset.garage} passe de « En attente » à « À enlever ».`,
      ok: "Confirmer",
      extra: button.dataset.email ? notifyBox("Envoyer la confirmation au garage par email") : "",
    });
    if (!answer) return;
    await afterAction(await api("confirm", { body: { id: Number(button.dataset.id), notify: answer.get("notify") === "on" } }), sheet.open ? Number(button.dataset.id) : null);
  }

  async function cancelBooking(button) {
    const answer = await ask({
      title: "Annuler cet enlèvement ?",
      text: `${button.dataset.garage}. Le créneau sera libéré ; la réservation reste dans l'historique.`,
      ok: "Annuler l'enlèvement",
      danger: true,
      extra:
        '<div class="field"><label for="cancel-reason">Motif <span class="optional">Facultatif</span></label><input id="cancel-reason" name="reason" class="input" maxlength="255" placeholder="Garage fermé, doublon…"></div>' +
        (button.dataset.email ? notifyBox("Prévenir le garage par email") : ""),
    });
    if (!answer) return;
    await afterAction(
      await api("cancel", { body: { id: Number(button.dataset.id), reason: answer.get("reason") ?? "", notify: answer.get("notify") === "on" } }),
      sheet.open ? Number(button.dataset.id) : null,
    );
  }

  async function reopen(button) {
    const answer = await ask({
      title: "Remettre « à enlever » ?",
      text: `${button.dataset.garage} : l'enlèvement redevient à faire. Le garage a déjà reçu l'email confirmant l'enlèvement ; prévenez-le si besoin. Il ne recevra pas un second email s'il est de nouveau marqué enlevé.`,
      ok: "Remettre à enlever",
    });
    if (!answer) return;
    await afterAction(await api("reopen", { body: { id: Number(button.dataset.id) } }), sheet.open ? Number(button.dataset.id) : null);
  }

  // Note interne : enregistrée sans fermer le panneau.
  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[data-note]");
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector("button");
    button.disabled = true;
    const result = await api("note", { body: { id: Number(form.dataset.note), note: form.note.value } });
    button.disabled = false;
    toast(result.json.message, !result.ok);
    if (result.ok) dirty = true;
  });

  async function resend(button) {
    button.disabled = true;
    const result = await api("resend", { body: { id: Number(button.dataset.id) } });
    await afterAction(result, Number(button.dataset.bookingId));
  }

  /* Formulaire « Nouvel enlèvement » / « Modifier » ---------------------- */

  function fieldError(form, name, message) {
    const box = form.querySelector(`[data-error-for="${name}"]`);
    if (box) {
      box.textContent = message ?? "";
      box.hidden = !message;
    }
    const input = form.querySelector(`[name="${name}"]`);
    if (input && input.classList.contains("input")) input.setAttribute("aria-invalid", message ? "true" : "false");
  }

  async function loadSlots(form, selected) {
    const date = form.date.value;
    const box = form.querySelector(".slot-choices");
    fieldError(form, "slot", "");
    if (!date) {
      box.innerHTML = '<p class="hint">Choisissez d\'abord une date.</p>';
      return;
    }
    box.innerHTML = '<p class="hint">Chargement des créneaux…</p>';
    try {
      const { json } = await api("slots", { params: { date, exclude: form.id.value || 0 } });
      if (!json.slots?.length) {
        box.innerHTML = `<p class="hint">${esc(json.message || "Aucun créneau ce jour-là.")}</p>`;
        return;
      }
      box.innerHTML = json.slots
        .map((slot) => {
          const note = slot.past ? "passé" : slot.remaining === 0 ? "complet" : `${slot.remaining} place${slot.remaining > 1 ? "s" : ""}`;
          const checked = slot.key === selected ? " checked" : "";
          const disabled = !slot.available && slot.key !== selected ? " disabled" : "";
          return `<label class="slot-choice"><input type="radio" name="slot" value="${esc(slot.key)}"${checked}${disabled}><span>${esc(slot.label)}<small>${note}</small></span></label>`;
        })
        .join("");
    } catch {
      box.innerHTML = '<p class="hint">Les créneaux ne peuvent pas être chargés. Réessayez.</p>';
    }
  }

  function fill(form, data) {
    for (const [name, value] of Object.entries(data)) {
      const input = form.elements.namedItem(name);
      if (input && "value" in input && value !== null && value !== undefined) input.value = value;
    }
  }

  function pickGarage(form, garage) {
    form.garageId.value = garage.garageId ?? garage.id;
    fill(form, {
      garageName: garage.garageName ?? garage.name,
      contactName: garage.contactName ?? garage.contact_name,
      phone: garage.phone,
      email: garage.email,
      street: garage.street,
      postalCode: garage.postalCode ?? garage.postal_code,
      city: garage.city,
    });
    const picked = form.querySelector(".picked");
    picked.querySelector("span").textContent = garage.garageName ?? garage.name;
    picked.hidden = false;
    form.querySelector(".search-field").hidden = true;
    form.querySelector(".suggestions").hidden = true;
    form.querySelector(".garage-picker .hint").hidden = true;
    form.querySelector("[data-garage-update]").hidden = false;
  }

  function openForm(mode, data = {}) {
    openSheet(mode === "edit" ? `Modifier ${data.reference ?? "l'enlèvement"}` : "Nouvel enlèvement");
    const form = document.getElementById("booking-form-template").content.firstElementChild.cloneNode(true);
    sheetBody.replaceChildren(form);
    form.querySelectorAll("[data-mode]").forEach((el) => (el.hidden = el.dataset.mode !== mode));
    form.date.min = mode === "create" ? today : "";
    form.querySelector("[data-submit]").textContent = mode === "edit" ? "Enregistrer les modifications" : "Enregistrer l'enlèvement";

    if (mode === "edit") {
      fill(form, data);
      loadSlots(form, data.slot);
    } else {
      form.requestId.value = uuid();
      if (data.garageId) pickGarage(form, data);
    }

    // Recherche d'un garage existant.
    const search = form.querySelector("#f-garage-search");
    const list = form.querySelector(".suggestions");
    let timer = null;
    search?.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const q = search.value.trim();
        if (q.length < 2) {
          list.hidden = true;
          return;
        }
        const { json } = await api("garages", { params: { q } });
        list.innerHTML = json.garages?.length
          ? json.garages
              .map((g, i) => `<li><button type="button" data-index="${i}"><strong>${esc(g.name)}</strong><small>${esc([g.city, g.contact_name, g.email].filter(Boolean).join(" · "))}</small></button></li>`)
              .join("")
          : '<li><p class="hint" style="padding:10px 12px">Aucun garage trouvé : remplissez les coordonnées ci-dessous.</p></li>';
        list.hidden = false;
        list.onclick = (event) => {
          const button = event.target.closest("button[data-index]");
          if (button) pickGarage(form, json.garages[Number(button.dataset.index)]);
        };
      }, 250);
    });
    form.querySelector("[data-unpick]")?.addEventListener("click", () => {
      form.garageId.value = "";
      form.querySelector(".picked").hidden = true;
      form.querySelector(".search-field").hidden = false;
      form.querySelector(".garage-picker .hint").hidden = false;
      form.querySelector("[data-garage-update]").hidden = true;
      search.value = "";
      search.focus();
    });

    form.date.addEventListener("change", () => loadSlots(form, form.querySelector('input[name="slot"]:checked')?.value));

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      form.querySelectorAll("[data-error-for]").forEach((box) => fieldError(form, box.dataset.errorFor, ""));
      const errorBox = form.querySelector(".form-error");
      errorBox.hidden = true;
      const values = Object.fromEntries(new FormData(form));
      const body = {
        ...values,
        id: values.id ? Number(values.id) : undefined,
        garageId: values.garageId ? Number(values.garageId) : null,
        slot: values.slot ?? "",
        updateGarage: values.updateGarage === "on",
        notifyGarage: values.notifyGarage === "on",
        notifyChange: values.notifyChange === "on",
      };
      if (!body.slot) {
        fieldError(form, "slot", "Choisissez un créneau.");
        return;
      }
      const submit = form.querySelector("[data-submit]");
      submit.disabled = true;
      try {
        const result = await api(mode === "edit" ? "update" : "create", { body });
        if (result.ok) {
          if (mode === "edit") {
            dirty = true;
            toast(result.json.message);
            await openBooking(body.id);
          } else {
            dirty = false;
            sheet.close();
            reloadWith(result.json.message);
          }
          return;
        }
        const fields = result.json.fields ?? {};
        Object.entries(fields).forEach(([name, message]) => fieldError(form, name, message));
        errorBox.textContent = result.json.message || "L'enlèvement n'a pas pu être enregistré.";
        errorBox.hidden = false;
        if (fields.slot) loadSlots(form, "");
        form.querySelector('[aria-invalid="true"], .field-error:not([hidden])')?.scrollIntoView({ block: "center", behavior: "smooth" });
      } catch {
        errorBox.textContent = "La connexion a été interrompue. Réessayez : l'enlèvement ne sera pas enregistré deux fois.";
        errorBox.hidden = false;
      } finally {
        submit.disabled = false;
      }
    });
    (mode === "edit" ? form.garageName : search)?.focus();
  }

  /* Photos en grand ---------------------------------------------------------- */

  const lightbox = document.getElementById("lightbox");
  let gallery = [];
  let galleryIndex = 0;

  function showPhoto(index) {
    galleryIndex = (index + gallery.length) % gallery.length;
    lightbox.querySelector("img").src = gallery[galleryIndex].url;
    lightbox.querySelector("img").alt = gallery[galleryIndex].alt;
    lightbox.querySelector(".lightbox-count").textContent = gallery.length > 1 ? `${galleryIndex + 1} / ${gallery.length}` : "";
    lightbox.querySelectorAll(".lightbox-nav").forEach((button) => (button.hidden = gallery.length < 2));
  }

  function openPhoto(link) {
    gallery = [...link.closest("[data-gallery]").querySelectorAll("[data-photo]")].map((a) => ({ url: a.dataset.photo, alt: a.querySelector("img")?.alt ?? "" }));
    showPhoto(Number(link.dataset.index));
    lightbox.showModal();
  }

  lightbox?.addEventListener("click", (event) => {
    const step = event.target.closest("[data-lightbox-step]");
    if (step) showPhoto(galleryIndex + Number(step.dataset.lightboxStep));
    else if (event.target === lightbox) lightbox.close();
  });
  lightbox?.addEventListener("keydown", (event) => {
    if (event.key === "ArrowRight") showPhoto(galleryIndex + 1);
    if (event.key === "ArrowLeft") showPhoto(galleryIndex - 1);
  });
  // Glisser du doigt sur téléphone.
  let touchX = null;
  lightbox?.addEventListener("touchstart", (event) => (touchX = event.touches[0].clientX), { passive: true });
  lightbox?.addEventListener("touchend", (event) => {
    if (touchX === null || gallery.length < 2) return;
    const delta = event.changedTouches[0].clientX - touchX;
    if (Math.abs(delta) > 50) showPhoto(galleryIndex + (delta < 0 ? 1 : -1));
    touchX = null;
  });

  /* Délégation des clics --------------------------------------------------- */

  document.addEventListener("click", (event) => {
    const photoLink = event.target.closest("[data-photo]");
    if (photoLink && lightbox) {
      event.preventDefault();
      openPhoto(photoLink);
      return;
    }
    const target = event.target.closest(
      "[data-booking], [data-action], [data-edit], [data-new-booking], [data-close], [data-edit-garage], [data-cancel-garage], .bell-button",
    );
    if (!target) {
      document.querySelectorAll(".bell-panel:not([hidden])").forEach((panel) => {
        if (!panel.parentElement.contains(event.target)) closeBell(panel.parentElement);
      });
      return;
    }
    if (target.matches("[data-close]")) {
      target.closest("dialog")?.close();
    } else if (target.matches(".bell-button")) {
      toggleBell(target.closest("[data-bell]"));
    } else if (target.matches("[data-new-booking]")) {
      openForm("create", target.dataset.newBooking ? JSON.parse(target.dataset.newBooking) : {});
    } else if (target.matches("[data-edit]")) {
      if (currentBooking) openForm("edit", currentBooking);
    } else if (target.matches("[data-action]")) {
      const action = target.dataset.action;
      if (action === "complete") complete(target);
      else if (action === "confirm") confirmBooking(target);
      else if (action === "cancel") cancelBooking(target);
      else if (action === "resend") resend(target);
      else if (action === "reopen") reopen(target);
    } else if (target.matches("[data-booking]")) {
      document.querySelectorAll("[data-bell]").forEach(closeBell);
      openBooking(Number(target.dataset.booking));
    } else if (target.matches("[data-edit-garage], [data-cancel-garage]")) {
      const editing = target.matches("[data-edit-garage]");
      document.querySelector("[data-garage-form]").hidden = !editing;
      document.querySelector("[data-garage-info]").hidden = editing;
    }
  });

  /* Cloche ------------------------------------------------------------------ */

  function toggleBell(bell) {
    const panel = bell.querySelector(".bell-panel");
    const open = panel.hidden;
    document.querySelectorAll("[data-bell]").forEach(closeBell);
    if (!open) return;
    panel.hidden = false;
    bell.querySelector(".bell-button").setAttribute("aria-expanded", "true");
    try {
      localStorage.setItem("bwc-bell-seen", bell.dataset.latest);
    } catch {
      /* navigation privée */
    }
    document.querySelectorAll(".bell-dot").forEach((dot) => (dot.hidden = true));
  }

  function closeBell(bell) {
    bell.querySelector(".bell-panel").hidden = true;
    bell.querySelector(".bell-button").setAttribute("aria-expanded", "false");
  }

  document.querySelectorAll("[data-bell]").forEach((bell) => {
    let seen = 0;
    try {
      seen = Number(localStorage.getItem("bwc-bell-seen") ?? 0);
    } catch {
      /* navigation privée */
    }
    bell.querySelector(".bell-dot").hidden = !(Number(bell.dataset.latest) > seen);
  });

  /* Recherche en direct (listes) -------------------------------------------- */

  document.querySelectorAll("form[data-live-search]").forEach((form) => {
    const input = form.querySelector('input[type="search"]');
    let timer = null;
    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        try {
          sessionStorage.setItem("bwc-search-focus", "1");
        } catch {
          /* navigation privée */
        }
        form.requestSubmit();
      }, 450);
    });
    try {
      if (sessionStorage.getItem("bwc-search-focus")) {
        sessionStorage.removeItem("bwc-search-focus");
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
      }
    } catch {
      /* navigation privée */
    }
  });

  /* Fiche garage : modification et fusion ---------------------------------- */

  const garageForm = document.querySelector("[data-garage-form]");
  garageForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    garageForm.querySelectorAll("[data-error-for]").forEach((box) => fieldError(garageForm, box.dataset.errorFor, ""));
    const result = await api("garage-update", { body: { id: Number(garageForm.dataset.garageForm), ...Object.fromEntries(new FormData(garageForm)) } });
    if (result.ok) {
      reloadWith(result.json.message);
      return;
    }
    Object.entries(result.json.fields ?? {}).forEach(([name, message]) => fieldError(garageForm, name, message));
    const box = garageForm.querySelector(".form-error");
    box.textContent = result.json.message;
    box.hidden = false;
  });

  const mergeInput = document.querySelector("[data-merge-search]");
  if (mergeInput) {
    const results = document.querySelector("[data-merge-results]");
    let timer = null;
    mergeInput.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const q = mergeInput.value.trim();
        if (q.length < 2) {
          results.hidden = true;
          return;
        }
        const { json } = await api("garages", { params: { q, exclude: mergeInput.dataset.mergeSearch } });
        results.innerHTML = json.garages?.length
          ? json.garages.map((g, i) => `<li><button type="button" data-index="${i}"><strong>${esc(g.name)}</strong><small>${esc([g.city, g.contact_name, g.email].filter(Boolean).join(" · "))}</small></button></li>`).join("")
          : '<li><p class="hint" style="padding:10px 12px">Aucune autre fiche trouvée.</p></li>';
        results.hidden = false;
        results.onclick = async (event) => {
          const button = event.target.closest("button[data-index]");
          if (!button) return;
          const other = json.garages[Number(button.dataset.index)];
          const answer = await ask({
            title: "Fusionner les deux fiches ?",
            text: `Les réservations de « ${other.name} » seront rattachées à ce garage, puis la fiche « ${other.name} » sera supprimée. Cette opération ne peut pas être annulée.`,
            ok: "Fusionner",
            danger: true,
          });
          if (!answer) return;
          const result = await api("garage-merge", { body: { source: other.id, target: Number(mergeInput.dataset.mergeSearch) } });
          if (result.ok) reloadWith(result.json.message);
          else toast(result.json.message, true);
        };
      }, 250);
    });
  }

  document.querySelector("[data-print]")?.addEventListener("click", () => window.print());

  document.querySelector("[data-erase-garage]")?.addEventListener("click", async (event) => {
    const button = event.currentTarget;
    const answer = await ask({
      title: "Effacer les données de ce garage ?",
      text: `${button.dataset.garage} : nom, contacts, adresses, commentaires et photos seront effacés de toutes ses réservations. Cette action est définitive.`,
      ok: "Effacer définitivement",
      danger: true,
    });
    if (!answer) return;
    const result = await api("garage-erase", { body: { id: Number(button.dataset.eraseGarage) } });
    if (result.ok) {
      try {
        sessionStorage.setItem("bwc-toast", result.json.message);
      } catch {
        /* navigation privée */
      }
      window.location.assign("garages.php");
    } else {
      toast(result.json.message, true);
    }
  });

  /* Planning : vue « Jour » par défaut sur téléphone ------------------------ */

  if (/planning\.php$/.test(location.pathname)) {
    const params = new URLSearchParams(location.search);
    if (!params.has("vue") && window.matchMedia("(max-width: 859px)").matches) {
      params.set("vue", "day");
      location.replace(`?${params}`);
    }
  }
})();
