import { apiGet } from '../../api.js';

export async function AdminNotifichePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  let utenti = [];
  try {
    const res = await apiGet('/utenti');
    utenti = res.dati || [];
  } catch (e) {}

  const utentiOptions = utenti.map(u => `<option value="${u.id}">${u.nome} ${u.cognome || ''} (${u.email})</option>`).join('');

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header"><h1>Invio Notifiche</h1></div>
      <div class="card"><div class="card-content">
        <form id="admin-notifica-form" style="display:flex;flex-direction:column;gap:var(--space-4);max-width:600px;">
          <div class="input-group">
            <label class="input-label">Destinatario *</label>
            <select name="id_utente" class="input" required>
              <option value="">Seleziona un utente...</option>
              ${utentiOptions}
            </select>
          </div>
          <div class="input-group">
            <label class="input-label">Tipo notifica *</label>
            <select name="tipo" class="input" required>
              <option value="SCADENZA">Scadenza</option>
              <option value="ORDINE_DISPONIBILE">Ordine Disponibile</option>
              <option value="ORDINE_INVIATO">Ordine Inviato</option>
              <option value="MOQ_RAGGIUNTO">MOQ Raggiunto</option>
              <option value="PROPOSTA_APPROVATA">Proposta Approvata</option>
              <option value="SISTEMA">Sistema</option>
            </select>
          </div>
          <div class="input-group">
            <label class="input-label">Titolo *</label>
            <input type="text" name="titolo" class="input" placeholder="Titolo della notifica" required>
          </div>
          <div class="input-group">
            <label class="input-label">Messaggio *</label>
            <textarea name="messaggio" class="input" rows="4" placeholder="Scrivi il messaggio..." required></textarea>
          </div>
          <div id="notifica-msg" style="font-size:var(--text-sm);display:none;"></div>
          <div><button type="submit" class="btn btn-default">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            Invia notifica
          </button></div>
        </form>
      </div></div>
    </div>`;

  document.getElementById('admin-notifica-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const msgEl = document.getElementById('notifica-msg');
    try {
      const { apiPost } = await import('../../api.js');
      await apiPost('/notifiche', {
        id_utente: parseInt(form.id_utente.value),
        tipo: form.tipo.value,
        titolo: form.titolo.value.trim(),
        messaggio: form.messaggio.value.trim()
      });
      msgEl.textContent = 'Notifica inviata con successo!'; msgEl.style.color = 'var(--color-success)'; msgEl.style.display = 'block';
      form.reset();
      setTimeout(() => { msgEl.style.display = 'none'; }, 3000);
    } catch (err) {
      msgEl.textContent = err.message; msgEl.style.color = 'var(--color-error)'; msgEl.style.display = 'block';
    }
  });
}
