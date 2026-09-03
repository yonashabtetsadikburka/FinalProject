export function Modal({ id, isOpen = false, title = '', children = '', onClose = '' }) {
  if (!isOpen) return '';

  const closeHandler = onClose || `closeModal('${id}')`;

  return `
    <div class="modal-overlay" id="${id}" onclick="if(event.target===this) ${closeHandler}">
      <div class="modal">
        <div class="modal-header">
          <h3 class="modal-title">${title}</h3>
          <button class="modal-close" onclick="${closeHandler}">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="modal-body">${children}</div>
      </div>
    </div>
  `;
}

export function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.remove();
  }
}
