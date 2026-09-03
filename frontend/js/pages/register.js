import { register } from '../auth.js';
import { showToast } from '../components/toast.js';

export function RegisterPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="login-page">
      <div class="login-card card">
        <div class="card-content">
          <div class="login-logo">
            <h1>BuyPool</h1>
            <p>Crea il tuo account</p>
          </div>
          <form class="login-form" id="register-form">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-3);">
              <div class="input-group">
                <label class="input-label">Nome *</label>
                <input type="text" name="nome" class="input" placeholder="Mario" required>
              </div>
              <div class="input-group">
                <label class="input-label">Cognome *</label>
                <input type="text" name="cognome" class="input" placeholder="Rossi" required>
              </div>
            </div>
            <div class="input-group">
              <label class="input-label">Email *</label>
              <input type="email" name="email" class="input" placeholder="mario.rossi@email.com" required>
            </div>
            <div class="input-group">
              <label class="input-label">Password * (min 8 caratteri)</label>
              <div class="input-icon-wrapper">
                <input type="password" name="password" id="register-password-input" class="input" placeholder="••••••••" required minlength="8">
                <button type="button" class="input-icon-btn" onclick="toggleRegisterPasswordVisibility()">
                  <span id="register-password-toggle-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                  </span>
                </button>
              </div>
            </div>
            <div class="input-group">
              <label class="input-label">Tipo account</label>
              <div class="register-type-selector">
                <div class="type-option selected" data-tipo="privato" onclick="selectTipo('privato')">
                  <svg class="type-option-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                  <div class="type-option-title">Privato</div>
                  <div class="type-option-desc">Acquista per te</div>
                </div>
                <div class="type-option" data-tipo="b2b" onclick="selectTipo('b2b')">
                  <svg class="type-option-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                  <div class="type-option-title">Business</div>
                  <div class="type-option-desc">Acquista per la tua azienda</div>
                </div>
              </div>
              <input type="hidden" name="tipo" value="privato">
            </div>
            <div id="register-error" style="color: var(--color-error); font-size: var(--text-sm); display: none;"></div>
            <button type="submit" class="btn btn-default w-full">Registrati</button>
          </form>
          <div class="login-footer">
            Hai gia' un account? <a href="#/login">Accedi</a>
          </div>
        </div>
      </div>
    </div>
  `;

  document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const errorEl = document.getElementById('register-error');
    errorEl.style.display = 'none';

    try {
      await register({
        nome: formData.get('nome'),
        cognome: formData.get('cognome'),
        email: formData.get('email'),
        password: formData.get('password'),
        tipo: formData.get('tipo')
      });
      window.location.href = 'app.html#/';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.style.display = 'block';
    }
  });
}

window.selectTipo = function(tipo) {
  document.querySelectorAll('.type-option').forEach(el => {
    el.classList.toggle('selected', el.dataset.tipo === tipo);
  });
  document.querySelector('input[name="tipo"]').value = tipo;
};

window.toggleRegisterPasswordVisibility = function() {
  const input = document.getElementById('register-password-input');
  const icon = document.getElementById('register-password-toggle-icon');
  if (!input || !icon) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';

  if (isPassword) {
    icon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
  } else {
    icon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
  }
};
