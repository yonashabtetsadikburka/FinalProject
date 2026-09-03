import { apiPost } from './api.js';
import { saveSession, clearSession, getState } from './state.js';

export async function login(email, password) {
  const data = await apiPost('/login', { email, password });
  const user = data.dati;
  saveSession(user, 'session_' + user.id);
  return user;
}

export async function loginWithGoogle(googleToken) {
  const data = await apiPost('/auth/google', { token: googleToken });
  const user = data.dati;
  saveSession(user, 'google_' + user.id);
  return user;
}

export async function loginWithMicrosoft(microsoftToken) {
  const data = await apiPost('/auth/microsoft', { token: microsoftToken });
  const user = data.dati;
  saveSession(user, 'microsoft_' + user.id);
  return user;
}

export async function register({ nome, cognome, email, password }) {
  const data = await apiPost('/registrazione', { nome, cognome, email, password });
  const user = data.dati;
  saveSession(user, 'session_' + user.id);
  return user;
}

export function logout() {
  clearSession();
}

export function isAdmin() {
  const { user } = getState();
  return user?.ruolo === 'admin';
}

export function isAuthenticated() {
  const { user } = getState();
  return user !== null;
}
