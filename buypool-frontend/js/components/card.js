export function Card({ class: extraClass = '', children }) {
  return `<div class="card ${extraClass}">${children}</div>`;
}

export function CardHeader({ children, action = '' }) {
  return `<div class="card-header"><div class="card-header-content">${children}</div>${action ? `<div class="card-action">${action}</div>` : ''}</div>`;
}

export function CardTitle({ children }) {
  return `<h3 class="card-title">${children}</h3>`;
}

export function CardDescription({ children }) {
  return `<p class="card-description">${children}</p>`;
}

export function CardContent({ children }) {
  return `<div class="card-content">${children}</div>`;
}

export function CardFooter({ children }) {
  return `<div class="card-footer">${children}</div>`;
}
