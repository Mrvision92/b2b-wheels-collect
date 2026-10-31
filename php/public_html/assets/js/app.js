/**
 * B2B Wheels Collect — formulaire de réservation, sans framework.
 *
 * Le navigateur refait les contrôles du serveur pour répondre tout de suite ;
 * seule la vérification du serveur (app/src/Validator.php) fait foi.
 */
(() => {
  "use strict";

  const form = document.getElementById("booking-form");
  if (!form) return;

  const API = { availability: "api/availability.php", bookings: "api/bookings.php" };
  const MAX_RIMS = Number(form.dataset.maxRims) || 40;
  const TURNSTILE_KEY = form.dataset.turnstile || "";
  const PHOTO_LIMITS = { maxCount: 5, maxTotalBytes: 4 * 1024 * 1024, maxDimension: 1600 };
  const REQUEST_KEY = "rimcollect.requestId";
  const AVAILABILITY_ERROR = "Les disponibilités ne peuvent pas être chargées. Vérifiez votre connexion.";
  const SLOT_GONE = "Ce créneau vient d'être complété. Choisissez-en un autre.";

  const $ = (selector, root = form) => root.querySelector(selector);
  const els = {
    calendar: $(".calendar"),
    monthTitle: $(".month-title"),
    prev: $('[data-month="-1"]'),
    next: $('[data-month="1"]'),
    calendarStatus: $(".calendar-status"),
    slotPicker: $(".slot-picker"),
    slots: $(".slots"),
    summary: $(".summary"),
    formError: $(".form-error"),
    submit: $(".submit-button"),
    rimOther: $(".rim-other"),
    rimCount: $("#rimCount"),
    photosList: $(".photos-list"),
    photosAdd: $(".photos-add-item"),
    photosInput: $("#photos"),
    turnstile: $(".turnstile"),
    confirmation: document.querySelector(".confirmation"),
  };

  const state = {
    data: null,
    status: "loading",
    message: "",
    cache: new Map(),
    seq: 0,
    date: "",
    slot: "",
    savedDay: null,
    errors: {},
    photos: [],
    submitting: false,
    requestId: null,
    turnstileToken: "",
    turnstileWidget: null,
  };

  /* Outils --------------------------------------------------------------- */

  const esc = (value) =>
    String(value).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

  const pad = (n) => String(n).padStart(2, "0");

  /** UUID v4, y compris hors HTTPS (crypto.randomUUID n'y existe pas). */
  function uuid() {
    if (window.crypto && typeof crypto.randomUUID === "function") return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, "0")).join("");
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  }

  function storedRequestId() {
    try {
      return sessionStorage.getItem(REQUEST_KEY);
    } catch {
      return null;
    }
  }

  function storeRequestId(id) {
    try {
      if (id) sessionStorage.setItem(REQUEST_KEY, id);
      else sessionStorage.removeItem(REQUEST_KEY);
    } catch {
      /* navigation privée : l'identifiant en mémoire suffit */
    }
  }

  /* Dates en français ---------------------------------------------------- */

  const DAYS = ["lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"];
  const MONTHS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"];

  const parts = (date) => date.split("-").map(Number);
  const isoWeekday = (date) => {
    const [y, m, d] = parts(date);
    const day = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
    return day === 0 ? 7 : day;
  };
  const daysInMonth = (month) => {
    const [y, m] = parts(month);
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
  };
  const shiftMonth = (month, delta) => {
    const [y, m] = parts(month);
    const t = new Date(Date.UTC(y, m - 1 + delta, 1));
    return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}`;
  };
  const formatDayMonth = (date) => `${DAYS[isoWeekday(date) - 1]} ${parts(date)[2]} ${MONTHS[parts(date)[1] - 1]}`;
  const formatDateLong = (date) => `${formatDayMonth(date)} ${date.slice(0, 4)}`;
  const formatMonthTitle = (month) => `${MONTHS[parts(month)[1] - 1]} ${month.slice(0, 4)}`;
  const capitalize = (value) => value.charAt(0).toUpperCase() + value.slice(1);

  /* Contrôles des champs (mêmes règles et messages que le serveur) -------- */

  const CONTROL = /[\u0000-\u001f\u007f]+/g;
  const CONTROL_EXCEPT_NEWLINE = /[\u0000-\u0009\u000b-\u001f\u007f]+/g;
  const collapse = (v) => String(v ?? "").replace(CONTROL, " ").replace(/\s+/g, " ").trim();
  const EMAIL = /^(?!\.)(?!.*\.\.)[A-Za-z0-9_'+.-]*[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9-]*\.)+[A-Za-z]{2,}$/;

  function normalizePhone(raw) {
    let value = String(raw).trim().replace(/[\s.\-/()]/g, "");
    if (value.startsWith("00")) value = `+${value.slice(2)}`;
    else if (value.startsWith("0")) value = `+32${value.slice(1)}`;
    if (!/^\+[1-9]\d{6,14}$/.test(value)) return null;
    if (value.startsWith("+32")) {
      const national = value.slice(3);
      if (national.startsWith("0")) return null;
      if (national.length !== (national.startsWith("4") ? 9 : 8)) return null;
    }
    return value;
  }

  function singleLine(v, min, max, minMessage, maxMessage) {
    const value = collapse(v);
    if (value.length < min) return minMessage;
    if (value.length > max) return maxMessage;
    return undefined;
  }

  const RULES = {
    garageName: (v) => singleLine(v, 2, 120, "Indiquez le nom du garage.", "Ce nom est trop long (120 caractères maximum)."),
    contactName: (v) =>
      singleLine(v, 3, 120, "Indiquez le prénom et le nom.", "Ce nom est trop long (120 caractères maximum).") ??
      (collapse(v).split(" ").length < 2 ? "Indiquez le prénom et le nom." : undefined),
    phone: (v) => {
      if (!String(v ?? "").trim()) return "Indiquez un numéro de téléphone.";
      if (String(v).length > 40) return "Numéro de téléphone invalide.";
      return normalizePhone(v) ? undefined : "Numéro invalide. Exemple : 0470 12 34 56 ou +32 2 123 45 67.";
    },
    email: (v) => {
      const value = String(v ?? "").trim();
      if (!value) return "Indiquez une adresse email.";
      if (value.length > 254) return "Adresse email trop longue.";
      return EMAIL.test(value) ? undefined : "Adresse email invalide. Exemple : atelier@garage.be";
    },
    street: (v) =>
      singleLine(v, 3, 160, "Indiquez la rue et le numéro.", "Adresse trop longue (160 caractères maximum).") ??
      (/\d/.test(collapse(v)) ? undefined : "Ajoutez le numéro de rue."),
    postalCode: (v) => {
      const value = String(v ?? "").trim();
      if (!value) return "Indiquez le code postal.";
      return /^[1-9]\d{3}$/.test(value) ? undefined : "Code postal belge à 4 chiffres.";
    },
    city: (v) => singleLine(v, 2, 80, "Indiquez la ville.", "Nom de ville trop long."),
    rimCount: (v) => {
      if (v === undefined || v === null) return "Indiquez le nombre de jantes.";
      const n = Number(v);
      if (Number.isNaN(n)) return "Indiquez le nombre de jantes.";
      if (!Number.isInteger(n)) return "Indiquez un nombre entier.";
      if (n < 1) return "Indiquez au moins 1 jante.";
      if (n > MAX_RIMS) return `Pour plus de ${MAX_RIMS} jantes, contactez-nous directement.`;
      return undefined;
    },
    garageReference: (v) => (collapse(v).length > 60 ? "Référence trop longue (60 caractères maximum)." : undefined),
    comment: (v) =>
      String(v ?? "").replace(CONTROL_EXCEPT_NEWLINE, " ").trim().length > 500 ? "Commentaire trop long (500 caractères maximum)." : undefined,
    date: (v) => (/^\d{4}-\d{2}-\d{2}$/.test(v ?? "") ? undefined : "Choisissez une date."),
    slot: (v) => (/^\d{4}$/.test(v ?? "") ? undefined : "Choisissez un créneau."),
  };

  const TEXT_FIELDS = ["garageName", "contactName", "phone", "email", "street", "postalCode", "city", "garageReference", "comment"];
  const ALL_FIELDS = [...TEXT_FIELDS, "rimCount", "photos", "date", "slot"];

  /** Ordre de lecture sur mobile : le premier champ à corriger reçoit le focus. */
  const FIELD_ORDER = ["garageName", "contactName", "phone", "email", "street", "postalCode", "city", "rimCount", "garageReference", "comment", "photos", "date", "slot"];
  const FOCUS_TARGET = {
    rimCount: () => (rimChoice() === "other" ? "#rimCount" : 'input[name="rimChoice"]'),
    date: 'input[name="date"]:checked, input[name="date"]:not(:disabled)',
    slot: 'input[name="slot"]:not(:disabled)',
    photos: "#photos",
  };

  /* Erreurs -------------------------------------------------------------- */

  const ERROR_ICON =
    '<svg viewBox="0 0 16 16" aria-hidden="true" fill="currentColor"><path d="M8 1.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Zm0 8.75a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8ZM8 4.5a.75.75 0 0 0-.75.75v3.5a.75.75 0 0 0 1.5 0v-3.5A.75.75 0 0 0 8 4.5Z"/></svg>';

  function describe(element, message, field) {
    if (message) element.setAttribute("aria-describedby", `${field}-error`);
    else element.removeAttribute("aria-describedby");
  }

  function showError(field, message) {
    const box = document.getElementById(`${field}-error`);
    if (box) {
      box.innerHTML = message ? `${ERROR_ICON}<span>${esc(message)}</span>` : "";
      box.hidden = !message;
    }
    // Relie l'erreur au champ concerné pour les lecteurs d'écran.
    if (field === "rimCount") {
      const other = rimChoice() === "other";
      els.rimCount.setAttribute("aria-invalid", message && other ? "true" : "false");
      describe(els.rimCount, other && message, field);
      describe($(".rim-count"), !other && message, field);
    } else if (TEXT_FIELDS.includes(field) || field === "photos") {
      const input = field === "photos" ? els.photosInput : document.getElementById(field);
      if (field !== "photos") input.setAttribute("aria-invalid", message ? "true" : "false");
      describe(input, message, field);
    } else {
      describe($(`[data-field="${field}"]`), message, field);
    }
  }

  function setError(field, message) {
    state.errors[field] = message || undefined;
    if (field === "slot") showSlotError();
    else showError(field, message);
  }

  /** Remplace toutes les erreurs affichées. */
  function applyErrors(errors) {
    for (const field of ALL_FIELDS) setError(field, errors[field]);
  }

  function showSlotError() {
    showError("slot", state.errors.slot ?? (slotNoLongerFree() ? SLOT_GONE : undefined));
  }

  function showFormError(message) {
    els.formError.textContent = message;
    els.formError.hidden = false;
  }

  function hideFormError() {
    els.formError.hidden = true;
    els.formError.textContent = "";
  }

  function focusFirstError(errors) {
    const first = FIELD_ORDER.find((field) => errors[field]);
    if (!first) return;
    const selector = FOCUS_TARGET[first] ?? `#${first}`;
    const target = form.querySelector(typeof selector === "function" ? selector() : selector);
    if (!target) return;
    target.focus({ preventScroll: true });
    (target.closest("fieldset, div") ?? target).scrollIntoView({ behavior: "smooth", block: "center" });
  }

  /* Champs texte ---------------------------------------------------------- */

  for (const field of TEXT_FIELDS) {
    const input = document.getElementById(field);
    input.addEventListener("input", () => {
      hideFormError();
      if (state.errors[field]) setError(field, RULES[field](input.value));
    });
    input.addEventListener("blur", () => {
      if (input.value !== "") setError(field, RULES[field](input.value));
    });
  }

  /* Nombre de jantes ------------------------------------------------------ */

  const rimChoice = () => form.querySelector('input[name="rimChoice"]:checked')?.value ?? "";
  const rimCountValue = () => (rimChoice() === "other" ? els.rimCount.value : rimChoice() || undefined);

  form.querySelectorAll('input[name="rimChoice"]').forEach((radio) => {
    radio.addEventListener("change", () => {
      const other = rimChoice() === "other";
      els.rimOther.hidden = !other;
      setError("rimCount", undefined);
      hideFormError();
      renderSummary();
      if (other) els.rimCount.focus();
    });
  });

  els.rimCount.addEventListener("input", () => {
    els.rimCount.value = els.rimCount.value.replace(/\D/g, "");
    hideFormError();
    if (state.errors.rimCount) setError("rimCount", RULES.rimCount(els.rimCount.value));
    renderSummary();
  });
  els.rimCount.addEventListener("blur", () => {
    if (els.rimCount.value !== "") setError("rimCount", RULES.rimCount(els.rimCount.value));
  });

  /* Disponibilités -------------------------------------------------------- */

  async function fetchMonth(month) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(`${API.availability}${month ? `?month=${month}` : ""}`, { cache: "no-store", signal: controller.signal });
      const body = await response.json().catch(() => null);
      if (!response.ok || !body?.month) {
        const error = new Error(body?.message || AVAILABILITY_ERROR);
        error.fromServer = true;
        throw error;
      }
      return body;
    } finally {
      clearTimeout(timer);
    }
  }

  const hasAvailableDay = (data) => data.days.some((d) => d.slots.some((s) => s.available));

  function setAvailability(status, data, message = "") {
    state.status = status;
    state.data = data;
    state.message = message;
    renderCalendar();
    renderSlots();
    renderSummary();
  }

  async function load(month, { fresh = false, skipEmpty = false } = {}) {
    const seq = ++state.seq;
    const cached = month && !fresh ? state.cache.get(month) : undefined;
    if (cached) {
      setAvailability("ready", cached);
      return;
    }
    setAvailability("loading", state.data);
    try {
      let data = await fetchMonth(month);
      // En fin de mois, ouvrir directement le mois suivant s'il reste des créneaux.
      if (skipEmpty && !hasAvailableDay(data) && data.month < data.lastMonth) {
        state.cache.set(data.month, data);
        data = await fetchMonth(shiftMonth(data.month, 1));
      }
      if (seq !== state.seq) return;
      state.cache.set(data.month, data);
      setAvailability("ready", data);
    } catch (error) {
      if (seq !== state.seq) return;
      setAvailability("error", state.data, error.fromServer ? error.message : AVAILABILITY_ERROR);
    }
  }

  /** Oublie les mois en cache et recharge le mois affiché (après un conflit). */
  function refresh() {
    state.cache.clear();
    load(state.data?.month ?? null, { fresh: true });
  }

  /* Calendrier ------------------------------------------------------------ */

  function renderCalendar() {
    const { data, status } = state;
    const loading = status === "loading";
    els.monthTitle.innerHTML = data ? esc(formatMonthTitle(data.month)) : '<span class="muted">Chargement…</span>';
    els.prev.disabled = !(data && data.month > data.firstMonth && !loading);
    els.next.disabled = !(data && data.month < data.lastMonth && !loading);
    els.calendar.classList.toggle("is-loading", loading && !!data);

    if (status === "error" && !data) {
      els.calendar.innerHTML = `<div class="calendar-error" role="alert"><p>${esc(state.message)}</p><button type="button" class="outline-button is-small" data-retry>Réessayer</button></div>`;
    } else if (!data) {
      els.calendar.innerHTML = '<span class="skeleton" aria-hidden="true"></span>'.repeat(35);
    } else {
      const days = new Map(data.days.map((d) => [d.date, d]));
      let html = '<span aria-hidden="true"></span>'.repeat(isoWeekday(`${data.month}-01`) - 1);
      for (let i = 1; i <= daysInMonth(data.month); i++) {
        const date = `${data.month}-${pad(i)}`;
        const day = days.get(date);
        const dayState = !day ? "closed" : day.slots.some((s) => s.available) ? "available" : "full";
        const label = `${formatDateLong(date)}${dayState === "full" ? ", complet" : dayState === "closed" ? ", indisponible" : ""}`;
        const classes = ["day", `is-${dayState}`, date === data.today ? "is-today" : ""].join(" ").trim();
        html += `<label class="${classes}"><input type="radio" name="date" value="${date}" class="sr-only" aria-label="${esc(label)}"${dayState !== "available" ? " disabled" : ""}${state.date === date ? " checked" : ""}><span aria-hidden="true">${i}</span></label>`;
      }
      els.calendar.innerHTML = html;
    }

    if (status === "error" && data) {
      els.calendarStatus.innerHTML = `${esc(state.message)} <button type="button" class="text-button" data-retry>Réessayer</button>`;
      els.calendarStatus.hidden = false;
    } else {
      els.calendarStatus.hidden = true;
    }
  }

  els.prev.addEventListener("click", () => state.data && load(shiftMonth(state.data.month, -1)));
  els.next.addEventListener("click", () => state.data && load(shiftMonth(state.data.month, 1)));
  form.addEventListener("click", (event) => {
    if (event.target.closest("[data-retry]")) refresh();
  });

  els.calendar.addEventListener("change", (event) => {
    if (event.target.name !== "date") return;
    const date = event.target.value;
    state.savedDay = state.data?.days.find((d) => d.date === date) ?? null;
    if (state.date !== date) state.slot = "";
    state.date = date;
    setError("date", undefined);
    state.errors.slot = undefined;
    hideFormError();
    renderSlots();
    renderSummary();
  });

  /* Créneaux -------------------------------------------------------------- */

  /** Le jour choisi, mis à jour à chaque rechargement de son mois. */
  function liveDay() {
    const { data } = state;
    return data && state.date.startsWith(data.month) ? data.days.find((d) => d.date === state.date) : undefined;
  }

  const currentDay = () => liveDay() ?? (state.date ? state.savedDay : null);
  const selectedSlot = () => currentDay()?.slots.find((s) => s.key === state.slot);
  const slotNoLongerFree = () => !!state.slot && !!liveDay() && !selectedSlot()?.available;

  function renderSlots() {
    const mode = state.data?.mode ?? "ranges";
    $("legend", els.slotPicker).textContent = mode === "ranges" ? "Plage d'enlèvement" : "Heure d'enlèvement";
    const day = currentDay();
    if (!day) {
      els.slots.innerHTML = '<p class="slots-empty">Choisissez d&#39;abord une date.</p>';
    } else {
      const pills = day.slots
        .map((slot) => {
          const label = esc(slot.label);
          const content = slot.available ? label : `<span class="struck">${label}</span><span class="full-tag">complet</span>`;
          return `<label class="choice"><input type="radio" name="slot" value="${esc(slot.key)}" class="sr-only" aria-label="${label}${slot.available ? "" : ", complet"}"${slot.available ? "" : " disabled"}${state.slot === slot.key ? " checked" : ""}><span aria-hidden="true" class="pill">${content}</span></label>`;
        })
        .join("");
      els.slots.innerHTML = `<p class="slots-day">${esc(formatDayMonth(day.date))}</p><div class="slots-grid${mode === "times" ? " is-times" : ""}">${pills}</div>`;
    }
    showSlotError();
  }

  els.slots.addEventListener("change", (event) => {
    if (event.target.name !== "slot") return;
    state.slot = event.target.value;
    setError("slot", undefined);
    hideFormError();
    renderSummary();
  });

  function renderSummary() {
    const slot = selectedSlot();
    if (state.date && slot) {
      const rims = rimCountValue();
      els.summary.innerHTML = `<strong>${esc(capitalize(formatDayMonth(state.date)))}</strong>, ${esc(slot.label)}${rims ? ` — ${esc(rims)} jante${Number(rims) > 1 ? "s" : ""}` : ""}`;
    } else {
      els.summary.textContent = "Choisissez une date et un créneau pour confirmer.";
    }
  }

  /* Photos ---------------------------------------------------------------- */

  /** Réduit une photo au plus grand côté configuré et la réencode en JPEG. */
  async function shrink(file) {
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
      const scale = Math.min(1, PHOTO_LIMITS.maxDimension / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      canvas.getContext("2d")?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      bitmap.close();
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.82));
      if (!blob) return file;
      return file.type === "image/jpeg" && file.size <= blob.size ? file : blob;
    } catch {
      // Formats que le navigateur ne sait pas lire (HEIC sur certains) : envoyés tels quels.
      return file;
    }
  }

  function renderPhotos(focusIndex) {
    els.photosList.querySelectorAll(".photo").forEach((item) => item.remove());
    state.photos.forEach((photo, index) => {
      const item = document.createElement("li");
      item.className = `photo${photo.ready ? "" : " is-loading"}`;
      item.innerHTML = `<img src="${esc(photo.preview)}" alt="Photo ${index + 1}"><button type="button" class="photo-remove" data-remove="${esc(photo.id)}" aria-label="Retirer la photo ${index + 1}"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>`;
      els.photosList.insertBefore(item, els.photosAdd);
    });
    els.photosAdd.hidden = state.photos.length >= PHOTO_LIMITS.maxCount;
    if (focusIndex !== undefined) {
      const buttons = els.photosList.querySelectorAll(".photo-remove");
      (buttons[Math.min(focusIndex, buttons.length - 1)] ?? els.photosInput).focus();
    }
  }

  els.photosInput.addEventListener("change", () => {
    const files = [...(els.photosInput.files ?? [])];
    if (!files.length) return;
    const remaining = PHOTO_LIMITS.maxCount - state.photos.length;
    const images = files.filter((f) => f.type.startsWith("image/") || /\.(heic|heif)$/i.test(f.name));
    if (images.length < files.length) setError("photos", "Seules les photos peuvent être jointes.");
    else if (images.length > remaining) setError("photos", `${PHOTO_LIMITS.maxCount} photos maximum : les suivantes n'ont pas été ajoutées.`);
    else setError("photos", undefined);

    for (const file of images.slice(0, Math.max(0, remaining))) {
      const photo = { id: uuid(), file, preview: URL.createObjectURL(file), ready: false };
      state.photos.push(photo);
      shrink(file).then((blob) => {
        if (!state.photos.includes(photo)) return;
        if (blob !== file) {
          URL.revokeObjectURL(photo.preview);
          photo.file = blob;
          photo.preview = URL.createObjectURL(blob);
        }
        photo.ready = true;
        renderPhotos();
      });
    }
    els.photosInput.value = "";
    renderPhotos();
  });

  els.photosList.addEventListener("click", (event) => {
    const button = event.target.closest("[data-remove]");
    if (!button) return;
    const index = state.photos.findIndex((p) => p.id === button.dataset.remove);
    if (index === -1) return;
    URL.revokeObjectURL(state.photos[index].preview);
    state.photos.splice(index, 1);
    setError("photos", undefined);
    renderPhotos(index);
  });

  /* Anti-spam Cloudflare Turnstile (seulement s'il est configuré) --------- */

  if (TURNSTILE_KEY) {
    const script = document.createElement("script");
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    script.async = true;
    script.addEventListener("load", () => {
      els.turnstile.hidden = false;
      state.turnstileWidget = window.turnstile.render(els.turnstile, {
        sitekey: TURNSTILE_KEY,
        language: "fr",
        appearance: "interaction-only",
        callback: (token) => (state.turnstileToken = token),
        "expired-callback": () => (state.turnstileToken = ""),
        "error-callback": () => (state.turnstileToken = ""),
      });
    });
    document.head.appendChild(script);
  }

  /* Envoi ----------------------------------------------------------------- */

  function payload() {
    if (!state.requestId) {
      state.requestId = storedRequestId() ?? uuid();
      storeRequestId(state.requestId);
    }
    const value = (id) => document.getElementById(id).value;
    return {
      requestId: state.requestId,
      garageName: value("garageName"),
      contactName: value("contactName"),
      phone: value("phone"),
      email: value("email"),
      street: value("street"),
      postalCode: value("postalCode"),
      city: value("city"),
      rimCount: rimCountValue(),
      comment: value("comment"),
      garageReference: value("garageReference"),
      date: state.date,
      slot: state.slot,
      turnstileToken: state.turnstileToken || undefined,
      website: value("website"),
    };
  }

  function validateAll(body) {
    const errors = {};
    for (const [field, rule] of Object.entries(RULES)) {
      const message = rule(body[field]);
      if (message) errors[field] = message;
    }
    return errors;
  }

  const SPINNER =
    '<svg viewBox="0 0 24 24" aria-hidden="true" class="spinner" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';

  function setSubmitting(submitting) {
    state.submitting = submitting;
    els.submit.disabled = submitting;
    form.setAttribute("aria-busy", String(submitting));
    els.submit.innerHTML = submitting ? `${SPINNER}Confirmation en cours…` : "Confirmer mon enlèvement";
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (state.submitting) return;

    const body = payload();
    const errors = validateAll(body);
    if (state.photos.reduce((sum, p) => sum + p.file.size, 0) > PHOTO_LIMITS.maxTotalBytes) {
      errors.photos = "Les photos sont trop lourdes. Retirez-en une ou deux.";
    }
    if (slotNoLongerFree()) errors.slot = SLOT_GONE;
    if (Object.keys(errors).length > 0) {
      applyErrors(errors);
      showFormError("Vérifiez les champs signalés.");
      focusFirstError(errors);
      return;
    }
    if (state.photos.some((p) => !p.ready)) {
      showFormError("Les photos sont en cours de préparation. Patientez une seconde puis confirmez à nouveau.");
      return;
    }
    if (TURNSTILE_KEY && !state.turnstileToken) {
      showFormError("Vérification anti-spam en cours. Patientez une seconde puis confirmez à nouveau.");
      return;
    }

    setSubmitting(true);
    hideFormError();
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), state.photos.length ? 60000 : 30000);
    try {
      const data = new FormData();
      data.append("data", JSON.stringify(body));
      state.photos.forEach((photo, index) => data.append("photos[]", photo.file, `photo-${index + 1}`));
      const response = await fetch(API.bookings, { method: "POST", body: data, signal: controller.signal });
      const json = await response.json().catch(() => null);

      if (response.ok && json?.ok) {
        state.requestId = null;
        storeRequestId(null);
        showConfirmation(json);
        return;
      }
      if (response.status === 400 && json?.fields) {
        applyErrors(json.fields);
        showFormError(json.message ?? "Vérifiez les champs signalés.");
        focusFirstError(json.fields);
        return;
      }
      if (response.status === 409) {
        // Rien n'a été réservé avec cette demande : la prochaine en utilisera une nouvelle.
        state.requestId = null;
        storeRequestId(null);
        state.slot = "";
        refresh();
      }
      showFormError(
        json?.message ?? "Votre enlèvement n'a pas pu être confirmé. Réessayez dans un instant, vous ne risquez pas de réserver deux fois.",
      );
    } catch {
      showFormError(
        "La connexion a été interrompue avant la confirmation. Vérifiez votre connexion puis cliquez à nouveau : vous ne risquez pas de réserver deux fois.",
      );
    } finally {
      clearTimeout(timer);
      setSubmitting(false);
      if (TURNSTILE_KEY && state.turnstileWidget !== null) {
        state.turnstileToken = "";
        window.turnstile?.reset(state.turnstileWidget);
      }
    }
  });

  /* Confirmation ---------------------------------------------------------- */

  function emailLine(result) {
    const email = `<span class="ink">${esc(result.booking.email)}</span>`;
    if (result.alreadyExisted) {
      return `Cette réservation était déjà enregistrée&nbsp;: aucun doublon n'a été créé. Le récapitulatif a été envoyé à ${email}.`;
    }
    if (result.emailStatus === "pending") {
      return `Votre réservation est bien enregistrée. L'email récapitulatif n'a pas pu partir immédiatement&nbsp;: il sera renvoyé automatiquement à ${email}. Notez votre référence.`;
    }
    if (result.emailStatus === "simulated") return `Un email récapitulatif a été préparé pour ${email}.`;
    return `Un email récapitulatif vient de vous être envoyé à ${email}.`;
  }

  function showConfirmation(result) {
    const { booking, demo } = result;
    const rows = [
      ["Date", booking.dateLabel],
      ["Créneau", booking.slotLabel],
      ["Garage", booking.garageName],
      ["Adresse", booking.address],
      ["Jantes", booking.rimsLabel],
    ];
    if (booking.garageReference) rows.push(["Votre réf.", booking.garageReference]);
    if (booking.photoCount > 0) rows.push(["Photos", `${booking.photosLabel} transmise${booking.photoCount > 1 ? "s" : ""}`]);

    let demoNote = "";
    if (demo.email || demo.calendar) {
      const links = demo.previews
        ? `<p class="demo-links"><a href="${esc(demo.previews.garage)}" target="_blank" rel="noopener">Voir l'email du garage</a><a href="${esc(demo.previews.vbm)}" target="_blank" rel="noopener">Voir la notification VBM</a></p>`
        : "";
      demoNote = `<div class="demo-note"><p>Mode démonstration&nbsp;: la réservation est enregistrée dans la base de données${demo.calendar ? ", l'agenda Google n'est pas connecté" : " et ajoutée à Google Agenda"}${demo.email ? " et aucun email réel n'a été envoyé." : "."}</p>${links}</div>`;
    }

    els.confirmation.innerHTML = `<div class="confirmation-inner">
      <div class="check"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5.5 12.5l4.2 4.2L18.5 8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      <h2 tabindex="-1">Votre enlèvement est confirmé.</h2>
      <p class="confirmation-lead" role="status">${emailLine(result)}</p>
      ${demoNote}
      <div class="reference"><p class="reference-label">Référence</p><p class="reference-value">${esc(booking.reference)}</p></div>
      <dl class="details">${rows.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join("")}</dl>
      <p class="confirmation-help">Pour modifier ou annuler, répondez simplement à l'email de confirmation en indiquant votre référence.</p>
      <button type="button" class="outline-button" data-reset>Planifier un autre enlèvement</button>
    </div>`;
    form.hidden = true;
    els.confirmation.hidden = false;
    const heading = els.confirmation.querySelector("h2");
    heading.focus({ preventScroll: true });
    document.getElementById("reservation").scrollIntoView({ behavior: "smooth", block: "start" });
  }

  els.confirmation.addEventListener("click", (event) => {
    if (!event.target.closest("[data-reset]")) return;
    state.photos.forEach((photo) => URL.revokeObjectURL(photo.preview));
    state.photos = [];
    form.reset();
    els.rimOther.hidden = true;
    state.date = "";
    state.slot = "";
    state.savedDay = null;
    applyErrors({});
    hideFormError();
    renderPhotos();
    els.confirmation.hidden = true;
    els.confirmation.innerHTML = "";
    form.hidden = false;
    refresh();
  });

  /* Démarrage : la date du jour vient du serveur. */
  load(null, { skipEmpty: true });
})();

/* Confidentialité : fenêtre sur la même page, ouverte par tout lien « #confidentialite ». */
(() => {
  "use strict";

  const dialog = document.getElementById("confidentialite");
  if (!dialog || typeof dialog.showModal !== "function") return;
  const HASH = "#confidentialite";

  const sync = () => {
    if (window.location.hash === HASH && !dialog.open) dialog.showModal();
  };
  sync();
  window.addEventListener("hashchange", sync);

  dialog.addEventListener("close", () => {
    if (window.location.hash === HASH) history.replaceState(null, "", window.location.pathname + window.location.search);
  });
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) dialog.close();
  });
  dialog.querySelector(".privacy-close").addEventListener("click", () => dialog.close());
})();
