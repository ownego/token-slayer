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

test('the picked period lights up at once, before the server answers with its numbers', () => {
  const tabs = mountTabs('week');
  document.querySelector('[data-p="week"]').setAttribute('aria-selected', 'true');

  // as in Alpine: inside a @click handler $el is the clicked button, $root the component
  tabs.$root = tabs.$el;
  tabs.$el = document.querySelector('[data-p="today"]');
  tabs.pick('today');

  expect(document.querySelector('[data-p="today"]').getAttribute('aria-selected')).toBe('true');
  expect(document.querySelector('[data-p="week"]').getAttribute('aria-selected')).toBe('false');
  expect(tabs.$wire.setPeriod).toHaveBeenCalledWith('today');
});

test('choosing a By-model period relabels the picker at once, before the server answers', () => {
  document.body.innerHTML = `<span id="m"><button><span id="model-meta">Today</span></button><span class="mpick-menu">
    <span role="option" data-p="today" aria-selected="true">Today</span><span role="option" data-p="week" aria-selected="false">This week</span></span></span>`;
  const picker = modelPicker(['today', 'week'], 'today');
  // as in Alpine: inside a @click handler $el is the clicked option, $root the component
  picker.$root = document.getElementById('m');
  picker.$el = document.querySelector('[data-p="week"]');
  picker.$wire = { setModelPeriod: vi.fn() };

  picker.choose('week');

  expect(document.getElementById('model-meta').textContent).toBe('This week');
  expect(document.querySelector('[data-p="week"]').getAttribute('aria-selected')).toBe('true');
  expect(document.querySelector('[data-p="today"]').getAttribute('aria-selected')).toBe('false');
  expect(picker.$wire.setModelPeriod).toHaveBeenCalledWith('week');
});
