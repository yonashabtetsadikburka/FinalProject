import { getState } from '../state.js';
import { logout, isAdmin, isFornitore } from '../auth.js';
import { apiGet } from '../api.js';
import { Avatar } from './dropdown.js';

async function updateNotificationBadge() {
  const container = document.getElementById('notification-btn');
  if (!container) return;
  try {
    const res = await apiGet('/notifiche/non-lette');
    const count = res.dati?.non_lette ?? 0;
    let badge = container.querySelector('.notification-badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'notification-badge';
        container.appendChild(badge);
      }
      badge.textContent = count;
    } else {
      if (badge) badge.remove();
    }
  } catch (e) {}
}

const NAV_ITEMS = [
  { route: '/', label: 'Campagne', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
  { route: '/proposte', label: 'Proposte', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>' },
  { route: '/fornitori', label: 'Fornitori', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>' },
  { route: '/dashboard', label: 'Dashboard', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>' },
];

const MIO_SPAZIO_ITEMS = [
  { route: '/notifiche', label: 'Notifiche', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' },
  { route: '/partecipazioni', label: 'Le mie partecipazioni', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>' },
  { route: '/proposte', label: 'Le mie proposte', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>' },
  { route: '/ordini', label: 'I miei ordini', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>' },
];

const ADMIN_ITEMS = [
  { route: '/admin', label: 'Dashboard', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>' },
  { route: '/admin/campagne', label: 'Campagne', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
  { route: '/admin/utenti', label: 'Utenti', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>' },
  { route: '/admin/fornitori', label: 'Fornitori', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>' },
  { route: '/admin/ordini', label: 'Ordini', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>' },
  { route: '/admin/ritiri', label: 'Ritiri', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>' },
  { route: '/admin/notifiche', label: 'Notifiche', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' },
];

const FORNITORE_ITEMS = [
  { route: '/area', label: 'Dashboard', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>' },
  { route: '/area/prodotti', label: 'I miei prodotti', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>' },
  { route: '/area/ordini', label: 'Ordini', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>' },
  { route: '/area/collette', label: 'Campagne', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
  { route: '/notifiche', label: 'Notifiche', icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' },
];

function renderNavItems(hash) {
  return NAV_ITEMS.map(item => {
    const isActive = hash === item.route || (item.route !== '/' && hash.startsWith(item.route));
    return `<a class="nav-item ${isActive ? 'active' : ''}" href="#${item.route}">${item.icon}<span>${item.label}</span></a>`;
  }).join('');
}

function renderMioSpazioDropdown(hash) {
  const isActive = MIO_SPAZIO_ITEMS.some(item =>
    hash === item.route || hash.startsWith(item.route)
  );
  const itemsHtml = MIO_SPAZIO_ITEMS.map(item => {
    const isItemActive = hash === item.route || hash.startsWith(item.route);
    return `<a class="dropdown-item ${isItemActive ? 'active' : ''}" href="#${item.route}">${item.icon}<span>${item.label}</span></a>`;
  }).join('');

  return `
    <div class="dropdown nav-dropdown" id="mio-spazio-menu">
      <div class="nav-item nav-dropdown-trigger ${isActive ? 'active' : ''}" data-dropdown-toggle="mio-spazio-menu">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        <span>Mio Spazio</span>
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </div>
      <div class="dropdown-menu nav-dropdown-menu">${itemsHtml}</div>
    </div>
  `;
}

function renderAdminNav(hash) {
  const adminIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>';
  return renderItemList(ADMIN_ITEMS, hash, adminIcon);
}

function renderFornitoreNav(hash) {
  const fornitoreIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>';
  return renderItemList(FORNITORE_ITEMS, hash, fornitoreIcon);
}

function renderItemList(items, hash, iconOverride) {
  return items.map(item => {
    const isActive = hash === item.route || (isSectionRoot(item.route) && hash.startsWith(item.route));
    const icon = iconOverride || item.icon;
    return `<a class="nav-item ${isActive ? 'active' : ''}" href="#${item.route}">${icon}<span>${item.label}</span></a>`;
  }).join('');
}

function isSectionRoot(route) {
  return route !== '/' && route !== '/area' && route !== '/admin' && !route.split('/').filter(Boolean)[1];
}

function renderMobileNavItems(hash) {
  let html = '<div class="mobile-menu-nav">';

  if (isAdmin()) {
    html += '<div class="mobile-menu-section">Amministrazione</div>';
    html = mobileItems(ADMIN_ITEMS, hash, html);
  } else if (isFornitore()) {
    html += '<div class="mobile-menu-section">Area Fornitore</div>';
    html = mobileItems(FORNITORE_ITEMS, hash, html);
  } else {
    html += '<div class="mobile-menu-section">Menu</div>';
    html = mobileItems(NAV_ITEMS, hash, html);
    html += '<div class="mobile-menu-section" style="margin-top: var(--space-4);">Mio Spazio</div>';
    html = mobileItems(MIO_SPAZIO_ITEMS, hash, html);
  }

  html += '</div>';
  return html;
}

function mobileItems(items, hash, mobileHtml) {
  let html = mobileHtml;
  items.forEach(item => {
    const isActive = hash === item.route || (isSectionRoot(item.route) && hash.startsWith(item.route));
    html += `<a class="mobile-menu-item ${isActive ? 'active' : ''}" href="#${item.route}" onclick="closeMobileMenu()">${item.icon}<span>${item.label}</span></a>`;
  });
  return html;
}

export function Header() {
  const { user } = getState();
  const initials = user ? `${user.nome[0]}${user.cognome[0]}` : '';
  const hash = window.location.hash.slice(1) || '/';
  const userIsAdmin = isAdmin();
  const userIsFornitore = isFornitore();

  const userMenuId = 'user-menu';
  const userMenuItems = [
    { id: 'profilo', label: 'Profilo', icon: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>', onClick: "window.location.hash='#/profilo'" },
    { separator: true },
    { id: 'logout', label: 'Esci', icon: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>', onClick: "handleLogout()" }
  ];

  const userMenuItemsHtml = userMenuItems.map(item => {
    if (item.separator) {
      return '<div class="dropdown-separator"></div>';
    }
    return `<button class="dropdown-item" onclick="${item.onClick}">${item.icon}<span>${item.label}</span></button>`;
  }).join('');

  setTimeout(updateNotificationBadge, 0);

  return `
    <header class="header">
      <div class="header-left">
        <button class="hamburger-btn" onclick="openMobileMenu()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="#/" class="header-logo header-logo-desktop">BuyPool</a>
      </div>
      <div class="header-center">
        <a href="#/" class="header-logo header-logo-mobile">BuyPool</a>
        <nav class="header-nav">
          ${userIsAdmin ? renderAdminNav(hash) : (userIsFornitore ? renderFornitoreNav(hash) : renderNavItems(hash) + renderMioSpazioDropdown(hash))}
        </nav>
      </div>
      <div class="header-right">
        <a href="#/notifiche" class="notification-btn" id="notification-btn">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        </a>
        <div class="dropdown" id="${userMenuId}">
          <div class="user-menu-trigger" data-dropdown-toggle="${userMenuId}">
            ${Avatar({ fallback: initials, size: 'sm' })}
            <span class="user-name">${user?.nome || ''} ${user?.cognome || ''}</span>
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
          </div>
          <div class="dropdown-menu">${userMenuItemsHtml}</div>
        </div>
      </div>
    </header>
    <div class="mobile-menu-overlay" id="mobile-menu-overlay" onclick="closeMobileMenu()"></div>
    <div class="mobile-menu" id="mobile-menu">
      <div class="mobile-menu-header">
        <span class="mobile-menu-logo">BuyPool</span>
        <button class="mobile-menu-close" onclick="closeMobileMenu()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      ${renderMobileNavItems(hash)}
    </div>
  `;
}

export function handleLogout() {
  logout();
  window.location.href = 'index.html';
}

window.handleLogout = handleLogout;

window.openMobileMenu = function() {
  document.getElementById('mobile-menu').classList.add('open');
  document.getElementById('mobile-menu-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
};

window.closeMobileMenu = function() {
  document.getElementById('mobile-menu').classList.remove('open');
  document.getElementById('mobile-menu-overlay').classList.remove('open');
  document.body.style.overflow = '';
};
