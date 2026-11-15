<script>
    document.addEventListener('alpine:init', () => {
        /**
         * Profile picture field: pick a picture, move and zoom it inside a square, and upload only the result.
         * The crop is kept in source-image pixels (x, y, side), like the Pingstpodcast cover cropper.
         */
        Alpine.data('avatarEditor', (config = {}) => {
            const clamp = (value, low, high) => Math.min(high, Math.max(low, value));
            const crop = { width: 0, height: 0, x: 0, y: 0, side: 0, minSide: 1, maxSide: 1 };
            const pointers = new Map();
            let pinchDistance = 0;
            let pendingUrl = null;
            let resizeObserver = null;

            return {
                previewUrl: config.url ?? null,
                sourceUrl: config.url ?? null,
                currentKey: config.key ?? null,
                disabled: config.disabled ?? false,
                open: false,
                busy: false,
                changed: false,
                dragging: false,
                dropping: false,
                error: null,

                init() {
                    if (window.ResizeObserver) {
                        resizeObserver = new ResizeObserver(() => {
                            if (this.open && crop.width) {
                                this.render();
                            }
                        });
                        resizeObserver.observe(this.$refs.stage);
                    }
                },

                destroy() {
                    resizeObserver?.disconnect();
                },

                choose() {
                    if (this.disabled || this.busy) {
                        return;
                    }

                    this.$refs.input.click();
                },

                load(file) {
                    if (this.disabled || ! file) {
                        return;
                    }

                    this.error = null;

                    if (! file.type.startsWith('image/')) {
                        this.error = config.messages.unreadable;

                        return;
                    }

                    this.discardPending();
                    pendingUrl = URL.createObjectURL(file);
                    this.openEditor(pendingUrl);
                },

                editCurrent() {
                    if (this.sourceUrl) {
                        this.error = null;
                        this.openEditor(this.sourceUrl);
                    }
                },

                openEditor(url) {
                    const image = this.$refs.image;

                    image.onload = () => {
                        crop.width = image.naturalWidth;
                        crop.height = image.naturalHeight;

                        if (! crop.width || ! crop.height) {
                            this.unreadable();

                            return;
                        }

                        crop.maxSide = Math.min(crop.width, crop.height);
                        // Zoom in up to 10x, but not past a quarter of the saved size.
                        crop.minSide = Math.min(crop.maxSide, Math.max(crop.maxSide / 10, config.edge / 4, 1));
                        this.open = true;
                        this.$nextTick(() => requestAnimationFrame(() => {
                            this.reset();
                            this.$refs.stage.focus({ preventScroll: true });
                        }));
                    };
                    image.onerror = () => this.unreadable();

                    if (! url.startsWith('blob:')) {
                        image.crossOrigin = 'anonymous';
                    } else {
                        image.removeAttribute('crossorigin');
                    }

                    image.src = url;
                },

                unreadable() {
                    this.open = false;
                    this.discardPending();
                    this.error = config.messages.unreadable;
                },

                render() {
                    crop.side = clamp(crop.side, crop.minSide, crop.maxSide);
                    crop.x = clamp(crop.x, 0, crop.width - crop.side);
                    crop.y = clamp(crop.y, 0, crop.height - crop.side);

                    const stageSize = this.$refs.stage.clientWidth || 1;
                    const scale = stageSize / crop.side;
                    const image = this.$refs.image;
                    image.style.width = (crop.width * scale) + 'px';
                    image.style.height = (crop.height * scale) + 'px';
                    image.style.transform = `translate(${-crop.x * scale}px, ${-crop.y * scale}px)`;

                    this.$refs.zoom.value = crop.maxSide === crop.minSide
                        ? 0
                        : Math.round(1000 * Math.log(crop.side / crop.maxSide) / Math.log(crop.minSide / crop.maxSide));

                    this.draw(this.$refs.preview);
                },

                draw(canvas) {
                    const context = canvas.getContext('2d');
                    context.imageSmoothingQuality = 'high';
                    context.fillStyle = '#ffffff';
                    context.fillRect(0, 0, canvas.width, canvas.height);
                    context.drawImage(this.$refs.image, crop.x, crop.y, crop.side, crop.side, 0, 0, canvas.width, canvas.height);
                },

                reset() {
                    crop.side = crop.maxSide;
                    crop.x = (crop.width - crop.side) / 2;
                    crop.y = (crop.height - crop.side) / 2;
                    this.render();
                },

                /** Zoom so the picture point under (pointX, pointY) on the stage stays where it is. */
                zoomTo(newSide, pointX, pointY) {
                    const stageSize = this.$refs.stage.clientWidth || 1;
                    pointX ??= stageSize / 2;
                    pointY ??= stageSize / 2;
                    newSide = clamp(newSide, crop.minSide, crop.maxSide);
                    const sourceX = crop.x + pointX * crop.side / stageSize;
                    const sourceY = crop.y + pointY * crop.side / stageSize;
                    crop.side = newSide;
                    crop.x = sourceX - pointX * crop.side / stageSize;
                    crop.y = sourceY - pointY * crop.side / stageSize;
                    this.render();
                },

                zoomBy(factor) {
                    this.zoomTo(crop.side * factor);
                },

                /** The slider is logarithmic so zooming feels even across its whole length. */
                zoomSlider(value) {
                    this.zoomTo(crop.maxSide * Math.pow(crop.minSide / crop.maxSide, value / 1000));
                },

                wheel(event) {
                    const rect = this.$refs.stage.getBoundingClientRect();
                    this.zoomTo(crop.side * Math.exp(event.deltaY * 0.0015), event.clientX - rect.left, event.clientY - rect.top);
                },

                pointerDown(event) {
                    this.$refs.stage.setPointerCapture(event.pointerId);
                    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

                    if (pointers.size === 2) {
                        const [first, second] = [...pointers.values()];
                        pinchDistance = Math.hypot(first.x - second.x, first.y - second.y);
                    }

                    this.dragging = true;
                },

                pointerMove(event) {
                    const pointer = pointers.get(event.pointerId);

                    if (! pointer) {
                        return;
                    }

                    const stageSize = this.$refs.stage.clientWidth || 1;

                    if (pointers.size === 1) {
                        crop.x -= (event.clientX - pointer.x) * crop.side / stageSize;
                        crop.y -= (event.clientY - pointer.y) * crop.side / stageSize;
                        pointer.x = event.clientX;
                        pointer.y = event.clientY;
                        this.render();

                        return;
                    }

                    pointer.x = event.clientX;
                    pointer.y = event.clientY;
                    const [first, second] = [...pointers.values()];
                    const distance = Math.hypot(first.x - second.x, first.y - second.y);

                    if (pinchDistance > 0 && distance > 0) {
                        const rect = this.$refs.stage.getBoundingClientRect();
                        this.zoomTo(crop.side * pinchDistance / distance, (first.x + second.x) / 2 - rect.left, (first.y + second.y) / 2 - rect.top);
                    }

                    pinchDistance = distance;
                },

                pointerUp(event) {
                    pointers.delete(event.pointerId);
                    pinchDistance = 0;
                    this.dragging = pointers.size > 0;
                },

                key(event) {
                    const step = crop.side * (event.shiftKey ? 0.1 : 0.02);
                    const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };

                    if (moves[event.key]) {
                        crop.x += moves[event.key][0];
                        crop.y += moves[event.key][1];
                        this.render();
                    } else if (['+', '='].includes(event.key)) {
                        this.zoomBy(1 / 1.1);
                    } else if (['-', '_'].includes(event.key)) {
                        this.zoomBy(1.1);
                    } else {
                        return;
                    }

                    event.preventDefault();
                },

                cancel() {
                    this.open = false;
                    this.discardPending();
                },

                discardPending() {
                    if (pendingUrl && pendingUrl !== this.sourceUrl) {
                        URL.revokeObjectURL(pendingUrl);
                    }

                    pendingUrl = null;
                },

                apply() {
                    const canvas = document.createElement('canvas');
                    canvas.width = config.edge;
                    canvas.height = config.edge;
                    this.draw(canvas);

                    canvas.toBlob((blob) => {
                        if (! blob) {
                            this.error = config.messages.uploadFailed;

                            return;
                        }

                        if (pendingUrl) {
                            if (this.sourceUrl?.startsWith('blob:')) {
                                URL.revokeObjectURL(this.sourceUrl);
                            }

                            // Keep the full picture so "Edit picture" starts from it, not the small result.
                            this.sourceUrl = pendingUrl;
                            pendingUrl = null;
                        }

                        if (this.previewUrl?.startsWith('blob:') && this.previewUrl !== this.sourceUrl) {
                            URL.revokeObjectURL(this.previewUrl);
                        }

                        this.previewUrl = URL.createObjectURL(blob);
                        this.open = false;
                        this.upload(new File([blob], 'profile-picture.jpg', { type: 'image/jpeg' }));
                    }, 'image/jpeg', 0.9);
                },

                async upload(file) {
                    this.busy = true;
                    this.error = null;

                    try {
                        await this.removeStored();
                    } catch (exception) {
                        this.busy = false;
                        this.error = config.messages.uploadFailed;

                        return;
                    }

                    const fileKey = window.crypto?.randomUUID?.() ?? (Date.now().toString(36) + Math.random().toString(36).slice(2));

                    this.$wire.upload(
                        `${config.statePath}.${fileKey}`,
                        file,
                        () => {
                            this.currentKey = fileKey;
                            this.busy = false;
                            this.changed = true;
                        },
                        () => {
                            this.busy = false;
                            this.error = config.messages.uploadFailed;
                        },
                    );
                },

                async remove() {
                    if (this.busy || ! window.confirm(config.messages.confirmDelete)) {
                        return;
                    }

                    this.busy = true;

                    try {
                        await this.removeStored();
                    } catch (exception) {
                        this.error = config.messages.uploadFailed;

                        return;
                    } finally {
                        this.busy = false;
                    }

                    [this.previewUrl, this.sourceUrl].forEach((url) => url?.startsWith('blob:') && URL.revokeObjectURL(url));
                    this.previewUrl = null;
                    this.sourceUrl = null;
                    this.changed = true;
                    this.error = null;
                },

                async removeStored() {
                    if (! this.currentKey) {
                        return;
                    }

                    await this.$wire.callSchemaComponentMethod(config.componentKey, 'removeUploadedFile', { fileKey: this.currentKey });
                    this.currentKey = null;
                },
            };
        });
    });
</script>
