/**
 * The schedule grid (Planering → Schema). Pointer events, so the same code
 * drags with a mouse, a pen or a finger (a finger holds for a moment first,
 * so the page still scrolls). A drop moves the shift in the page at once and
 * sends one Livewire call; the page then shows what the server saved, which
 * also puts a refused move back.
 *
 * Week view: drag a shift to another person or day (Alt copies it). Day
 * view: drag the shift along the timeline or to another person, drag its
 * edges to change the times, or draw a new shift on an empty track.
 * Keyboard: arrows move between cells, Enter opens, Delete removes, c copies
 * and v pastes, ? shows the keys.
 */

import { DAY_MINUTES, at, dragInterval, drawnInterval, moveToDay, neighbour } from './planning-math.js';

const HOLD_MS = 250;
const MOVE_PX = 4;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('planningGrid', (config) => ({
        step: config.step,
        view: config.view,
        canManage: config.canManage,
        rows: config.rows,
        clipboard: null,
        help: false,
        press: null,
        drag: null,

        init() {
            this.onMove = (event) => this.pointerMove(event);
            this.onUp = (event) => this.pointerUp(event);
            this.onTouchMove = (event) => {
                if (this.drag) {
                    event.preventDefault();
                }
            };
        },

        // ── Pointer ──────────────────────────────────────────────────────

        pointerDown(event) {
            if (!this.canManage || event.button > 0) {
                return;
            }

            const chip = event.target.closest('[data-shift]');
            const handle = event.target.closest('[data-handle]');
            const track = event.target.closest('[data-track]');

            if (!chip && !(track && this.view === 'dag')) {
                return;
            }

            this.press = {
                chip,
                track: chip ? chip.closest('[data-track]') : track,
                mode: chip ? (handle ? handle.dataset.handle : 'move') : 'create',
                x: event.clientX,
                y: event.clientY,
                touch: event.pointerType === 'touch',
                armed: event.pointerType !== 'touch',
                timer: null,
            };

            if (this.press.touch) {
                this.press.timer = setTimeout(() => {
                    if (this.press) {
                        this.press.armed = true;
                        this.start(event);
                    }
                }, HOLD_MS);
            }

            window.addEventListener('pointermove', this.onMove);
            window.addEventListener('pointerup', this.onUp, { once: true });
            window.addEventListener('pointercancel', this.onUp, { once: true });
            document.addEventListener('touchmove', this.onTouchMove, { passive: false });
        },

        pointerMove(event) {
            if (!this.press) {
                return;
            }

            const moved = Math.abs(event.clientX - this.press.x) + Math.abs(event.clientY - this.press.y);

            if (!this.drag) {
                if (this.press.touch && !this.press.armed && moved > MOVE_PX) {
                    // A finger that moves before the hold scrolls the page.
                    this.cancelPress();

                    return;
                }

                if (this.press.armed && moved > MOVE_PX) {
                    this.start(event);
                }

                return;
            }

            this.view === 'dag' ? this.dragTimeline(event) : this.dragWeek(event);
        },

        pointerUp(event) {
            const drag = this.drag;
            const press = this.press;
            this.cancelPress();

            if (!drag) {
                // A click or a tap: open the shift, or a new one on that row and day.
                if (press?.chip) {
                    this.$wire.edit(press.chip.dataset.shift);
                } else if (press?.track) {
                    this.$wire.create(this.rowEmployee(press.track, null), press.track.dataset.date);
                }

                return;
            }

            this.view === 'dag' ? this.dropTimeline(drag, event) : this.dropWeek(drag, event);
        },

        cancelPress() {
            clearTimeout(this.press?.timer);
            this.press = null;
            window.removeEventListener('pointermove', this.onMove);
            document.removeEventListener('touchmove', this.onTouchMove);
        },

        start(event) {
            const press = this.press;
            const chip = press.chip;

            if (this.view === 'dag') {
                const rect = press.track.getBoundingClientRect();
                const minuteAtPointer = ((event.clientX - rect.left) / rect.width) * DAY_MINUTES;
                this.drag = {
                    mode: press.mode,
                    chip,
                    track: press.track,
                    x: press.x,
                    startMin: chip ? Number(chip.dataset.startMin) : minuteAtPointer,
                    endMin: chip ? Number(chip.dataset.endMin) : minuteAtPointer,
                    from: minuteAtPointer,
                    ghost: chip ?? this.ghostOn(press.track),
                };
            } else {
                const ghost = chip.cloneNode(true);
                ghost.classList.add('pointer-events-none', 'fixed', 'z-50', 'shadow-lg', 'opacity-90');
                ghost.style.width = `${chip.offsetWidth}px`;
                document.body.appendChild(ghost);
                chip.classList.add('opacity-40');
                this.drag = { mode: 'move', chip, ghost, target: null };
                this.dragWeek(event);
            }
        },

        // ── Week view ────────────────────────────────────────────────────

        dragWeek(event) {
            const drag = this.drag;
            drag.ghost.style.left = `${event.clientX + 8}px`;
            drag.ghost.style.top = `${event.clientY + 8}px`;
            const cell = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-cell]');

            if (drag.target !== cell) {
                drag.target?.classList.remove('ring-2', 'ring-accent-500');
                cell?.classList.add('ring-2', 'ring-accent-500');
                drag.target = cell ?? null;
            }
        },

        dropWeek(drag, event) {
            drag.ghost.remove();
            drag.chip.classList.remove('opacity-40');
            drag.target?.classList.remove('ring-2', 'ring-accent-500');
            const cell = drag.target;
            const chip = drag.chip;

            if (!cell || (cell.dataset.row === chip.dataset.row && cell.dataset.date === chip.dataset.date)) {
                return;
            }

            const times = moveToDay({ start: chip.dataset.start, end: chip.dataset.end, endOffset: Number(chip.dataset.endOffset) }, cell.dataset.date);

            if (event.altKey) {
                this.$wire.copyShift(chip.dataset.shift, this.rowEmployee(cell, chip), cell.dataset.date);

                return;
            }

            // Shown in its new place at once; the server's answer follows.
            cell.querySelector('[data-chips]')?.appendChild(chip);
            this.send(chip.dataset.shift, cell, chip, times.startsAt, times.endsAt);
        },

        // ── Day view ─────────────────────────────────────────────────────

        dragTimeline(event) {
            const drag = this.drag;
            const rect = drag.track.getBoundingClientRect();
            const delta = ((event.clientX - drag.x) / rect.width) * DAY_MINUTES;
            let interval;

            if (drag.mode === 'create') {
                interval = drawnInterval(drag.from, drag.from + delta, this.step);
            } else {
                interval = dragInterval(drag.mode, drag.startMin, drag.endMin, delta, this.step);
                const track = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-track]');

                if (drag.mode === 'move' && track && track !== drag.ghost.parentElement) {
                    track.appendChild(drag.ghost);
                }
            }

            drag.interval = interval;
            this.place(drag.ghost, interval.start, interval.end);
        },

        dropTimeline(drag) {
            const interval = drag.interval;

            if (!interval) {
                return;
            }

            const date = drag.track.dataset.date;
            const startsAt = at(date, interval.start);
            const endsAt = at(date, interval.end);

            if (drag.mode === 'create') {
                drag.ghost.remove();
                this.$wire.createShift(this.rowEmployee(drag.track, null), startsAt, endsAt);

                return;
            }

            this.send(drag.chip.dataset.shift, drag.ghost.parentElement, drag.chip, startsAt, endsAt);
        },

        ghostOn(track) {
            const ghost = document.createElement('div');
            ghost.className = 'pointer-events-none absolute inset-y-1 rounded-lg bg-accent-200/70 ring-2 ring-accent-500';
            track.appendChild(ghost);

            return ghost;
        },

        place(element, start, end) {
            element.style.left = `${(start / DAY_MINUTES) * 100}%`;
            element.style.width = `${(Math.min(end, DAY_MINUTES) - start) / DAY_MINUTES * 100}%`;
            const label = element.querySelector('[data-time]');

            if (label) {
                label.textContent = `${at('2000-01-01', start).slice(11)}–${at('2000-01-01', end).slice(11)}`;
            }
        },

        // ── Saving ───────────────────────────────────────────────────────

        rowEmployee(row, chip) {
            if (this.rows === 'avdelningar') {
                return chip?.dataset.employee ? Number(chip.dataset.employee) : null;
            }

            return row.dataset.row === 'open' ? null : Number(row.dataset.row);
        },

        send(id, row, chip, startsAt, endsAt) {
            if (this.rows === 'avdelningar') {
                this.$wire.moveShiftToArea(id, row.dataset.row === 'none' ? null : Number(row.dataset.row), startsAt, endsAt);

                return;
            }

            this.$wire.moveShift(id, this.rowEmployee(row, chip), startsAt, endsAt);
        },

        // ── Keyboard ─────────────────────────────────────────────────────

        keydown(event) {
            if (event.target.closest('input, select, textarea, [role="dialog"]')) {
                return;
            }

            const chip = event.target.closest('[data-shift]');
            const cell = event.target.closest('[data-cell], [data-track]');

            if (event.key === '?') {
                this.help = !this.help;
                event.preventDefault();

                return;
            }

            if (event.key.startsWith('Arrow') && cell) {
                this.focusNeighbour(cell, event.key);
                event.preventDefault();

                return;
            }

            if (!this.canManage) {
                return;
            }

            if (event.key === 'Enter' && chip) {
                this.$wire.edit(chip.dataset.shift);
                event.preventDefault();
            } else if (event.key === 'Enter' && cell) {
                this.$wire.create(this.rowEmployee(cell, null), cell.dataset.date);
                event.preventDefault();
            } else if ((event.key === 'Delete' || event.key === 'Backspace') && chip) {
                const question = chip.dataset.published === '1' ? chip.dataset.cancelQuestion : null;

                if (question === null || window.confirm(question)) {
                    this.$wire.deleteShift(chip.dataset.shift);
                }

                event.preventDefault();
            } else if (event.key === 'c' && chip && !event.metaKey && !event.ctrlKey) {
                this.clipboard = chip.dataset.shift;
                this.$dispatch('toast', { message: config.copiedText, type: 'success' });
            } else if (event.key === 'v' && cell && this.clipboard && !event.metaKey && !event.ctrlKey) {
                this.$wire.copyShift(this.clipboard, this.rowEmployee(cell, null), cell.dataset.date);
            }
        },

        focusNeighbour(cell, key) {
            const cells = [...this.$root.querySelectorAll(this.view === 'dag' ? '[data-track]' : '[data-cell]')];
            const columns = this.view === 'dag' ? 1 : 7;
            const index = cells.indexOf(cell);
            const rows = Math.ceil(cells.length / columns);
            const next = neighbour(Math.floor(index / columns), index % columns, key, rows, columns);
            cells[next.row * columns + next.column]?.focus();
        },
    }));
});
