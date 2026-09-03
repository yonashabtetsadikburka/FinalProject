export function AdminNotifichePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Invio Notifiche</h1>
      </div>
      <div class="card">
        <div class="card-content">
          <form id="admin-notifica-form" style="display: flex; flex-direction: column; gap: var(--space-4); max-width: 600px;">
            <div class="input-group">
              <label class="input-label">Destinatari *</label>
              <select name="destinatari" class="input" required>
                <option value="tutti">Tutti gli utenti</option>
                <option value="acquirenti">Solo acquirenti</option>
                <option value="fornitori">Solo fornitori</option>
                <option value="specifico">Utente specifico</option>
              </select>
            </div>
            <div class="input-group" id="utente-id-group" style="display: none;">
              <label class="input-label">ID Utente</label>
              <input type="number" name="utente_id" class="input" placeholder="ID utente">
            </div>
            <div class="input-group">
              <label class="input-label">Tipo notifica *</label>
              <select name="tipo" class="input" required>

                <option value="SCADENZA">Scadenza</option>
                <option value="ORDINE_DISPONIBILE">Ordine Disponibile</option>
                <option value="ORDINE_INVIATO">Ordine Inviato</option>
                <option value="PROPOSTA_APPROVATA">Proposta Approvata</option>
              </select>
            </div>
            <div class="input-group">
              <label class="input-label">Messaggio *</label>
              <textarea name="messaggio" class="input" rows="4" placeholder="Scrivi il messaggio della notifica..." required></textarea>
            </div>
            <div>
              <button type="submit" class="btn btn-default">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                Invia notifica
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  `;

  document.querySelector('select[name="destinatari"]').addEventListener('change', (e) => {
    document.getElementById('utente-id-group').style.display = e.target.value === 'specifico' ? 'block' : 'none';
  });

  document.getElementById('admin-notifica-form').addEventListener('submit', (e) => {
    e.preventDefault();
    alert('Notifica inviata con successo!');
    e.target.reset();
  });
}
