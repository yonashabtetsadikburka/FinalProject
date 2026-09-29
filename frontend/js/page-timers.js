let timers = [];

export function setPageInterval(fn, ms) {
  const id = setInterval(fn, ms);
  timers.push(id);
  return id;
}

export function clearPageTimers() {
  timers.forEach(clearInterval);
  timers = [];
}
