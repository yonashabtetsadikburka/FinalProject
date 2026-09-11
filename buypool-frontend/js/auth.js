import { apiPost } from './api.js';
import { saveSession, clearSession, getState } from './state.js';

export async function login(email, password) {
  const data = await apiPost('/auth/login', { email, password });
  const { token, utente } = data.dati;
  saveSession(utente, token);
  return utente;
}

export async function loginWithGoogle(googleToken) {
  // TODO backend: route pas encore implémentée côté Laravel
  const data = await apiPost('/auth/google', { token: googleToken });
  const { token, utente } = data.dati;
  saveSession(utente, token);
  return utente;
}

export async function loginWithMicrosoft(microsoftToken) {
  // TODO backend: route pas encore implémentée côté Laravel
  const data = await apiPost('/auth/microsoft', { token: microsoftToken });
  const { token, utente } = data.dati;
  saveSession(utente, token);
  return utente;
}

export async function register({ nome, cognome, email, password }) {
  const data = await apiPost('/auth/register', { nome, cognome, email, password });
  const { token, utente } = data.dati;
  saveSession(utente, token);
  return utente;
}

export function logout() {
  clearSession();
}

export function isAdmin() {
  const { user } = getState();
  return user?.ruolo === 'admin';
}

export function isFornitore() {
  const { user } = getState();
  return user?.ruolo === 'fornitore';
}

export function ruoloUtente() {
  const { user } = getState();
  return user?.ruolo || 'utente';
}

export function isAuthenticated() {
  const { user } = getState();
  return user !== null;
}
