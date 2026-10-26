/**
 * Anmeldeformular, progressive enhancement.
 *
 * Ohne JS: normaler POST auf /api/anmeldung, der Endpoint antwortet mit einer
 * Redirect auf ?status=… . Mit JS: derselbe POST per fetch, Rueckmeldung
 * inline, Fokus auf die erste fehlerhafte Eingabe.
 *
 * Die clientseitige Pruefung ist reine Bequemlichkeit — verbindlich ist die
 * Validierung im Endpoint.
 */
interface Messages {
  errors: Record<string, string>;
  successTitle: string;
  successBody: string;
  errorTitle: string;
  errorBody: string;
}

export function initForm(): void {
  const form = document.querySelector<HTMLFormElement>('[data-form]');
  if (!form) return;

  const raw = form.querySelector<HTMLScriptElement>('[data-form-messages]')?.textContent;
  if (!raw) return;

  let messages: Messages;
  try {
    messages = JSON.parse(raw) as Messages;
  } catch {
    return;
  }

  /**
   * Wie lange das Ausfuellen gedauert hat. Ein Mensch braucht Sekunden,
   * ein Bot nicht — der Endpoint wertet das als eines von mehreren
   * Merkmalen. Gezaehlt wird ab dem Laden der Seite.
   */
  const start = Date.now();
  const elapsed = form.querySelector<HTMLInputElement>('[data-elapsed]');

  const status = form.querySelector<HTMLElement>('[data-status]');
  const statusTitle = form.querySelector<HTMLElement>('[data-status-title]');
  const statusBody = form.querySelector<HTMLElement>('[data-status-body]');
  const submit = form.querySelector<HTMLButtonElement>('[data-submit]');
  const idle = form.querySelector<HTMLElement>('[data-submit-idle]');
  const busy = form.querySelector<HTMLElement>('[data-submit-busy]');

  const setBusy = (state: boolean) => {
    if (submit) submit.disabled = state;
    if (idle) idle.hidden = state;
    if (busy) busy.hidden = !state;
  };

  const clearErrors = () => {
    form.querySelectorAll<HTMLElement>('[data-error-for]').forEach((slot) => {
      slot.textContent = '';
      const field = form.elements.namedItem(slot.dataset.errorFor ?? '');
      if (field instanceof HTMLElement) field.removeAttribute('aria-invalid');
    });
  };

  const showErrors = (fields: Record<string, string>) => {
    let firstInvalid: HTMLElement | null = null;

    for (const [name, message] of Object.entries(fields)) {
      const slot = form.querySelector<HTMLElement>(`[data-error-for="${name}"]`);
      if (slot) slot.textContent = message;

      const field = form.elements.namedItem(name);
      if (field instanceof HTMLElement) {
        field.setAttribute('aria-invalid', 'true');
        firstInvalid ??= field;
      }
    }

    firstInvalid?.focus();
  };

  const showStatus = (state: 'success' | 'error', title: string, body: string) => {
    if (!status) return;
    status.hidden = false;
    status.dataset.state = state;
    if (statusTitle) statusTitle.textContent = title;
    if (statusBody) statusBody.textContent = body;
  };

  /** Spiegelt die Regeln des Endpoints, ersetzt sie nicht. */
  const validate = (data: FormData): Record<string, string> => {
    const errors: Record<string, string> = {};
    const value = (name: string) => String(data.get(name) ?? '').trim();

    if (value('firstName') === '') errors.firstName = messages.errors.firstName ?? '';
    if (value('lastName') === '') errors.lastName = messages.errors.lastName ?? '';

    const email = value('email');
    if (email === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      errors.email = messages.errors.email ?? '';
    }
    if (data.get('consent') === null) errors.consent = messages.errors.consent ?? '';

    return errors;
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors();

    if (elapsed) elapsed.value = String(Date.now() - start);

    const data = new FormData(form);
    const errors = validate(data);

    if (Object.keys(errors).length > 0) {
      showErrors(errors);
      return;
    }

    setBusy(true);

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: data,
      });

      const payload = (await response.json().catch(() => ({}))) as {
        ok?: boolean;
        fields?: Record<string, string>;
        error?: string;
      };

      if (response.ok && payload.ok) {
        form.reset();
        showStatus('success', messages.successTitle, messages.successBody);
        status?.focus?.();
        return;
      }

      if (response.status === 429) {
        showStatus('error', messages.errorTitle, messages.errors.rateLimit ?? messages.errorBody);
        return;
      }

      if (payload.fields) {
        // Serverseitige Feldfehler an denselben Stellen anzeigen.
        showErrors(
          Object.fromEntries(
            Object.keys(payload.fields).map((name) => [
              name,
              messages.errors[name] ?? messages.errors.generic ?? '',
            ]),
          ),
        );
        return;
      }

      showStatus('error', messages.errorTitle, messages.errorBody);
    } catch {
      showStatus('error', messages.errorTitle, messages.errorBody);
    } finally {
      setBusy(false);
    }
  });
}
