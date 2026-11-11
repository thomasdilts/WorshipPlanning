<script>
    document.addEventListener('mousedown', (event) => {
        const scrollContainer = event.target;

        if (event.button !== 0 || !(scrollContainer instanceof HTMLElement)
            || !scrollContainer.closest('.fi-dropdown-panel, .fi-fo-date-time-picker-panel, [role="listbox"], [role="menu"]')) {
            return;
        }

        const style = getComputedStyle(scrollContainer);
        const scrollsVertically = /auto|scroll/.test(style.overflowY)
            && scrollContainer.scrollHeight > scrollContainer.clientHeight;
        const scrollsHorizontally = /auto|scroll/.test(style.overflowX)
            && scrollContainer.scrollWidth > scrollContainer.clientWidth;

        if (!scrollsVertically && !scrollsHorizontally) {
            return;
        }

        const rect = scrollContainer.getBoundingClientRect();
        const contentLeft = rect.left + scrollContainer.clientLeft;
        const contentTop = rect.top + scrollContainer.clientTop;
        const isScrollbar = (scrollsVertically
            && (event.clientX < contentLeft || event.clientX >= contentLeft + scrollContainer.clientWidth))
            || (scrollsHorizontally
                && (event.clientY < contentTop || event.clientY >= contentTop + scrollContainer.clientHeight));

        if (!isScrollbar) {
            return;
        }

        // Option-list handlers cancel mousedown; native scrollbar dragging must keep its default action.
        event.stopPropagation();

        if (!scrollContainer.hasAttribute('tabindex')) {
            scrollContainer.tabIndex = -1;
        }

        scrollContainer.focus({ preventScroll: true });
    }, true);
</script>
