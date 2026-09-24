import { apiPost, apiGet } from '../api.js';

export function PasswordDimenticataPage() {
  const content = document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="login-page">
      <div class="login-card card">
        <div class="card-content">
          <div class="login-logo"><h1>BuyPool</h1><p>Password dimenticata</p></div>
          <p class="text-secondary text-sm" style="margin-bottom:var(--space-4);">Inserisci la tua email: avviseremo l'amministratore, che ti invierà un link per impostare una nuova password.</p>
          <form class="login-form" id="richiesta-form">
            <div id="richiesta-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;"></div>
            <div class="input-group">
              <label class="input-label">Email</label>
              <input type="email" name="email" class="input" required>
            </div>
            <button type="submit" class="btn btn-default w-full">Invia richiesta</button>
          </form>
          <div id="richiesta-ok" style="display:none;text-align:center;">
            <h3 style="color:var(--color-success);">Richiesta inviata!</h3>
            <p class="text-secondary text-sm">Se l'email è registrata, l'amministratore ti invierà un link per reimpostare la password.</p>
            <a href="#/login" class="btn btn-outline" style="margin-top:var(--space-4);">Torna al login</a>
          </div>
        </div>
      </div>
    </div>`;

  document.getElementById('richiesta-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('richiesta-error');
    errorEl.style.display = 'none';
    try {
      await apiPost('/password/richiesta', { email: e.target.email.value.trim() });
      document.getElementById('richiesta-form').style.display = 'none';
      document.getElementById('richiesta-ok').style.display = 'block';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.style.display = 'block';
    }
  });
}

export async function PasswordResetPage() {
  const content = document.querySelector('.main-content');
  if (!content) return;

  const token = new URLSearchParams(window.location.hash.split('?')[1] || '').get('token') || '';

  if (!token) {
    content.innerHTML = `<div class="login-page"><div class="login-card card"><div class="card-content" style="text-align:center;">
      <h2>Link non valido</h2><a href="#/login" class="btn btn-default" style="margin-top:var(--space-4);">Vai al login</a>
    </div></div></div>`;
    return;
  }

  content.innerHTML = '<div class="login-page"><div class="login-card card"><div class="card-content loading-spinner">Verifica link...</div></div></div>';

  let info;
  try {
    const res = await apiGet(`/password/reset/${encodeURIComponent(token)}`);
    info = res.dati;
  } catch (err) {
    content.innerHTML = `<div class="login-page"><div class="login-card card"><div class="card-content" style="text-align:center;">
      <h2>Link non valido</h2><p class="text-secondary">${err.message}</p>
      <a href="#/login" class="btn btn-default" style="margin-top:var(--space-4);">Vai al login</a>
    </div></div></div>`;
    return;
  }

  content.innerHTML = `
    <div class="login-page">
      <div class="login-card card">
        <div class="card-content">
          <div class="login-logo"><h1>BuyPool</h1><p>Nuova password per ${info.nome || info.email}</p></div>
          <form class="login-form" id="reset-form">
            <div id="reset-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;"></div>
            <div class="input-group">
              <label class="input-label">Nuova password (min 8 caratteri)</label>
              <input type="password" name="password" class="input" required minlength="8">
            </div>
            <button type="submit" class="btn btn-default w-full">Imposta password</button>
          </form>
          <div id="reset-ok" style="display:none;text-align:center;">
            <h3 style="color:var(--color-success);">Password aggiornata!</h3>
            <p class="text-secondary text-sm">Ora puoi accedere con la nuova password.</p>
            <a href="#/login" class="btn btn-default" style="margin-top:var(--space-4);">Vai al login</a>
          </div>
        </div>
      </div>
    </div>`;

  document.getElementById('reset-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('reset-error');
    errorEl.style.display = 'none';
    try {
      await apiPost('/password/reset', { token, password: e.target.password.value });
      document.getElementById('reset-form').style.display = 'none';
      document.getElementById('reset-ok').style.display = 'block';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.style.display = 'block';
    }
  });
}
