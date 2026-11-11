import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../resources/views/filament/select-scrollbar-script.blade.php', import.meta.url), 'utf8')
    .replace(/<\/?script>/g, '');

class Element {
    constructor({ parent = null, className = '', role = null, rtl = false, horizontal = false, tabIndex = null } = {}) {
        Object.assign(this, {
            parent, className, role, tabIndex,
            clientLeft: rtl ? 15 : 0,
            clientTop: 0,
            clientWidth: horizontal ? 200 : 185,
            clientHeight: horizontal ? 185 : 200,
            scrollWidth: horizontal ? 400 : 185,
            scrollHeight: horizontal ? 185 : 400,
            style: { overflowY: horizontal ? 'hidden' : 'auto', overflowX: horizontal ? 'auto' : 'hidden' },
        });
    }

    closest(selector) {
        const matches = selector.split(',').some(value => {
            value = value.trim();
            return value.startsWith('.') ? this.className.split(' ').includes(value.slice(1))
                : value === `[role="${this.role}"]`;
        });
        return matches ? this : this.parent?.closest(selector);
    }

    getBoundingClientRect() {
        return { left: 100, top: 100 };
    }

    hasAttribute(name) {
        return name === 'tabindex' && this.tabIndex !== null;
    }

    focus(options) {
        this.focusOptions = options;
    }
}

function press(target, { clientX = 290, clientY = 150, button = 0 } = {}) {
    let handler;
    vm.runInNewContext(script, {
        HTMLElement: Element,
        getComputedStyle: element => element.style,
        document: {
            addEventListener(name, listener, capture) {
                assert.equal(name, 'mousedown');
                assert.equal(capture, true);
                handler = listener;
            },
        },
    });
    const event = {
        target, clientX, clientY, button, propagationStopped: false,
        stopPropagation() { this.propagationStopped = true; },
        preventDefault() { assert.fail('Scrollbar dragging must retain its native default action'); },
    };
    handler(event);
    return event;
}

for (const panel of [
    { className: 'fi-dropdown-panel' },
    { className: 'fi-fo-date-time-picker-panel' },
    { className: 'wp-start-time-options', role: 'listbox' },
    { role: 'menu' },
]) {
    test(`scrollbar keeps focus inside ${panel.className || panel.role}`, () => {
        const element = new Element(panel);
        const event = press(element);
        assert.equal(element.tabIndex, -1);
        assert.equal(element.focusOptions.preventScroll, true);
        assert.equal(event.propagationStopped, true);
    });
}

test('nested option-list scrollbars are protected from cancelling mousedown handlers', () => {
    const element = new Element({ parent: new Element({ className: 'fi-dropdown-panel' }) });
    assert.equal(press(element).propagationStopped, true);
    assert.equal(element.focusOptions.preventScroll, true);
});

test('RTL left-side scrollbars retain focus', () => {
    const element = new Element({ role: 'listbox', rtl: true });
    assert.equal(press(element, { clientX: 105 }).propagationStopped, true);
    assert.equal(element.focusOptions.preventScroll, true);
});

test('horizontal scrollbar dragging preserves existing tabindex', () => {
    const element = new Element({ role: 'menu', horizontal: true, tabIndex: 0 });
    assert.equal(press(element, { clientX: 150, clientY: 290 }).propagationStopped, true);
    assert.equal(element.tabIndex, 0);
});

test('ordinary option clicks, outside clicks, non-scrollable menus and secondary buttons are untouched', () => {
    const panel = new Element({ role: 'listbox' });
    assert.equal(press(panel, { clientX: 150 }).propagationStopped, false);
    assert.equal(press(new Element()).propagationStopped, false);
    assert.equal(press(panel, { button: 2 }).propagationStopped, false);
    panel.scrollHeight = panel.clientHeight;
    assert.equal(press(panel).propagationStopped, false);
    assert.equal(panel.focusOptions, undefined);
});
