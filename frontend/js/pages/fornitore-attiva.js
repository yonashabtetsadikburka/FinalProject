import { API_URL } from '../constants.js';

async function publicPost(path, body) {
  const res = await fetch(`${API_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.errore?.messaggio || 'Errore del server');
  return data;
}

async function publicGet(path) {
  const res = await fetch(`${API_URL}${path}`);
  const data = await res.json();
  if (!res.ok) throw new Error(data.errore?.messaggio || 'Errore del server');
  return data;
}

export async function FornitoreAttivaPage() {
  const content = document.querySelector('.main-content');
  if (!content) return;

  // Token dal query string (fornitore-attiva.html) o dall'hash (index.html#/fornitore/attiva)
  const token = new URLSearchParams(window.location.search).get('token')
    || new URLSearchParams(window.location.hash.split('?')[1] || '').get('token')
    || '';

  if (!token) {
    content.innerHTML = `<div class="login-page"><div class="login-card card"><div class="card-content" style="text-align:center;">
      <h2>Link non valido</h2><p class="text-secondary">Token di invito mancante.</p>
      <a href="index.html#/login" class="btn btn-default" style="margin-top:var(--space-4);">Vai al login</a>
    </div></div></div>`;
    return;
  }

  content.innerHTML = '<div class="login-page"><div class="login-card card"><div class="card-content loading-spinner">Verifica invito...</div></div></div>';

  let invito;
  try {
    const res = await publicGet(`/fornitori/invito/${encodeURIComponent(token)}`);
    invito = res.dati;
  } catch (err) {
    content.innerHTML = `<div class="login-page"><div class="login-card card"><div class="card-content" style="text-align:center;">
      <h2>Invito non valido</h2><p class="text-secondary">${err.message}</p>
      <a href="index.html#/login" class="btn btn-default" style="margin-top:var(--space-4);">Vai al login</a>
    </div></div></div>`;
    return;
  }

  content.innerHTML = `
    <div class="login-page">
      <div class="login-card card">
        <div class="card-content">
          <div class="login-logo"><h1>BuyPool</h1><p>Attivazione account fornitore</p></div>
          <p class="text-secondary" style="margin-bottom:var(--space-4);">${invito.nome_azienda} &middot; ${invito.email}</p>
          <form class="login-form" id="attiva-form">
            <div id="attiva-error" style="color: var(--color-error); font-size: var(--text-sm); display: none;"></div>
            <div class="input-group">
              <label class="input-label">Nome referente</label>
              <input type="text" name="nome" class="input" required>
            </div>
            <div class="input-group">
              <label class="input-label">Cognome referente</label>
              <input type="text" name="cognome" class="input">
            </div>
            <div class="input-group">
              <label class="input-label">Password (min 8 caratteri)</label>
              <input type="password" name="password" class="input" required minlength="8">
            </div>
            <button type="submit" class="btn btn-default w-full">Attiva account</button>
          </form>
          <div id="attiva-ok" style="display:none;text-align:center;">
            <h3 style="color:var(--color-success);">Account attivato!</h3>
            <p class="text-secondary">Ora puoi accedere con la tua email e password.</p>
            <a href="index.html#/login" class="btn btn-default" style="margin-top:var(--space-4);">Vai al login</a>
          </div>
        </div>
      </div>
    </div>`;

  document.getElementById('attiva-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('attiva-error');
    errorEl.style.display = 'none';
    try {
      await publicPost('/fornitori/attiva', {
        token,
        nome: e.target.nome.value.trim(),
        cognome: e.target.cognome.value.trim(),
        password: e.target.password.value
      });
      document.getElementById('attiva-form').style.display = 'none';
      document.getElementById('attiva-ok').style.display = 'block';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.style.display = 'block';
    }
  });
}
