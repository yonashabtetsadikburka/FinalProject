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
      clearSession();
      window.location.href = 'index.html';
      throw new Error('Sessione scaduta');
    }

    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.errore?.messaggio || 'Errore del server');
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
