import { sanificaDeep } from './escape.js';

let state = {
  user: null,
  token: null,
  isLoading: true
};

const listeners = new Set();

export function getState() {
  return { ...state };
}

export function setState(newState) {
  state = { ...state, ...newState };
  listeners.forEach(cb => cb(state));
}

export function subscribe(callback) {
  listeners.add(callback);
  return () => listeners.delete(callback);
}

export function initSession() {
  const token = localStorage.getItem('buypool_token');
  const userJson = localStorage.getItem('buypool_user');

  if (token && userJson) {
    try {
      // Il localStorage non e' fidato (sessione vecchia, modificato a mano): si sanifica ad ogni lettura.
      const user = sanificaDeep(JSON.parse(userJson));
      setState({ user, token, isLoading: false });
    } catch (e) {
      localStorage.removeItem('buypool_token');
      localStorage.removeItem('buypool_user');
      setState({ user: null, token: null, isLoading: false });
    }
  } else {
    setState({ user: null, token: null, isLoading: false });
  }
}

export function saveSession(user, token) {
  user = sanificaDeep(user);
  localStorage.setItem('buypool_token', token);
  localStorage.setItem('buypool_user', JSON.stringify(user));
  setState({ user, token });
}

export function clearSession() {
  localStorage.removeItem('buypool_token');
  localStorage.removeItem('buypool_user');
  setState({ user: null, token: null });
}
