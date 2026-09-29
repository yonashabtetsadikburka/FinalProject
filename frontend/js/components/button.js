export function Button({ variant = 'default', size = 'default', onClick, disabled = false, class: extraClass = '', children }) {
  const classes = ['btn', `btn-${variant}`, extraClass].filter(Boolean).join(' ');
  const sizeClass = size !== 'default' ? `btn-${size}` : '';
  const allClasses = [classes, sizeClass].filter(Boolean).join(' ');
  const disabledAttr = disabled ? 'disabled' : '';
  const onClickAttr = onClick ? `onclick="${onClick}"` : '';

  return `<button class="${allClasses}" ${disabledAttr} ${onClickAttr}>${children}</button>`;
}
