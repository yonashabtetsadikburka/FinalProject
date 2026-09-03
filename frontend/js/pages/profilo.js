import { getState } from '../state.js';
import { Card, CardContent } from '../components/card.js';

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
              <div class="full-width" style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-default">Salva modifiche</button>
              </div>
            </form>
          </div>
        ` })}
        ${Card({ children: `
          <div class="card-content">
            <h3 style="margin-bottom: var(--space-4);">Sicurezza</h3>
            <div style="display: flex; flex-direction: column; gap: var(--space-4);">
              <div class="input-group">
                <label class="input-label">Password attuale</label>
                <input type="password" class="input" placeholder="••••••••">
              </div>
              <div class="input-group">
                <label class="input-label">Nuova password</label>
                <input type="password" class="input" placeholder="••••••••">
              </div>
              <div style="display: flex; justify-content: flex-end;">
                <button class="btn btn-outline" onclick="alert('Password aggiornata!')">Cambia password</button>
              </div>
            </div>
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

  document.getElementById('profile-form').addEventListener('submit', (e) => {
    e.preventDefault();
    alert('Profilo aggiornato con successo!');
  });
}
