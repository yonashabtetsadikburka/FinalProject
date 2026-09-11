import { getState, saveSession } from '../state.js';
import { apiPut } from '../api.js';
import { Card, CardContent } from '../components/card.js';

function showToast(messaggio, tipo = 'success') {
  const toast = document.createElement('div');
  toast.className = `toast toast-${tipo}`;
  toast.innerHTML = `<div class="toast-content"><div class="toast-title">${messaggio}</div></div>`;
  document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}

export function ProfiloPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const { user } = getState();

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Profilo</h1>
      </div>
      <div style="display: flex; flex-direction: column; gap: var(--space-6); max-width: 800px;">
        ${Card({ children: `
          <div class="card-content">
            <h3 style="margin-bottom: var(--space-4);">Informazioni Personali</h3>
            <form class="profile-form" id="profile-form">
              <div class="input-group">
                <label class="input-label">Nome</label>
                <input type="text" name="nome" class="input" value="${user?.nome || ''}">
              </div>
              <div class="input-group">
                <label class="input-label">Cognome</label>
                <input type="text" name="cognome" class="input" value="${user?.cognome || ''}">
              </div>
              <div class="input-group">
                <label class="input-label">Email</label>
                <input type="email" name="email" class="input" value="${user?.email || ''}">
              </div>
              <div class="input-group">
                <label class="input-label">Telefono</label>
                <input type="tel" name="telefono" class="input" value="${user?.telefono || ''}">
              </div>
              <div class="input-group full-width">
                <label class="input-label">Indirizzo</label>
                <input type="text" name="indirizzo" class="input" value="${user?.indirizzo || ''}">
              </div>
              ${user?.ruolo === 'fornitore' ? `
                <div class="input-group full-width">
                  <label class="input-label">Nome Azienda</label>
                  <input type="text" name="nome_azienda" class="input" value="${user?.fornitore?.nome_azienda || ''}">
                </div>
              ` : ''}
              <div class="full-width" style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-default">Salva modifiche</button>
              </div>
            </form>
          </div>
        ` })}
        ${Card({ children: `
          <div class="card-content">
            <h3 style="margin-bottom: var(--space-4);">Sicurezza</h3>
            <form class="profile-form" id="password-form">
              <div class="input-group">
                <label class="input-label">Nuova password</label>
                <input type="password" id="new-password" class="input" placeholder="••••••••" minlength="8">
              </div>
              <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-outline">Cambia password</button>
              </div>
            </form>
          </div>
        ` })}
        <div class="danger-zone">
          <h3 class="danger-zone-title">Zona Pericolosa</h3>
          <p class="danger-zone-desc">Elimina il tuo account e tutti i dati associati. Questa azione non puo' essere annullata.</p>
          <div style="display: flex; gap: var(--space-3);">
            <button class="btn btn-outline" onclick="alert('Esportazione dati in corso...')">Esporta dati</button>
            <button class="btn btn-destructive" onclick="if(confirm('Sei sicuro?')) alert('Account eliminato!')">Elimina account</button>
          </div>
        </div>
      </div>
    </div>
  `;

  document.getElementById('profile-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const payload = {
      nome: form.nome.value.trim(),
      cognome: form.cognome.value.trim(),
      email: form.email.value.trim(),
      telefono: form.telefono.value.trim(),
      indirizzo: form.indirizzo.value.trim()
    };
    if (form.nome_azienda) payload.nome_azienda = form.nome_azienda.value.trim();
    try {
      const res = await apiPut('/profilo', payload);
      saveSession(res.dati, getState().token);
      showToast('Profilo aggiornato con successo!');
    } catch (err) {
      showToast(err.message || 'Errore aggiornamento profilo', 'error');
    }
  });

  document.getElementById('password-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const password = document.getElementById('new-password').value;
    if (!password || password.length < 8) {
      showToast('La password deve avere almeno 8 caratteri', 'error');
      return;
    }
    try {
      await apiPut('/profilo', { password });
      document.getElementById('new-password').value = '';
      showToast('Password aggiornata!');
    } catch (err) {
      showToast(err.message || 'Errore aggiornamento password', 'error');
    }
  });
}
