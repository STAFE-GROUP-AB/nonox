import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isInstalled, pinchScale } from '../../resources/js/installed-zoom.js';

test('pinches a picture between its own size and five times larger', () => {
    assert.equal(pinchScale(1, 100, 200), 2);
    assert.equal(pinchScale(2, 100, 50), 1);
    assert.equal(pinchScale(1, 100, 40), 1);
    assert.equal(pinchScale(3, 100, 1000), 5);
    assert.equal(pinchScale(2, 0, 100), 2);
});

test('is only installed when the page runs on its own', () => {
    const win = (standalone, iosStandalone = false) => ({
        matchMedia: (query) => ({ matches: standalone && query.includes('standalone') }),
        navigator: { standalone: iosStandalone },
    });

    assert.equal(isInstalled(win(false)), false);
    assert.equal(isInstalled(win(true)), true);
    assert.equal(isInstalled(win(false, true)), true);
});
