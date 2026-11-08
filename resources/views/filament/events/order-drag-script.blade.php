<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('serviceOrderDrag', (config = {}) => ({
            observer: null,
            frame: null,
            configuredSortable: null,
            listenedBody: null,
            dragging: false,
            awaitingServer: false,
            awaitTimer: null,
            reconcile() {
                cancelAnimationFrame(this.frame);
                this.frame = requestAnimationFrame(() => {
                    const table = this.$el.closest('.fi-ta');
                    if (!table?.isConnected) return;

                    if (config.draggable) {
                        table.querySelectorAll('.wp-order-drag-handle[data-order-record-key]').forEach(handle => {
                            const row = handle.closest('tr');
                            if (row && row.getAttribute('x-sortable-item') !== handle.dataset.orderRecordKey) {
                                row.setAttribute('x-sortable-item', handle.dataset.orderRecordKey);
                            }
                        });
                    }

                    const body = table.querySelector('tbody.fi-ta-records');
                    this.placeStartMarker(body);
                    this.listenForDrags(body);

                    const sortable = body?.sortable;
                    if (!sortable || sortable === this.configuredSortable) return;
                    this.configuredSortable = sortable;
                    sortable.option('animation', matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220);
                    sortable.option('easing', 'cubic-bezier(0.2, 0, 0, 1)');
                    sortable.option('ghostClass', 'wp-order-drag-ghost');
                    sortable.option('chosenClass', 'wp-order-drag-chosen');
                    sortable.option('delay', 0);
                });
            },
            buildStartMarker() {
                const row = document.createElement('tr');
                row.className = 'wp-order-start-marker';
                if (config.draggable) row.setAttribute('x-sortable-item', config.markerKey);

                const cell = document.createElement('td');
                const band = document.createElement('div');
                band.className = 'wp-order-start-band';

                if (config.draggable) {
                    const handle = document.createElement('button');
                    handle.type = 'button';
                    handle.className = 'wp-order-start-handle';
                    handle.setAttribute('x-sortable-handle', '');
                    handle.setAttribute('aria-label', config.handleLabel);
                    handle.title = config.handleLabel;
                    handle.textContent = '⠿';
                    band.append(handle);
                }

                const before = document.createElement('span');
                before.className = 'wp-order-start-side';
                before.textContent = '▲ ' + config.beforeLabel;

                const time = document.createElement('strong');
                time.className = 'wp-order-start-time';
                time.textContent = config.startLabel;

                const after = document.createElement('span');
                after.className = 'wp-order-start-side';
                after.textContent = config.afterLabel + ' ▼';

                band.append(before, time, after);

                if (config.draggable) {
                    const hint = document.createElement('span');
                    hint.className = 'wp-order-start-hint';
                    hint.textContent = config.dragLabel;
                    band.append(hint);
                }

                cell.append(band);
                row.append(cell);

                return row;
            },
            placeStartMarker(body) {
                if (!body) return;

                const rows = [...body.querySelectorAll(':scope > tr.fi-ta-row')];
                let marker = body.querySelector(':scope > tr.wp-order-start-marker');

                if (!rows.length) {
                    marker?.remove();
                    return;
                }

                if (!marker) {
                    marker = this.buildStartMarker();
                    this.awaitingServer = false;
                }

                const cell = marker.firstElementChild;
                const columns = String(rows[0].children.length);
                if (cell.getAttribute('colspan') !== columns) cell.setAttribute('colspan', columns);

                const items = [...body.querySelectorAll(':scope > tr.fi-ta-row, :scope > tr.wp-order-start-marker')];
                const at = items.indexOf(marker);
                const isBefore = row => row.classList.contains('wp-order-before');
                const inPlace = at !== -1
                    && items.slice(0, at).every(isBefore)
                    && items.slice(at + 1).every(row => !isBefore(row));

                if (inPlace) {
                    this.awaitingServer = false;
                    return;
                }

                // Right after a drop the rows still carry their old side until the server answers.
                if (this.dragging || (this.awaitingServer && at !== -1)) return;

                body.insertBefore(marker, rows.find(row => !isBefore(row)) ?? null);
            },
            listenForDrags(body) {
                if (!config.draggable || !body || body === this.listenedBody) return;
                this.listenedBody = body;
                body.addEventListener('start', () => {
                    this.dragging = true;
                });
                body.addEventListener('end', () => {
                    this.dragging = false;
                    this.awaitingServer = true;
                    clearTimeout(this.awaitTimer);
                    this.awaitTimer = setTimeout(() => {
                        this.awaitingServer = false;
                        this.reconcile();
                    }, 5000);
                });
            },
            init() {
                this.observer = new MutationObserver(() => this.reconcile());
                this.observer.observe(this.$el.closest('.fi-ta'), {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['x-sortable-item', 'data-order-record-key', 'class'],
                });
                this.$nextTick(() => this.reconcile());
            },
            destroy() {
                this.observer?.disconnect();
                cancelAnimationFrame(this.frame);
                clearTimeout(this.awaitTimer);
            },
        }));
    });
</script>
