<script>
    document.addEventListener('mousedown', (event) => {
        const panel = event.target;

        if (event.button !== 0 || !(panel instanceof HTMLElement)
            || !panel.matches('.fi-select-input-ctn > .fi-dropdown-panel')) {
            return;
        }

        // Keep scrollbar interaction inside the select's focus boundary without cancelling native dragging.
        panel.tabIndex = -1;
        panel.focus({ preventScroll: true });
    }, true);
</script>
