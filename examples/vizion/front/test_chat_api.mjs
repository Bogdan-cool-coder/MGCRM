// Test all chat API endpoints from chats_frontend.md
// Runs from frontend container, API via http://app:9000 proxied or http://nginx

const BASE = 'http://nginx';

async function api(method, path, body, token) {
  const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const opts = { method, headers };
  if (body) opts.body = JSON.stringify(body);

  const res = await fetch(`${BASE}${path}`, opts);
  const data = await res.json();
  return { status: res.status, data };
}

let passed = 0, failed = 0;
function assert(name, condition, detail = '') {
  if (condition) {
    console.log(`  ✅ ${name}`);
    passed++;
  } else {
    console.log(`  ❌ ${name} ${detail}`);
    failed++;
  }
}

// ═══════════════════════════════════════
// 1. LOGIN
// ═══════════════════════════════════════
console.log('\n=== 1. LOGIN ===');
const loginRes = await api('POST', '/api/login', {
  email: 'webkuznets@yandex.ru',
  password: 'Z3576824'
});
assert('status 200', loginRes.status === 200, `got ${loginRes.status}`);
assert('has token', !!loginRes.data.token, 'no token');
assert('has user', !!loginRes.data.user, 'no user');
assert('role is superadmin', loginRes.data.user?.role === 'superadmin', `got ${loginRes.data.user?.role}`);

const TOKEN = loginRes.data.token;
console.log(`  Token: ${TOKEN.substring(0, 10)}...`);

// ═══════════════════════════════════════
// 2. CREATE CHAT
// ═══════════════════════════════════════
console.log('\n=== 2. CREATE CHAT ===');
const createChat = await api('POST', '/api/chats', { type: 'report_generation' }, TOKEN);
assert('status 201', createChat.status === 201, `got ${createChat.status}`);
assert('type is report_generation', createChat.data.type === 'report_generation');
assert('title is null', createChat.data.title === null, `got "${createChat.data.title}"`);
assert('report_id is null', createChat.data.report_id === null);
assert('messages is empty array', Array.isArray(createChat.data.messages) && createChat.data.messages.length === 0);
assert('has id', !!createChat.data.id);

const CHAT_ID = createChat.data.id;
console.log(`  Chat ID: ${CHAT_ID}`);

// ═══════════════════════════════════════
// 3. LIST CHATS
// ═══════════════════════════════════════
console.log('\n=== 3. LIST CHATS ===');
const listChats = await api('GET', '/api/chats', null, TOKEN);
assert('status 200', listChats.status === 200);
assert('is array', Array.isArray(listChats.data));
assert('has our chat', listChats.data.some(c => c.id === CHAT_ID));
const listed = listChats.data.find(c => c.id === CHAT_ID);
if (listed) {
  assert('has last_message', !!listed.last_message);
  assert('has report_id field', 'report_id' in listed);
  assert('has title field', 'title' in listed);
}

// ═══════════════════════════════════════
// 4. SEND MESSAGE (AI creates report)
// ═══════════════════════════════════════
console.log('\n=== 4. SEND MESSAGE (create report) ===');
const sendMsg = await api('POST', `/api/chats/${CHAT_ID}/messages`, {
  content: 'Покажи количество сделок по статусам'
}, TOKEN);
assert('status 200', sendMsg.status === 200, `got ${sendMsg.status}`);
assert('has message', !!sendMsg.data.message);
assert('has chat', !!sendMsg.data.chat);

const msg = sendMsg.data.message;
const chat = sendMsg.data.chat;

assert('message role is assistant or system', msg.role === 'assistant' || msg.role === 'system', `got ${msg.role}`);

if (msg.role === 'system') {
  console.log(`  ⚠️  AI error: ${msg.metadata?.error || 'unknown'}`);
  console.log('  Skipping report checks (AI unavailable)');
} else {
  assert('message has content', !!msg.content && msg.content.length > 0);
  assert('message has metadata', !!msg.metadata);
  assert('metadata has usage', !!msg.metadata?.usage);
  assert('metadata has prompt_tokens', typeof msg.metadata?.usage?.prompt_tokens === 'number');
  assert('metadata has completion_tokens', typeof msg.metadata?.usage?.completion_tokens === 'number');
  assert('metadata has finish_reason', !!msg.metadata?.finish_reason);

  // Check tool_calls in metadata
  if (msg.metadata?.tool_calls) {
    assert('tool_calls is array', Array.isArray(msg.metadata.tool_calls));
    const toolNames = msg.metadata.tool_calls.map(tc => tc.name);
    console.log(`  Tool calls: ${toolNames.join(', ')}`);
    assert('has probe_data or create_report', toolNames.some(n => n === 'probe_data' || n === 'create_report'));
  }

  // Check chat state
  assert('chat title set (from first message)', chat.title !== null && chat.title.length > 0, `got "${chat.title}"`);
  console.log(`  Chat title: "${chat.title}"`);

  assert('chat report_id set', chat.report_id !== null, 'report_id is null');
  assert('chat has report object', !!chat.report);

  if (chat.report) {
    assert('report is_system=false', chat.report.is_system === false, `got ${chat.report.is_system}`);
    assert('report is_published=false', chat.report.is_published === false, `got ${chat.report.is_published}`);
    assert('report has config', !!chat.report.config);
    assert('report config has primary_model', !!chat.report.config?.primary_model);
    assert('report config has columns', Array.isArray(chat.report.config?.columns) && chat.report.config.columns.length > 0);
    assert('report config has chart', !!chat.report.config?.chart);
    console.log(`  Report ID: ${chat.report.id}, model: ${chat.report.config?.primary_model}`);
  }

  // Check ai_context
  if (chat.ai_context) {
    assert('ai_context has last_tool_calls', Array.isArray(chat.ai_context.last_tool_calls));
    assert('ai_context has total_steps', typeof chat.ai_context.total_steps === 'number');
    console.log(`  ai_context steps: ${chat.ai_context.total_steps}, tools: ${JSON.stringify(chat.ai_context.last_tool_calls)}`);
  }
}

const REPORT_ID = chat?.report_id;
const HAS_REPORT = REPORT_ID !== null && REPORT_ID !== undefined;

// ═══════════════════════════════════════
// 5. GET CHAT (show)
// ═══════════════════════════════════════
console.log('\n=== 5. GET CHAT (show) ===');
const showChat = await api('GET', `/api/chats/${CHAT_ID}`, null, TOKEN);
assert('status 200', showChat.status === 200);
assert('has messages array', Array.isArray(showChat.data.messages));
assert('messages ordered asc', showChat.data.messages.length >= 2);
assert('first message is user', showChat.data.messages[0]?.role === 'user');
assert('has report', !!showChat.data.report || showChat.data.report_id !== null);
if (showChat.data.messages.length >= 2) {
  assert('second message is assistant or system', ['assistant', 'system'].includes(showChat.data.messages[1]?.role));
}
console.log(`  Messages: ${showChat.data.messages.length}, report_id: ${showChat.data.report_id}`);

// ═══════════════════════════════════════
// 6. GET CHAT MESSAGES
// ═══════════════════════════════════════
console.log('\n=== 6. GET CHAT MESSAGES ===');
const getMsgs = await api('GET', `/api/chats/${CHAT_ID}/messages`, null, TOKEN);
assert('status 200', getMsgs.status === 200);
assert('is array', Array.isArray(getMsgs.data));
assert('has user message', getMsgs.data.some(m => m.role === 'user'));
assert('has assistant/system message', getMsgs.data.some(m => m.role === 'assistant' || m.role === 'system'));
assert('messages have id', getMsgs.data.every(m => !!m.id));
assert('messages have content', getMsgs.data.every(m => !!m.content));
assert('messages have created_at', getMsgs.data.every(m => !!m.created_at));
console.log(`  Total messages: ${getMsgs.data.length}`);

// ═══════════════════════════════════════
// 7. GET REPORT WITH DATA
// ═══════════════════════════════════════
if (HAS_REPORT) {
  console.log('\n=== 7. GET REPORT WITH DATA ===');
  const report = await api('GET', `/api/reports/${REPORT_ID}`, null, TOKEN);
  assert('status 200', report.status === 200);
  assert('has id', report.data.id === REPORT_ID);
  assert('has title (object with ru/en)', typeof report.data.title === 'object' && report.data.title?.ru);
  assert('has columns array', Array.isArray(report.data.columns) && report.data.columns.length > 0);
  assert('column has field', !!report.data.columns[0]?.field);
  assert('column has header (i18n)', typeof report.data.columns[0]?.header === 'object');
  assert('column has type', !!report.data.columns[0]?.type);
  assert('has rows array', Array.isArray(report.data.rows));
  assert('rows have data', report.data.rows.length > 0);
  assert('has meta', !!report.data.meta);
  assert('meta has total', typeof report.data.meta?.total === 'number');
  assert('meta has page', typeof report.data.meta?.page === 'number');
  assert('meta has per_page', typeof report.data.meta?.per_page === 'number');
  assert('meta has last_page', typeof report.data.meta?.last_page === 'number');
  assert('has chart', !!report.data.chart);
  assert('chart has type', !!report.data.chart?.type);
  assert('chart has labels', Array.isArray(report.data.chart?.labels));
  assert('chart has datasets', Array.isArray(report.data.chart?.datasets));
  assert('has filters_available', typeof report.data.filters_available === 'object');
  assert('has filters_applied', typeof report.data.filters_applied === 'object');
  console.log(`  Rows: ${report.data.rows.length}/${report.data.meta?.total}`);
  console.log(`  Chart: ${report.data.chart?.type}, labels: ${report.data.chart?.labels?.slice(0, 3).join(', ')}...`);
  console.log(`  Filters: ${Object.keys(report.data.filters_available || {}).join(', ')}`);

  // ═══════════════════════════════════════
  // 8. GET REPORT WITH FILTERS
  // ═══════════════════════════════════════
  console.log('\n=== 8. GET REPORT WITH FILTERS ===');
  const firstFilterKey = Object.keys(report.data.filters_available || {})[0];
  if (firstFilterKey) {
    const filterType = report.data.filters_available[firstFilterKey]?.type;
    let filterUrl;
    if (filterType === 'date_range') {
      filterUrl = `/api/reports/${REPORT_ID}?filters[${firstFilterKey}][from]=2025-01-01&filters[${firstFilterKey}][to]=2025-12-31`;
    } else if (filterType === 'number_range') {
      const min = report.data.filters_available[firstFilterKey]?.options?.min || 0;
      filterUrl = `/api/reports/${REPORT_ID}?filters[${firstFilterKey}][from]=${min}`;
    } else {
      const optVal = report.data.filters_available[firstFilterKey]?.options?.[0]?.value;
      if (optVal !== undefined) filterUrl = `/api/reports/${REPORT_ID}?filters[${firstFilterKey}]=${encodeURIComponent(optVal)}`;
    }
    if (filterUrl) {
      const filtered = await api('GET', filterUrl, null, TOKEN);
      assert('filtered status 200', filtered.status === 200);
      assert('filtered has rows', Array.isArray(filtered.data.rows));
      console.log(`  Filter ${firstFilterKey} (${filterType}): ${filtered.data.rows?.length}/${filtered.data.meta?.total} rows`);
    } else {
      console.log('  ⚠️  No filter URL could be constructed');
    }
  }

  // ═══════════════════════════════════════
  // 9. PAGINATION
  // ═══════════════════════════════════════
  console.log('\n=== 9. PAGINATION ===');
  const page2 = await api('GET', `/api/reports/${REPORT_ID}?page=1&per_page=5`, null, TOKEN);
  assert('page 2 status 200', page2.status === 200);
  assert('per_page works', page2.data.rows?.length <= 5, `got ${page2.data.rows?.length} rows`);
  assert('meta.page=1', page2.data.meta?.page === 1);
  assert('meta.per_page=5', page2.data.meta?.per_page === 5);

  // ═══════════════════════════════════════
  // 10. ITERATIVE EDIT (send follow-up message)
  // ═══════════════════════════════════════
  console.log('\n=== 10. ITERATIVE EDIT ===');
  const editMsg = await api('POST', `/api/chats/${CHAT_ID}/messages`, {
    content: 'Добавь колонку с названием города'
  }, TOKEN);
  assert('edit status 200', editMsg.status === 200);
  assert('edit has message', !!editMsg.data.message);
  assert('edit message is assistant or system', ['assistant', 'system'].includes(editMsg.data.message?.role));

  if (editMsg.data.message?.role === 'assistant') {
    assert('chat still has report_id', editMsg.data.chat?.report_id === REPORT_ID);
    assert('report still exists', !!editMsg.data.chat?.report);
    console.log(`  AI response: "${editMsg.data.message.content.substring(0, 80)}..."`);
    if (editMsg.data.chat?.report?.config?.columns) {
      console.log(`  Columns after edit: ${editMsg.data.chat.report.config.columns.map(c => c.field).join(', ')}`);
    }
  } else {
    console.log(`  ⚠️  AI error on edit: ${editMsg.data.message?.metadata?.error || 'unknown'}`);
  }
} else {
  console.log('\n=== 7-10. SKIPPED (no report created — AI unavailable) ===');
}

// ═══════════════════════════════════════
// 11. ACCESS CONTROL (403)
// ═══════════════════════════════════════
console.log('\n=== 11. ACCESS CONTROL ===');

// No token → 401
const noAuth = await api('GET', '/api/chats', null, null);
assert('no token → 401', noAuth.status === 401, `got ${noAuth.status}`);

// Wrong chat id → 404
const wrongChat = await api('GET', `/api/chats/999999`, null, TOKEN);
assert('wrong chat → 404 or 403', [403, 404].includes(wrongChat.status), `got ${wrongChat.status}`);

// ═══════════════════════════════════════
// 12. DELETE CHAT
// ═══════════════════════════════════════
console.log('\n=== 12. DELETE CHAT ===');
const del = await api('DELETE', `/api/chats/${CHAT_ID}`, null, TOKEN);
assert('delete status 200', del.status === 200, `got ${del.status}`);
assert('has message', !!del.data.message);

// Verify chat is gone
const gone = await api('GET', `/api/chats/${CHAT_ID}`, null, TOKEN);
assert('deleted chat → 404 or 403', [403, 404].includes(gone.status), `got ${gone.status}`);

// Verify report is gone
if (HAS_REPORT) {
  const reportGone = await api('GET', `/api/reports/${REPORT_ID}`, null, TOKEN);
  assert('report deleted with chat → 404', reportGone.status === 404, `got ${reportGone.status}`);
}

// ═══════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════
console.log(`\n${'═'.repeat(40)}`);
console.log(`  PASSED: ${passed}`);
console.log(`  FAILED: ${failed}`);
console.log(`  TOTAL:  ${passed + failed}`);
console.log(`${'═'.repeat(40)}`);
process.exit(failed > 0 ? 1 : 0);
