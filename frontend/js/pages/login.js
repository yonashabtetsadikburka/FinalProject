import { login, loginWithGoogle, loginWithMicrosoft } from '../auth.js';

const MICROSOFT_CLIENT_ID = 'YOUR_MICROSOFT_CLIENT_ID_HERE';

window.handleCredentialResponse = async function(response) {
  const errorEl = document.getElementById('login-error');
  try {
    await loginWithGoogle(response.credential);
    window.location.href = 'app.html#/';
  } catch (err) {
    errorEl.textContent = err.message || 'Errore durante il login con Google';
    errorEl.style.display = 'block';
  }
};

function renderGoogleButton() {
  const container = document.getElementById('google-btn-container');
  if (!container) return;

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'oauth-btn';
  btn.innerHTML = `
    <svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
      <path fill="#4285F4" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
      <path fill="#34A853" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
      <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
      <path fill="#EA4335" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
    </svg>
    <span>Accedi con Google</span>
  `;
  btn.onclick = handleGoogleLogin;
  container.appendChild(btn);
}

async function handleGoogleLogin() {
  const errorEl = document.getElementById('login-error');
  errorEl.style.display = 'none';

  if (typeof google === 'undefined' || !google.accounts) {
    errorEl.textContent = 'Google Identity Services non caricato. Riprova piu tardi.';
    errorEl.style.display = 'block';
    return;
  }

  try {
    google.accounts.id.initialize({
      client_id: '22127010556-mss3ikiiugra9aqv3im8m1jenn3jj8cd.apps.googleusercontent.com',
      callback: window.handleCredentialResponse,
    });
    google.accounts.id.prompt();
  } catch (err) {
    errorEl.textContent = err.message || 'Errore durante il login con Google';
    errorEl.style.display = 'block';
  }
}

function renderMicrosoftButton() {
  const container = document.getElementById('microsoft-btn-container');
  if (!container) return;

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'oauth-btn';
  btn.innerHTML = `
    <svg width="20" height="20" viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg">
      <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
      <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
      <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
      <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
    </svg>
    <span>Accedi con Microsoft</span>
  `;
  btn.onclick = handleMicrosoftLogin;
  container.appendChild(btn);
}

async function handleMicrosoftLogin() {
  const errorEl = document.getElementById('login-error');
  errorEl.style.display = 'none';

  if (typeof msal === 'undefined') {
    errorEl.textContent = 'Microsoft Authentication Library non caricato. Riprova piu tardi.';
    errorEl.style.display = 'block';
    return;
  }

  try {
    const msalConfig = {
      auth: {
        clientId: MICROSOFT_CLIENT_ID,
        redirectUri: window.location.origin + window.location.pathname
      }
    };

    const msalInstance = new msal.PublicClientApplication(msalConfig);

    const loginRequest = {
      scopes: ['user.read']
    };

    const response = await msalInstance.loginPopup(loginRequest);
    await loginWithMicrosoft(response.idToken);
    window.location.href = 'app.html#/';
  } catch (err) {
    if (err.errorCode === 'user_cancelled') return;
    errorEl.textContent = err.message || 'Errore durante il login con Microsoft';
    errorEl.style.display = 'block';
  }
}

window.togglePasswordVisibility = function() {
  const input = document.getElementById('password-input');
  const icon = document.getElementById('password-toggle-icon');
  if (!input || !icon) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';

  if (isPassword) {
    icon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
  } else {
    icon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
  }
};

export function LoginPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="login-page">
      <div class="login-card card">
        <div class="card-content">
          <div class="login-logo">
            <h1>BuyPool</h1>
            <p>Acquisti collettivi, prezzi da grossista</p>
          </div>
          <form class="login-form" id="login-form">
            <div class="input-group">
              <label class="input-label">Email</label>
              <input type="email" name="email" class="input" placeholder="mario.rossi@email.com" required>
            </div>
            <div class="input-group">
              <label class="input-label">Password</label>
              <div class="input-icon-wrapper">
                <input type="password" name="password" id="password-input" class="input" placeholder="••••••••" required>
                <button type="button" class="input-icon-btn" id="password-toggle-btn" onclick="togglePasswordVisibility()">
                  <span id="password-toggle-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                  </span>
                </button>
              </div>
            </div>
            <div id="login-error" style="color: var(--color-error); font-size: var(--text-sm); display: none;"></div>
            <button type="submit" class="btn btn-default w-full">Accedi</button>
          </form>
          <div class="login-divider">oppure</div>
          <div class="oauth-buttons">
            <div id="google-btn-container" style="flex: 1;"></div>
            <div id="microsoft-btn-container" style="flex: 1;"></div>
          </div>
          <div class="login-footer">
            Non hai un account? <a href="#/register">Registrati</a>
          </div>
        </div>
      </div>
    </div>
  `;

  renderGoogleButton();
  renderMicrosoftButton();

  document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = e.target.email.value;
    const password = e.target.password.value;
    const errorEl = document.getElementById('login-error');
    errorEl.style.display = 'none';

    try {
      await login(email, password);
      window.location.href = 'app.html#/';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.style.display = 'block';
    }
  });
}
