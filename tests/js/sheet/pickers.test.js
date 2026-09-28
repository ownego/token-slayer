// @vitest-environment jsdom
import { beforeEach, expect, test, vi } from 'vitest';
import { modelPicker, periodTabs } from '@battlefield/sheet/pickers.js';

beforeEach(() => localStorage.clear());

const key = (k, target) => ({ key: k, target, preventDefault: vi.fn(), stopPropagation: vi.fn() });

function mountTabs(current = 'today') {
  document.body.innerHTML = `<div id="p"><div class="periods">
    ${['hour', 'today', 'week', 'month', 'all'].map(p => `<button class="period" data-p="${p}"></button>`).join('')}
    <div class="period" id="dd" data-p="year" tabindex="0"></div></div></div>`;
  const tabs = periodTabs(current);
  tabs.$el = document.getElementById('p');
  tabs.$wire = { setPeriod: vi.fn() };

  return tabs;
}

test('arrow keys walk the period tabs, wrapping, and pick the one landed on', () => {
  const tabs = mountTabs();
  const today = document.querySelector('[data-p="today"]');

  tabs.nav(key('ArrowRight', today));
  expect(document.activeElement.dataset.p).toBe('week');
  expect(tabs.$wire.setPeriod).toHaveBeenLastCalledWith('week');

  tabs.nav(key('ArrowLeft', document.querySelector('[data-p="hour"]')));
  expect(document.activeElement.id).toBe('dd');
  expect(tabs.more).toBe(false); // landing on "More" doesn't pop its menu
});

test('Enter or Space on "More" opens its menu; Escape closes it and returns focus', () => {
  const tabs = mountTabs();
  const dd = document.getElementById('dd');

  tabs.nav(key('Enter', dd));
  expect(tabs.more).toBe(true);
  tabs.escape();
  expect(tabs.more).toBe(false);
  expect(document.activeElement).toBe(dd);
});

test('a picked period is remembered for the next visit, and restored on open', () => {
  const first = mountTabs();
  first.pick('month');
  expect(localStorage.getItem('ts:profile-period')).toBe('month');

  const next = mountTabs('today');
  next.init();
  expect(next.$wire.setPeriod).toHaveBeenCalledWith('month');
});

test('the model picker opens with ↓, moves its highlight, picks with Enter and closes with Escape', () => {
  const picker = modelPicker(['today', 'week', 'month', 'all'], 'today');
  picker.$wire = { setModelPeriod: vi.fn() };

  picker.keys(key('ArrowDown'));
  expect(picker.open).toBe(true);
  expect(picker.active).toBe(0);
  picker.keys(key('ArrowDown'));
  picker.keys(key('ArrowDown'));
  picker.keys(key('Enter'));
  expect(picker.$wire.setModelPeriod).toHaveBeenCalledWith('month');
  expect(picker.open).toBe(false);

  picker.keys(key('ArrowUp'));
  picker.keys(key('Escape'));
  expect(picker.open).toBe(false);
});

test('arrow keys typed inside the custom-range date inputs stay in the input', () => {
  const tabs = mountTabs();
  const input = document.createElement('input');
  document.getElementById('dd').appendChild(input);

  tabs.nav(key('ArrowRight', input));

  expect(tabs.$wire.setPeriod).not.toHaveBeenCalled();
});
