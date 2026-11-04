const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const template = readFileSync(resolve(__dirname, '../../resources/views/monthly-reports/public.blade.php'), 'utf8');
const paginationScript = template.slice(template.indexOf("    document.querySelectorAll('[data-report-pagination]')"), template.lastIndexOf('</script>'));

function element(properties = {}) {
    return {
        hidden: false,
        textContent: '',
        listeners: {},
        addEventListener(event, listener) { this.listeners[event] = listener; },
        emit(event) { return this.listeners[event]?.({}); },
        ...properties,
    };
}

function setup(rows) {
    const previous = element();
    const next = element();
    const status = element();
    const heading = element();
    const count = element();
    const empty = element();
    const month = element({ value: '2026-10', selectedOptions: [{ textContent: 'October 2026 so far' }] });
    const search = element({ value: '' });
    const controls = element({
        querySelector(selector) {
            return { '[data-page-previous]': previous, '[data-page-next]': next, '[data-page-status]': status }[selector];
        },
        remove() { throw new Error('Filter controls must remain available for short or empty histories.'); },
    });
    const targets = {
        '[data-pagination-controls]': controls,
        '[data-change-log-month]': month,
        '[data-change-log-search]': search,
        '[data-change-log-heading]': heading,
        '[data-change-log-count]': count,
        '[data-change-log-empty]': empty,
    };
    const table = element({
        dataset: { pageSize: '10' },
        querySelectorAll: () => rows,
        parentElement: { querySelector: (selector) => targets[selector] || null },
    });
    runInNewContext(paginationScript, { document: { querySelectorAll: () => [table] } });
    return { previous, next, status, heading, count, empty, month, search, controls, visible: () => rows.filter((row) => !row.hidden) };
}

function row(month, text) {
    return element({ dataset: { changeMonth: month }, textContent: text, querySelector: () => null });
}

test('paginates only the selected month and resets pages when month or search changes', () => {
    const rows = Array.from({ length: 23 }, (_, index) => row('2026-10', `October ticket ${index} Alice Open Closed`));
    const september = row('2026-09', 'September ticket Bob Pending Closed');
    const state = setup([...rows, september]);
    assert.equal(state.visible().length, 10);
    assert.equal(state.status.textContent, 'Page 1 of 3');
    assert.equal(state.previous.disabled, true);
    assert.equal(september.hidden, true);
    state.next.emit('click');
    assert.equal(state.visible()[0], rows[10]);
    state.next.emit('click');
    assert.equal(state.visible().length, 3);
    assert.equal(state.next.disabled, true);
    state.previous.emit('click');
    assert.equal(state.visible()[0], rows[10]);
    state.search.value = '  ALICE  ';
    state.search.emit('input');
    assert.equal(state.status.textContent, 'Page 1 of 3');
    state.search.value = 'no match';
    state.search.emit('input');
    assert.equal(state.visible().length, 0);
    assert.equal(state.empty.hidden, false);
    assert.equal(state.controls.hidden, true);
    state.month.value = '2026-09';
    state.month.selectedOptions = [{ textContent: 'September 2026' }];
    state.month.emit('change');
    assert.equal(state.visible().length, 0);
    state.search.value = 'pending';
    state.search.emit('input');
    assert.deepEqual(state.visible(), [september]);
    assert.equal(state.heading.textContent, 'September 2026');
    assert.equal(state.empty.hidden, true);
    assert.equal(state.count.textContent, '1 change(s)');
    state.month.value = '2026-10';
    state.month.emit('change');
    state.search.value = '';
    state.search.emit('input');
    assert.equal(state.visible().length, 10);
    assert.equal(state.status.textContent, 'Page 1 of 3');
});

test('keeps filters working for empty and single-row histories', () => {
    const empty = setup([]);
    assert.equal(empty.empty.hidden, false);
    assert.equal(empty.controls.hidden, true);
    assert.equal(empty.count.textContent, '0 change(s)');
    const single = setup([row('2026-09', 'An older change')]);
    assert.equal(single.visible().length, 0);
    single.month.value = '2026-09';
    single.month.emit('change');
    assert.equal(single.visible().length, 1);
});

function setupInspector(fetch) {
    const targets = {};
    const dialog = element({
        open: false,
        querySelector(selector) {
            return targets[selector] ||= element({
                replaceChildren() {}, appendChild() {},
            });
        },
        setAttribute(key, value) { this[key] = value; },
        removeAttribute(key) { delete this[key]; },
        showModal() { this.open = true; },
        close() { this.open = false; this.emit('close'); },
    });
    const findingRow = element({
        dataset: { inspectorFindingArn: 'arn:aws:inspector2:ap-southeast-2:123456789012:finding/example' },
        cells: [{}, { firstChild: { textContent: 'Finding title' } }],
    });
    const script = template.slice(template.indexOf("    const inspectorFindingDialog ="), template.indexOf("    document.querySelectorAll('[data-report-pagination]')"));
    runInNewContext(script, {
        document: {
            getElementById: () => dialog,
            querySelectorAll: () => [findingRow],
            createElement: () => element({ appendChild() {} }),
        },
        window: { location: { href: 'https://dashboard.example.test/reports/monthly/report-token?old=value' } },
        URL, AbortController, fetch,
    });
    return { dialog, findingRow, targets };
}

test('loads only clicked Inspector finding details and shows loading state', async () => {
    let respond;
    let calls = 0;
    const state = setupInspector((url, options) => {
        calls++;
        assert.equal(url.pathname, '/reports/monthly/report-token/inspector-finding');
        assert.equal(url.searchParams.get('arn'), state.findingRow.dataset.inspectorFindingArn);
        assert.equal(url.searchParams.has('old'), false);
        assert.equal(options.cache, 'no-store');
        return new Promise((resolve) => { respond = resolve; });
    });
    assert.equal(calls, 0);
    const pending = state.findingRow.emit('click');
    assert.equal(state.dialog.open, true);
    assert.equal(state.dialog['aria-busy'], 'true');
    assert.equal(state.targets['[data-inspector-loading-status]'].textContent, 'Loading finding details...');
    respond({ ok: true, json: async () => ({ title: 'Full finding title', description: 'Full description' }) });
    await pending;
    assert.equal(calls, 1);
    assert.equal(state.targets['#inspector-finding-title'].textContent, 'Full finding title');
    assert.equal(state.targets['[data-inspector-detail="description"]'].textContent, 'Full description');
    assert.equal(state.targets['[data-inspector-loading-status]'].textContent, '');
    assert.equal(state.dialog['aria-busy'], undefined);
});

test('Inspector detail failures offer retry and clear errors after success', async () => {
    let calls = 0;
    const state = setupInspector(async () => ++calls === 1
        ? { ok: false, status: 502 }
        : { ok: true, json: async () => ({ title: 'Recovered finding' }) });
    await state.findingRow.emit('click');
    assert.equal(state.targets['[data-retry-inspector-finding]'].hidden, false);
    assert.match(state.targets['[data-inspector-loading-status]'].textContent, /Please retry/);
    await state.targets['[data-retry-inspector-finding]'].emit('click');
    assert.equal(calls, 2);
    assert.equal(state.targets['[data-retry-inspector-finding]'].hidden, true);
    assert.equal(state.targets['[data-inspector-loading-status]'].textContent, '');
    assert.equal(state.targets['#inspector-finding-title'].textContent, 'Recovered finding');
});

test('closing the Inspector dialog cancels loading without reopening it', async () => {
    let respond;
    let signal;
    const state = setupInspector((url, options) => {
        signal = options.signal;
        return new Promise((resolve) => { respond = resolve; });
    });
    const pending = state.findingRow.emit('click');
    state.dialog.close();
    assert.equal(signal.aborted, true);
    respond({ ok: true, json: async () => ({ title: 'Stale finding' }) });
    await pending;
    assert.equal(state.dialog.open, false);
    assert.equal(state.targets['#inspector-finding-title'].textContent, 'Finding title');
});