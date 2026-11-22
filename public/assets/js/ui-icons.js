function uiIcon(name) {
  const icon = /^[a-z]+$/.test(name) ? name : 'tool';
  const base = String(window.APP_BASE || '').replace(/\/+$/, '');
  return `<svg class="ui-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><use href="${base}/assets/icons.svg#${icon}"></use></svg>`;
}
