'use strict';

const $ = (id) => document.getElementById(id);
let csrfToken = null;

class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

async function api(path, options = {}) {
  const headers = new Headers(options.headers || {});
  const method = (options.method || 'GET').toUpperCase();
  if (method !== 'GET' && path !== '../v1/admin/login' && csrfToken) {
    headers.set('X-Solosync-Admin', csrfToken);
  }
  if (options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
  const response = await fetch(path, {
    ...options,
    method,
    headers,
    cache: 'no-store',
    credentials: 'same-origin',
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new ApiError(body.message || `HTTP ${response.status}`, response.status);
  return body;
}

function showLogin(message = '') {
  csrfToken = null;
  $('admin-panel').hidden = true;
  $('login-panel').hidden = false;
  $('login-message').textContent = message;
  $('password').value = '';
}

function showAdmin(session) {
  csrfToken = session.csrf;
  $('login-panel').hidden = true;
  $('admin-panel').hidden = false;
  $('login-message').textContent = '';
}

async function refresh() {
  try {
    const status = await api('../v1/admin/status');
    $('status').textContent = JSON.stringify(status, null, 2);
    $('message').textContent = status.installed ? '稼働中' : '未インストールです';
    if (status.installed) {
      const keys = await api('../v1/admin/connection-keys');
      $('keys').replaceChildren(...keys.keys.filter((key) => !key.revokedAt).map((key) => {
        const row = document.createElement('div');
        row.className = 'key';
        const text = document.createElement('span');
        text.textContent = `${key.label} (${key.id})`;
        row.appendChild(text);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'danger';
        button.textContent = '失効';
        button.addEventListener('click', async () => {
          try {
            await api(`../v1/admin/connection-keys/${key.id}/revoke`, { method: 'POST', body: '{}' });
            await refresh();
          } catch (error) {
            handleError(error);
          }
        });
        row.appendChild(button);
        return row;
      }));
    } else {
      $('keys').replaceChildren();
    }
  } catch (error) {
    handleError(error);
  }
}

function handleError(error) {
  if (error instanceof ApiError && error.status === 401) {
    showLogin('セッションの有効期限が切れました。もう一度ログインしてください。');
    return;
  }
  $('message').textContent = String(error.message || error);
}

$('login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('login-message').textContent = 'ログインしています…';
  try {
    const session = await api('../v1/admin/login', {
      method: 'POST',
      body: JSON.stringify({ password: $('password').value }),
    });
    showAdmin(session);
    $('password').value = '';
    await refresh();
  } catch (error) {
    showLogin(error instanceof ApiError && error.status === 401
      ? 'パスワードが正しくありません。'
      : String(error.message || error));
  }
});

$('logout').addEventListener('click', async () => {
  try {
    await api('../v1/admin/logout', { method: 'POST', body: '{}' });
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) {
      $('message').textContent = String(error.message || error);
      return;
    }
  }
  showLogin('ログアウトしました。');
});

$('install').addEventListener('click', async () => {
  try {
    await api('../v1/admin/install', { method: 'POST', body: '{}' });
    await refresh();
  } catch (error) {
    handleError(error);
  }
});

function resetKeyDialog() {
  $('key-create-form').hidden = false;
  $('key-result').hidden = true;
  $('key-dialog-message').textContent = '';
  $('copy-message').textContent = '';
  $('new-key').textContent = '';
  $('server-url').textContent = '';
  $('key-create-submit').disabled = false;
}

function currentServerUrl() {
  const url = new URL(window.location.href);
  url.search = '';
  url.hash = '';
  url.pathname = url.pathname.replace(/\/admin\/?$/, '/');
  return url.toString().replace(/\/$/, '');
}

function closeKeyDialog() {
  $('key-dialog').close();
  resetKeyDialog();
}

$('create-key').addEventListener('click', () => {
  resetKeyDialog();
  $('key-dialog').showModal();
  $('key-label').focus();
  $('key-label').select();
});

$('key-dialog-close').addEventListener('click', closeKeyDialog);
$('key-result-close').addEventListener('click', closeKeyDialog);
$('key-dialog').addEventListener('click', (event) => {
  if (event.target === $('key-dialog')) closeKeyDialog();
});

$('key-create-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const label = $('key-label').value.trim();
  if (!label) {
    $('key-dialog-message').textContent = '名前を入力してください。';
    return;
  }

  $('key-create-submit').disabled = true;
  $('key-dialog-message').textContent = '作成しています…';
  try {
    const result = await api('../v1/admin/connection-keys', {
      method: 'POST',
      body: JSON.stringify({ label }),
    });
    $('new-key').textContent = result.key;
    $('server-url').textContent = currentServerUrl();
    $('key-create-form').hidden = true;
    $('key-result').hidden = false;
    $('copy-key').focus();
    await refresh();
  } catch (error) {
    $('key-create-submit').disabled = false;
    if (error instanceof ApiError && error.status === 401) {
      closeKeyDialog();
      handleError(error);
      return;
    }
    $('key-dialog-message').textContent = String(error.message || error);
  }
});

async function copyTextFrom(elementId, successMessage) {
  const element = $(elementId);
  const value = element.textContent;
  if (!value) return;
  try {
    await navigator.clipboard.writeText(value);
    $('copy-message').textContent = successMessage;
  } catch {
    const range = document.createRange();
    range.selectNodeContents(element);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    $('copy-message').textContent = '自動コピーできませんでした。値を選択したので手動でコピーしてください。';
  }
}

$('copy-key').addEventListener('click', async () => {
  await copyTextFrom('new-key', 'キーをコピーしました。');
});

$('copy-server-url').addEventListener('click', async () => {
  await copyTextFrom('server-url', 'サーバーURLをコピーしました。');
});

$('maintenance').addEventListener('click', async () => {
  try {
    const result = await api('../v1/admin/maintenance', { method: 'POST', body: JSON.stringify({ limit: 100 }) });
    $('message').textContent = `Plugin配送: ${JSON.stringify(result)}`;
    await refresh();
  } catch (error) {
    handleError(error);
  }
});

async function bootstrap() {
  try {
    const session = await api('../v1/admin/session');
    if (!session.authenticated) {
      showLogin();
      return;
    }
    showAdmin(session);
    await refresh();
  } catch (error) {
    showLogin(String(error.message || error));
  }
}

void bootstrap();
