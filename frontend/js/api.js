import { API_URL } from './constants.js';
import { getState, clearSession } from './state.js';

export async function apiRequest(method, url, body = null) {
  const { token } = getState();

  const headers = {
    'Content-Type': 'application/json'
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const config = {
    method,
    headers
  };

  if (body && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
    config.body = JSON.stringify(body);
  }

  try {
    const response = await fetch(`${API_URL}${url}`, config);

    if (response.status === 401) {
      // 401 su endpoint auth = credenziali errate, non sessione scaduta: niente redirect
      const isAuthEndpoint = ['/login', '/registrazione', '/auth/'].some(p => url.includes(p));
      if (!isAuthEndpoint) {
        clearSession();
        window.location.href = 'index.html';
        throw new Error('Sessione scaduta');
      }
    }

    const data = await response.json();

    if (!response.ok) {
      if (data.errore?.codice === 'UTENTE_SOSPESO') {
        const toast = document.createElement('div');
        toast.className = 'toast toast-error';
        toast.innerHTML = `<div class="toast-content"><div class="toast-title">Account sospeso</div><div class="toast-description">${data.errore?.messaggio || ''}</div></div>`;
        document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
      }
      const err = new Error(data.errore?.messaggio || 'Errore del server');
      err.codice = data.errore?.codice;
      throw err;
    }

    return data;
  } catch (error) {
    if (error.message === 'Sessione scaduta') throw error;
    console.error('API Error:', error);
    throw error;
  }
}

export function apiGet(url) {
  return apiRequest('GET', url);
}

export function apiPost(url, body) {
  return apiRequest('POST', url, body);
}

export function apiPut(url, body) {
  return apiRequest('PUT', url, body);
}

export function apiDelete(url) {
  return apiRequest('DELETE', url);
}

export async function apiPostForm(url, formData) {
  const { token } = getState();
  const headers = {};
  if (token) headers['Authorization'] = `Bearer ${token}`;
  try {
    const response = await fetch(`${API_URL}${url}`, { method: 'POST', headers, body: formData });
    const data = await response.json();
    if (!response.ok) throw new Error(data.errore?.messaggio || 'Errore del server');
    return data;
  } catch (error) {
    console.error('API Error:', error);
    throw error;
  }
}
