'use strict';

const $ = (id) => document.getElementById(id);
let csrfToken = null;
let latestUpdateTag = null;
let updateReady = false;
const views = {
  overview: ['概要', 'サーバーの稼働状況を確認します。'],
  keys: ['接続キー', 'アプリからの接続を管理します。'],
  plugins: ['プラグイン', '機能の有効化と設定を管理します。'],
  updates: ['サーバー更新', '公式Releaseの確認と更新を行います。'],
};

function selectView(name) {
  if (!views[name]) return;
  document.querySelectorAll('[data-panel]').forEach((panel) => { panel.hidden = panel.dataset.panel !== name; });
  document.querySelectorAll('[data-view]').forEach((item) => {
    const active = item.dataset.view === name;
    item.classList.toggle('active', active);
    if (active) item.setAttribute('aria-current', 'page');
    else item.removeAttribute('aria-current');
  });
  $('view-title').textContent = views[name][0];
  $('view-description').textContent = views[name][1];
  try { history.replaceState(null, '', `#${name}`); } catch { /* optional URL state */ }
}

document.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => selectView(button.dataset.view)));
const savedTheme = localStorage.getItem('solosync-admin-theme');
if (['light', 'dark'].includes(savedTheme)) document.documentElement.dataset.theme = savedTheme;
$('theme-select').value = ['light', 'dark'].includes(savedTheme) ? savedTheme : 'system';
$('theme-select').addEventListener('change', () => {
  const theme = $('theme-select').value;
  if (theme === 'system') { delete document.documentElement.dataset.theme; localStorage.removeItem('solosync-admin-theme'); }
  else { document.documentElement.dataset.theme = theme; localStorage.setItem('solosync-admin-theme', theme); }
});

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
  $('admin-nav').hidden = true;
  $('logout').hidden = true;
}

function showAdmin(session) {
  csrfToken = session.csrf;
  $('login-panel').hidden = true;
  $('admin-panel').hidden = false;
  $('login-message').textContent = '';
  $('admin-nav').hidden = false;
  $('logout').hidden = false;
  selectView(location.hash.slice(1) in views ? location.hash.slice(1) : 'overview');
}

function renderStats(status) {
  const values = status.installed ? [
    ['状態', status.bound ? '接続済み' : '未接続'],
    ['端末', status.devices ?? 0],
    ['同期パック', status.packs ?? 0],
    ['接続キー', status.connectionKeys ?? 0],
    ['保存容量', `${Math.round(Number(status.usedBytes || 0) / 1048576 * 10) / 10} MB`],
    ['保留イベント', status.pendingPluginEvents ?? 0],
  ] : [['状態', '未インストール']];
  $('status-grid').replaceChildren(...values.map(([label, value]) => {
    const box = document.createElement('div');
    box.className = 'stat';
    box.append(node('strong', String(value)), node('span', label));
    return box;
  }));
  $('server-pill').textContent = status.installed ? '稼働中' : '未インストール';
  $('server-pill').classList.toggle('ok', !!status.installed);
}

async function refresh() {
  try {
    const status = await api('../v1/admin/status');
    $('status').textContent = JSON.stringify(status, null, 2);
    renderStats(status);
    $('message').hidden = true;
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
    if (status.installed && !$('keys').children.length) $('keys').appendChild(node('p', '接続キーはまだありません。', 'muted'));
    await Promise.all([refreshPlugins(), refreshProjection(), refreshUpdate()]);
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
  $('message').hidden = false;
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
    await refresh();
    $('message').textContent = `Plugin配送: ${JSON.stringify(result)}`;
    $('message').hidden = false;
  } catch (error) {
    handleError(error);
  }
});

function node(tag, text = '', className = '') {
  const element = document.createElement(tag);
  element.textContent = text;
  if (className) element.className = className;
  return element;
}

function action(text, callback, className = '') {
  const button = node('button', text, className);
  button.type = 'button';
  button.addEventListener('click', async () => {
    button.disabled = true;
    try { await callback(); } catch (error) { handleError(error); }
    finally { button.disabled = false; }
  });
  return button;
}

function compareVersions(left, right) {
  const a = left.replace(/^v/, '').split('.').map(Number);
  const b = right.replace(/^v/, '').split('.').map(Number);
  for (let i = 0; i < 3; i++) if (a[i] !== b[i]) return a[i] - b[i];
  return 0;
}

async function refreshUpdate() {
  try {
    const [status, preflight] = await Promise.all([
      api('../v1/admin/updates'), api('../v1/admin/updates/preflight'),
    ]);
    $('installed-version').textContent = status.installedVersion;
    const older = status.latestVersion && !status.updateAvailable
      && status.error == null && /^v?\d+\.\d+\.\d+$/.test(status.latestVersion)
      && compareVersions(status.latestVersion, status.installedVersion) < 0;
    const detail = status.error ? `確認できません: ${status.error}`
      : status.checkedAt ? `${older ? '公開中のReleaseは現在の配置より古いです' : `公開最新版: ${status.latestVersion ?? 'なし'}`}（確認: ${status.checkedAt}）`
      : 'まだ確認していません。';
    $('update-status').textContent = detail;
    $('version-warning').hidden = !status.versionFileMissing;
    $('version-warning').textContent = status.versionFileMissing
      ? 'VERSION ファイルがないか内容が無効です。サーバーファイル一式を配置してください。現在は互換性判定に標準のバージョンを使用しています。' : '';
    $('update-preflight').textContent = preflight.available
      ? 'このサーバーは管理画面から更新できます。' : `管理画面からの更新は利用できません: ${preflight.reason}`;
    latestUpdateTag = status.updateAvailable ? status.latestVersion : null;
    updateReady = !!preflight.available;
    $('install-update').hidden = !latestUpdateTag;
    $('install-update').disabled = !updateReady;
    if (status.updateAvailable && typeof status.releaseUrl === 'string'
      && status.releaseUrl.startsWith('https://github.com/kakik0u/solosync-server/releases/')) {
      $('update-link').href = status.releaseUrl;
      $('update-link').hidden = false;
    } else {
      $('update-link').hidden = true;
    }
  } catch (error) {
    $('update-status').textContent = `更新情報を取得できません: ${String(error.message || error)}`;
  }
}

$('check-update').addEventListener('click', async () => {
  $('check-update').disabled = true;
  try {
    await api('../v1/admin/updates/check', { method: 'POST', body: '{}' });
    await refreshUpdate();
  } catch (error) { handleError(error); }
  finally { $('check-update').disabled = false; }
});

$('install-update').addEventListener('click', async () => {
  if (!latestUpdateTag || !updateReady) return;
  const tag = latestUpdateTag;
  if (!window.confirm(`${tag} に更新します。プログラムのバックアップを保存し、必要なDBマイグレーションを適用します。続けますか？`)) return;
  $('install-update').disabled = true;
  $('check-update').disabled = true;
  $('update-result').textContent = 'Releaseを検証して更新しています。完了までこの画面を閉じないでください…';
  try {
    const result = await api('../v1/admin/updates/install', { method: 'POST', body: JSON.stringify({ expectedTag: tag }) });
    $('update-result').textContent = `${result.version} に更新しました。${result.files}個のファイルを更新し、${result.migrations.length}件のマイグレーションを適用しました。ページを再読み込みしてください。`;
    latestUpdateTag = null;
    await refreshUpdate();
  } catch (error) {
    $('update-result').textContent = `更新できませんでした: ${String(error.message || error)}`;
  } finally {
    $('install-update').disabled = false;
    $('check-update').disabled = false;
  }
});

async function refreshProjection() {
  try {
    const status = await api('../v1/admin/projection/status');
    $('projection-status').textContent = status.initialized === false
      ? '読み取りデータは未作成です。プラグインを有効化すると準備できます。'
      : `状態: ${status.status}、反映済み ${status.sequence}/${status.headSequence}`
        + `、チャンネル ${status.channels}件、メモ ${status.messages}件`
        + (status.orphanMessages ? `、親チャンネルのないメモ ${status.orphanMessages}件` : '')
        + (status.lastError ? `、エラー: ${status.lastError}` : '');
  } catch (error) {
    $('projection-status').textContent = String(error.message || error);
  }
}

$('projection-step').addEventListener('click', async () => {
  $('projection-step').disabled = true;
  try {
    const status = await api('../v1/admin/projection/step', { method: 'POST', body: '{}' });
    $('projection-status').textContent = `状態: ${status.status}、反映済み ${status.sequence}/${status.headSequence}`;
    await refreshPlugins();
  } catch (error) { handleError(error); }
  finally { $('projection-step').disabled = false; }
});

$('projection-rebuild').addEventListener('click', async () => {
  if (!window.confirm('読み取りデータをSyncPackから作り直します。再構築が追いつくまで公開ページとWebhookは一時停止します。続けますか？')) return;
  $('projection-rebuild').disabled = true;
  try {
    await api('../v1/admin/projection/rebuild', { method: 'POST', body: '{}' });
    await refreshProjection();
    $('message').textContent = '読み取りデータをリセットしました。「データを準備・更新」で再生してください。';
  } catch (error) { handleError(error); }
  finally { $('projection-rebuild').disabled = false; }
});

async function loadChannels() {
  try {
    const result = await api('../v1/admin/projection/channels'), channels = result.channels;
    const status = channels.length ? null : await api('../v1/admin/projection/status');
    return { channels, guilds: result.guilds ?? [], status, error: null };
  } catch (error) {
    return { channels: [], status: null, error };
  }
}

function channelEmptyMessage(result) {
  if (result.error) return `チャンネル一覧を取得できません: ${String(result.error.message || result.error)}`;
  if (result.status?.initialized === false || result.status?.ready === false) {
    return '同期データを準備・更新するとチャンネルを選べます。';
  }
  if (result.status?.orphanMessages > 0) {
    return 'メモの親チャンネルがサーバーにありません。アプリから再同期してください。読み取りデータの再構築だけでは復元できません。';
  }
  return 'サーバーにチャンネルがありません。アプリから同期してください。';
}

async function refreshPlugins() {
  try {
    const response = await api('../v1/admin/plugins');
    const cards = await Promise.all(response.plugins.map(renderPlugin));
    $('plugins').replaceChildren(...cards);
  } catch (error) {
    $('plugins').replaceChildren(node('p', `プラグイン一覧を取得できません: ${String(error.message || error)}`, 'error'));
  }
}

async function renderPlugin(plugin) {
  const card = node('article', '', 'plugin-card');
  card.appendChild(node('h3', plugin.name?.ja || plugin.id));
  card.appendChild(node('p', plugin.description?.ja || '', 'muted'));
  card.appendChild(node('p', `${plugin.id} / v${plugin.version} / ${plugin.enabled ? 'ON' : 'OFF'}`, 'muted'));
  if (plugin.lastError) card.appendChild(node('p', plugin.lastError, 'error'));
  if (plugin.delivery?.retry || plugin.delivery?.dead) {
    card.appendChild(node('p', `イベント配送: 再試行待ち ${plugin.delivery.retry}件、停止 ${plugin.delivery.dead}件`, 'error'));
  }
  const row = node('div', '', 'row');
  row.appendChild(action(plugin.enabled ? '無効にする' : '有効にする', async () => {
    await api(`../v1/admin/plugins/${plugin.id}/${plugin.enabled ? 'disable' : 'enable'}`, { method: 'POST', body: '{}' });
    await refresh();
  }, plugin.enabled ? 'secondary' : ''));
  if (plugin.enabled && plugin.delivery?.dead) {
    row.appendChild(action('失敗イベントを再試行', async () => {
      await api(`../v1/admin/plugins/${plugin.id}/retry-events`, { method: 'POST', body: '{}' });
      await refresh();
    }, 'secondary'));
  }
  card.appendChild(row);
  const detail = document.createElement('details');
  detail.appendChild(node('summary', 'メタ情報・設定'));
  detail.appendChild(node('p', `権限: ${(plugin.capabilities || []).join(', ') || 'なし'}`, 'muted'));
  if (plugin.minServerVersion) detail.appendChild(node('p', `必要なサーバー: ${plugin.minServerVersion}以上`, 'muted'));
  if (plugin.author) detail.appendChild(node('p', `作者: ${plugin.author}`, 'muted'));
  if (typeof plugin.website === 'string' && /^https:\/\//.test(plugin.website)) {
    const link = node('a', 'プラグインのWebサイト');
    link.href = plugin.website;
    link.rel = 'noopener noreferrer';
    link.target = '_blank';
    detail.appendChild(link);
  }
  if (plugin.legacy) detail.appendChild(node('p', '従来形式です。設定画面とHTTPルートはありません。', 'muted'));
  if (plugin.settingsSchema?.length) {
    const form = document.createElement('form');
    const fields = [];
    for (const spec of plugin.settingsSchema) {
      const label = node('label', spec.label?.ja || spec.key);
      const channelResult = spec.type === 'channel' ? await loadChannels() : null;
      const input = spec.type === 'textarea' ? document.createElement('textarea')
        : spec.type === 'channel' ? channelSelect(channelResult.channels) : document.createElement('input');
      if (input instanceof HTMLInputElement) {
        input.type = spec.type === 'boolean' ? 'checkbox' : spec.type === 'integer' ? 'number' : 'text';
      }
      input.id = `plugin-${plugin.id}-${spec.key}`;
      label.htmlFor = input.id;
      if (spec.type === 'boolean') input.checked = plugin.settings?.[spec.key] ?? spec.default ?? false;
      else input.value = plugin.settings?.[spec.key] ?? spec.default ?? '';
      if (spec.min !== undefined) input.min = spec.min;
      if (spec.max !== undefined) input.max = spec.max;
      form.append(label, input);
      if (channelResult && !channelResult.channels.length) {
        form.appendChild(node('p', channelEmptyMessage(channelResult), channelResult.error ? 'error' : 'muted'));
      }
      fields.push([spec, input]);
    }
    const save = node('button', '設定を保存');
    save.type = 'submit';
    form.appendChild(save);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const settings = Object.fromEntries(fields.map(([spec, input]) => [spec.key,
        spec.type === 'boolean' ? input.checked : spec.type === 'integer' ? Number(input.value) : input.value]));
      save.disabled = true;
      try {
        await api(`../v1/admin/plugins/${plugin.id}/settings`, { method: 'PUT', body: JSON.stringify(settings) });
        $('message').textContent = `${plugin.name?.ja || plugin.id}の設定を保存しました。`;
      } catch (error) { handleError(error); }
      finally { save.disabled = false; }
    });
    detail.appendChild(form);
  }
  card.appendChild(detail);
  if (plugin.enabled && plugin.id === 'mcp') card.appendChild(await renderMcp());
  if (plugin.enabled && plugin.id === 'webhook') card.appendChild(await renderWebhook());
  if (plugin.enabled && plugin.id === 'public_channel') card.appendChild(await renderPublicChannels());
  return card;
}

function channelSelect(channels) {
  const select = document.createElement('select');
  for (const channel of channels) {
    const option = document.createElement('option');
    option.value = channel.id;
    option.textContent = channel.name;
    select.appendChild(option);
  }
  return select;
}

async function renderWebhook() {
  const area = node('div', '', 'plugin-resource');
  area.appendChild(node('h4', '接続先'));
  const [response, channelResult] = await Promise.all([
    api('../v1/admin/plugins/webhook/routes/endpoints').catch(() => ({ endpoints: [] })), loadChannels(),
  ]);
  const channels = channelResult.channels;
  const form = document.createElement('form');
  const label = document.createElement('input');
  label.placeholder = '名前（例: 通知）';
  label.required = true;
  const select = channelSelect(channels);
  const submit = node('button', 'Webhookを作成');
  submit.type = 'submit';
  submit.disabled = channels.length === 0;
  form.append(node('label', '名前'), label, node('label', '投稿先チャンネル'), select, submit);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const created = await api('../v1/admin/plugins/webhook/routes/endpoints', {
        method: 'POST', body: JSON.stringify({ label: label.value, channelId: select.value }),
      });
      $('plugin-secret').textContent = currentServerUrl() + created.path;
      $('plugin-secret-message').textContent = '';
      $('plugin-secret-dialog').showModal();
      await refreshPlugins();
    } catch (error) { handleError(error); }
  });
  area.appendChild(form);
  const list = node('div', '', 'plugin-resource-list');
  for (const endpoint of response.endpoints) {
    const item = document.createElement('div');
    item.append(node('strong', `${endpoint.label} → ${endpoint.channelId}`),
      node('p', `ID: ${endpoint.id}（送信URLは発行時のみ表示されます）`, 'muted'));
    item.appendChild(action('失効', async () => {
      await api(`../v1/admin/plugins/webhook/routes/endpoints/${endpoint.id}/revoke`, { method: 'POST', body: '{}' });
      await refreshPlugins();
    }, 'danger'));
    list.appendChild(item);
  }
  area.appendChild(list);
  if (!channels.length) area.appendChild(node('p', channelEmptyMessage(channelResult), channelResult.error ? 'error' : 'muted'));
  return area;
}

async function renderPublicChannels() {
  const area = node('div', '', 'plugin-resource');
  area.appendChild(node('h4', '公開するチャンネル'));
  area.appendChild(node('p', '公開すると、URLを知っている人は誰でもメモを読めます。添付ファイルは公開されません。', 'warning'));
  const [response, channelResult] = await Promise.all([
    api('../v1/admin/plugins/public_channel/routes/shares').catch(() => ({ shares: [] })), loadChannels(),
  ]);
  const channels = channelResult.channels;
  const form = document.createElement('form');
  const select = channelSelect(channels);
  const submit = node('button', '公開URLを作成');
  submit.type = 'submit';
  submit.disabled = channels.length === 0;
  form.append(node('label', 'チャンネル'), select, submit);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!window.confirm('このチャンネルのメモをインターネットに公開します。続けますか？')) return;
    try {
      await api('../v1/admin/plugins/public_channel/routes/shares', {
        method: 'POST', body: JSON.stringify({ channelId: select.value }),
      });
      await refreshPlugins();
    } catch (error) { handleError(error); }
  });
  area.appendChild(form);
  const list = node('div', '', 'plugin-resource-list');
  for (const share of response.shares) {
    const item = document.createElement('div');
    const url = currentServerUrl() + share.path;
    item.append(node('strong', `${share.label}：${share.enabled ? '公開中' : '停止中'}`));
    if (share.enabled) {
      const link = node('a', url);
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      item.append(node('p', '', 'muted'), link);
    }
    item.appendChild(action(share.enabled ? '公開を停止' : '再公開', async () => {
      await api(`../v1/admin/plugins/public_channel/routes/shares/${share.id}/${share.enabled ? 'disable' : 'enable'}`,
        { method: 'POST', body: '{}' });
      await refreshPlugins();
    }, share.enabled ? 'danger' : ''));
    item.appendChild(action('URLを再生成', async () => {
      await api(`../v1/admin/plugins/public_channel/routes/shares/${share.id}/rotate`, { method: 'POST', body: '{}' });
      await refreshPlugins();
    }, 'secondary'));
    list.appendChild(item);
  }
  area.appendChild(list);
  if (!channels.length) area.appendChild(node('p', channelEmptyMessage(channelResult), channelResult.error ? 'error' : 'muted'));
  return area;
}

$('copy-plugin-secret').addEventListener('click', async () => {
  await copyTextFrom('plugin-secret', '接続情報をコピーしました。');
  $('plugin-secret-message').textContent = $('copy-message').textContent;
});
$('close-plugin-secret').addEventListener('click', () => {
  $('plugin-secret-dialog').close();
});
$('plugin-secret-dialog').addEventListener('close', () => {
  $('plugin-secret').textContent = '';
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

async function renderMcp() {
  const area = node('div', '', 'plugin-resource');
  area.append(node('h4', '外部AI用のMCP接続'), node('p', '読み取り用とアプリ中継用のトークンは別々に発行します。中継用は1端末分のみ有効です。', 'muted'));
  const [response, channelResult] = await Promise.all([api('../v1/admin/plugins/mcp/routes/tokens'), loadChannels()]);
  const form = document.createElement('form'), label = document.createElement('input'), kind = document.createElement('select'), select = channelSelect(channelResult.channels);
  for (const [value, text] of [['read', '外部AIの読み取り用'], ['worker', 'Solocordアプリ中継用']]) { const option = node('option', text); option.value = value; kind.appendChild(option); }
  const changes = document.createElement('input'); changes.type = 'checkbox';
  const all = node('option', '全サーバー・全チャンネル'); all.value = '*'; select.prepend(all); select.value = '*';
  const submit = node('button', 'トークンを発行'); submit.type = 'submit'; submit.disabled = !channelResult.guilds?.length;
  form.append(node('label', '接続名'), label, node('label', '用途'), kind, node('label', '許可するチャンネル'), select, node('label', '変更案を許可（適用にはSolocordアプリでの承認が必要）'), changes, submit);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const channel = channelResult.channels.find(c => c.id === select.value);
      if (select.value !== '*' && !channel?.guild_id) throw Error('サーバー情報がありません。同期と読み取りモデルの準備を確認してください。');
      const created = await api('../v1/admin/plugins/mcp/routes/tokens', { method: 'POST', body: JSON.stringify({ label: label.value, kind: kind.value, allowChanges: changes.checked, scope: select.value === '*' ? { guildIds: channelResult.guilds.map(g => g.id) } : { guildIds: [channel.guild_id], channelIds: [channel.id] } }) });
      $('plugin-secret').textContent = JSON.stringify({ url: currentServerUrl() + created.path, token: created.secret, groupId: created.groupId }, null, 2);
      $('plugin-secret-message').textContent = 'このトークンは今回だけ表示します。'; $('plugin-secret-dialog').showModal(); await refreshPlugins();
    } catch (error) { handleError(error); }
  });
  area.appendChild(form);
  for (const token of response.tokens) { const item = node('div', '', 'plugin-resource-list'); item.append(node('strong', token.label), node('p', `${token.kind === 'worker' ? 'アプリ中継用' : '読み取り用'} · ${token.scopeJson}`, 'muted')); item.appendChild(action('失効', async () => { await api(`../v1/admin/plugins/mcp/routes/tokens/${token.id}/revoke`, { method: 'POST', body: '{}' }); await refreshPlugins(); }, 'danger')); area.appendChild(item); }
  return area;
}
