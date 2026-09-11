import { apiGet, apiPost } from '../api.js';

let tokenCorrente = null;

function render(html) {
  const container = document.getElementById('attiva-content');
  if (container) container.innerHTML = html;
}

function renderErrore(messaggio) {
  render(`
    <div style="text-align: center; padding: var(--space-4) 0;">
      <p style="color: var(--color-error); margin-bottom: var(--space-4);">${messaggio}</p>
      <a href="index.html" class="btn btn-default">Torna alla pagina di accesso</a>
    </div>
  `);
}

function renderInputLink() {
  render(`
    <p class="text-secondary" style="margin-bottom: var(--space-4);">
      Incolla qui di seguito il link di invito ricevuto dall'amministratore per attivare il tuo account fornitore.
    </p>
    <div class="input-group">
      <label class="input-label">Link di invito</label>
      <input type="text" class="input" id="invito-link-input"
        placeholder="https://.../fornitore-attiva.html?token=..." autocomplete="off">
    </div>
    <div id="attiva-error" style="color: var(--color-error); font-size: var(--text-sm); display: none; margin-bottom: var(--space-3);"></div>
    <button type="button" class="btn btn-default w-full" id="invito-link-submit">Continua</button>
  `);

  document.getElementById('invito-link-submit').addEventListener('click', onSubmitLink);
  document.getElementById('invito-link-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') onSubmitLink();
  });
}

function estraiToken(dato) {
  if (!dato) return '';
  const match = dato.match(/[?&]token=([^&\s"'<>]+)/i);
  return match ? match[1] : dato.trim();
}

async function onSubmitLink() {
  const errorEl = document.getElementById('attiva-error');
  const btn = document.getElementById('invito-link-submit');
  const token = estraiToken(document.getElementById('invito-link-input')?.value || '');
  errorEl.style.display = 'none';

  if (!token) {
    errorEl.textContent = "Inserisci il link di invito ricevuto dall'amministratore.";
    errorEl.style.display = 'block';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Verifica in corso...';
  await avviaAttivazione(token);
}

async function avviaAttivazione(token) {
  tokenCorrente = token;
  try {
    const res = await apiGet(`/fornitori/invito/${encodeURIComponent(token)}`);
    const { nome_azienda, email } = res.dati;
    renderForm(nome_azienda, email);
  } catch (err) {
    renderErrore(err.message || 'Invito non valido.');
  }
}

function renderForm(nomeAzienda, email) {
  render(`
    <div class="text-sm" style="background: var(--color-surface-2); border-radius: var(--radius-md); padding: var(--space-4); margin-bottom: var(--space-4);">
      <div class="text-secondary">Azienda</div>
      <div class="font-medium" id="attiva-nome-azienda">${nomeAzienda}</div>
    </div>
    <div class="input-group">
      <label class="input-label">Email</label>
      <input type="email" class="input" id="attiva-email" value="${email}" readonly>
    </div>
    <div class="input-group">
      <label class="input-label">Password * (min 8 caratteri)</label>
      <div class="input-icon-wrapper">
        <input type="password" name="password" id="attiva-password" class="input" placeholder="••••••••" required minlength="8">
        <button type="button" class="input-icon-btn" onclick="toggleAttivaPasswordVisibility()">
          <span id="attiva-password-toggle-icon">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
          </span>
        </button>
      </div>
    </div>
    <div id="attiva-error" style="color: var(--color-error); font-size: var(--text-sm); display: none; margin-bottom: var(--space-3);"></div>
    <button type="button" class="btn btn-default w-full" id="attiva-submit">Attiva account</button>
  `);

  document.getElementById('attiva-submit').addEventListener('click', onSubmit);
}

window.toggleAttivaPasswordVisibility = function() {
  const input = document.getElementById('attiva-password');
  const icon = document.getElementById('attiva-password-toggle-icon');
  if (!input || !icon) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';

  if (isPassword) {
    icon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
  } else {
    icon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
  }
};

async function onSubmit() {
  const errorEl = document.getElementById('attiva-error');
  const btn = document.getElementById('attiva-submit');
  const password = document.getElementById('attiva-password')?.value || '';
  errorEl.style.display = 'none';

  if (password.length < 8) {
    errorEl.textContent = 'La password deve contenere almeno 8 caratteri.';
    errorEl.style.display = 'block';
    return;
  }

  if (!tokenCorrente) {
    errorEl.textContent = "Token di invito mancante. Riprova inserendo il link ricevuto.";
    errorEl.style.display = 'block';
    return;
  }

  try {
    btn.disabled = true;
    btn.textContent = 'Attivazione in corso...';
    await apiPost('/fornitori/attiva', { token: tokenCorrente, password });
    render(`
      <div style="text-align: center; padding: var(--space-4) 0;">
        <p style="margin-bottom: var(--space-4);">
          <span class="badge badge-success">Account attivato con successo!</span>
        </p>
        <p class="text-secondary" style="margin-bottom: var(--space-4);">Ora puoi accedere con la tua email.</p>
        <a href="index.html?attivato=1" class="btn btn-default w-full">Vai all'accesso</a>
      </div>
    `);
    setTimeout(() => {
      window.location.href = 'index.html?attivato=1';
    }, 4000);
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Attiva account';
    errorEl.textContent = err.message || 'Errore durante l\'attivazione.';
    errorEl.style.display = 'block';
  }
}

(async function init() {
  const token = new URLSearchParams(window.location.search).get('token');

  if (!token) {
    renderInputLink();
    return;
  }

  await avviaAttivazione(token);
})();