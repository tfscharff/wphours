// Tests for the shared JS resolver (mcwResolve / mcwSchedule) that the plugin embeds in its pages.
// Run: node --test tests/js/   (needs php on PATH, or set PHP to its full path)
// The resolver source is not copied here: tests/js-bridge.php prints it from mcw_hours_resolve_js(),
// and this file evaluates that source with node:vm, so a change to the plugin's JS changes these results.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const bridge = fileURLToPath(new URL('../js-bridge.php', import.meta.url));
const { js, cases } = JSON.parse(execFileSync(process.env.PHP || 'php', [bridge], { encoding: 'utf8', maxBuffer: 1 << 24 }));

const ctx = vm.createContext({});
vm.runInContext(js + '\nthis.mcwResolve=mcwResolve;this.mcwSchedule=mcwSchedule;', ctx);
// Results are built inside the vm realm; round-trip through JSON so deepEqual compares plain values.
const plain = v => JSON.parse(JSON.stringify(v));
const resolve = (sch, date) => {
	const dow = new Date(date + 'T00:00:00Z').getUTCDay();
	return plain(ctx.mcwResolve(plain(sch), date, dow));
};

test('the plugin supplies the resolver functions', () => {
	assert.equal(typeof ctx.mcwResolve, 'function');
	assert.equal(typeof ctx.mcwSchedule, 'function');
});

for (const c of cases) {
	test('fixture: ' + c.name, () => {
		assert.deepEqual(resolve(c.schedule, c.date), c.expected);
	});
	test('parity with PHP: ' + c.name, () => {
		const js = resolve(c.schedule, c.date);
		assert.deepEqual(js.blocks, c.php.blocks);
		assert.equal(js.note, c.php.note);
		assert.equal(js.blocks.length === 0, c.php.closed);
	});
}

const B = (o, c) => ({ open: o, close: c });

test('weekly day keys work as an object map or a JSON list', () => {
	const asObject = { weekly: { 3: [B('08:00', '17:00')] } };
	assert.deepEqual(resolve(asObject, '2026-10-28').blocks, [B('08:00', '17:00')]);
});

test('missing sections and a missing schedule do not throw', () => {
	assert.deepEqual(resolve({}, '2026-10-28'), { blocks: [], note: '' });
	assert.deepEqual(resolve({ exceptions: null, ranges: null, weekly: null }, '2026-10-28'), { blocks: [], note: '' });
});

test('malformed weekly values give no blocks, never a string character', () => {
	assert.deepEqual(resolve({ weekly: 'junk' }, '2026-10-28').blocks, []);
	assert.deepEqual(resolve({ weekly: { 3: 'junk' } }, '2026-10-28').blocks, []);
	assert.deepEqual(resolve({ weekly: { 3: { open: '08:00' } } }, '2026-10-28').blocks, []);
	assert.deepEqual(resolve({ ranges: [{ start: '2026-10-01', end: '2026-10-31', weekly: { 3: 'junk' } }] }, '2026-10-28').blocks, []);
});

test('mcwSchedule: library schedule is the top-level weekly, exceptions and ranges', () => {
	const cfg = { weekly: { 3: [B('08:00', '17:00')] }, exceptions: { '2026-10-28': { closed: true, note: 'x' } },
		guest: { weekly: { 3: [B('10:00', '14:00')] }, exceptions: {}, ranges: [] } };
	const lib = plain(ctx.mcwSchedule(cfg, 'library'));
	assert.deepEqual(lib.weekly, cfg.weekly);
	assert.deepEqual(lib.exceptions, cfg.exceptions);
	assert.deepEqual(lib.ranges, []);
	assert.deepEqual(plain(ctx.mcwSchedule(cfg)), lib);
});

test('mcwSchedule: guest schedule resolves independently of the library one', () => {
	const cfg = { weekly: { 3: [B('08:00', '24:00')] }, exceptions: { '2026-10-28': { closed: true, note: 'Library only' } }, ranges: [],
		guest: { weekly: { 3: [B('10:00', '14:00')] }, exceptions: {}, ranges: [] } };
	assert.deepEqual(resolve(ctx.mcwSchedule(cfg, 'library'), '2026-10-28'), { blocks: [], note: 'Library only' });
	assert.deepEqual(resolve(ctx.mcwSchedule(cfg, 'guest'), '2026-10-28'), { blocks: [B('10:00', '14:00')], note: '' });
});

test('mcwSchedule: asking for guest without a guest schedule gives the library one', () => {
	const cfg = { weekly: { 3: [B('08:00', '17:00')] }, exceptions: {}, ranges: [] };
	assert.deepEqual(plain(ctx.mcwSchedule(cfg, 'guest')), plain(ctx.mcwSchedule(cfg, 'library')));
});

test('a reversed block (close before open) is passed through unchanged', () => {
	// Pinning current behaviour; see CLAUDE.md. The resolver does not interpret overnight hours.
	assert.deepEqual(resolve({ weekly: { 3: [B('18:00', '09:00')] } }, '2026-10-28').blocks, [B('18:00', '09:00')]);
});
