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
        emit(event) { this.listeners[event]?.({}); },
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