import { getState } from '../state.js';
import { apiGet, apiPost, apiPut } from '../api.js';
import { updateNotificationBadge } from '../components/header.js';

const NOTIFICA_ICONS = {
  SCADENZA: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
  ORDINE_DISPONIBILE: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
  ORDINE_INVIATO: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
  ORDINE_RICEVUTO: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
  RITIRATO: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
  SPEDITO: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
  MOQ_RAGGIUNTO: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
  PAGAMENTO_RIUSCITO: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
  SISTEMA: '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
};

export async function NotifichePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/notifiche');
    const notifiche = res.dati || [];
    const unreadCount = notifiche.filter(n => !n.letta).length;

    const notificheHtml = notifiche.map(n => `
      <div class="notification-item ${!n.letta ? 'unread' : ''}" data-id="${n.id}" ${!n.letta ? `onclick="markOneRead(${n.id})" style="cursor:pointer;"` : ''}>
        <div class="notification-icon">${NOTIFICA_ICONS[n.tipo] || NOTIFICA_ICONS['SISTEMA']}</div>
        <div class="notification-content">
          <div class="notification-message">${n.messaggio}</div>
          <div class="notification-time">${new Date(n.data_creazione).toLocaleDateString('it-IT')}</div>
        </div>
        ${!n.letta ? '<div class="notification-dot"></div>' : ''}
      </div>
    `).join('') || '<div class="empty-state"><svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg><h2 class="empty-state-title">Nessuna notifica</h2></div>';

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header">
          <h1>Notifiche ${unreadCount > 0 ? `<span class="badge badge-default" style="margin-left:var(--space-2);">${unreadCount}</span>` : ''}</h1>
          ${unreadCount > 0 ? `<button class="btn btn-outline btn-sm" onclick="markAllRead()">Segna tutte come lette</button>` : ''}
        </div>
        <div class="card"><div class="notification-list">${notificheHtml}</div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

window.markOneRead = async function(id) {
  const el = document.querySelector(`.notification-item[data-id="${id}"]`);
  try {
    await apiPut(`/notifiche/${id}/letta`, {});
  } catch (err) {
    console.error('Mark read error:', err);
    return;
  }
  if (el) {
    el.classList.remove('unread');
    el.removeAttribute('onclick');
    el.style.cursor = '';
    const dot = el.querySelector('.notification-dot');
    if (dot) dot.remove();
  }
  const badge = document.querySelector('.page-header .badge');
  if (badge) {
    const n = parseInt(badge.textContent) - 1;
    if (n > 0) badge.textContent = n;
    else badge.remove();
  }
  if (document.querySelectorAll('.notification-item.unread').length === 0) {
    const btn = document.querySelector('.page-header .btn-outline');
    if (btn) btn.remove();
  }
  try { await updateNotificationBadge(); } catch (_) {}
};

window.markAllRead = async function() {
  try {
    await apiPost('/notifiche/marca-tutte-lette', {});
    document.querySelectorAll('.notification-item.unread').forEach(el => {
      el.classList.remove('unread');
      const dot = el.querySelector('.notification-dot');
      if (dot) dot.remove();
    });
    const badge = document.querySelector('.page-header .badge');
    if (badge) badge.remove();
    const btn = document.querySelector('.page-header .btn-outline');
    if (btn) btn.remove();
    try { await updateNotificationBadge(); } catch (_) {}
  } catch (err) {
    console.error('Mark all read error:', err);
  }
};
