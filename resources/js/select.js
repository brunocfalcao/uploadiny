// Quanamo's select presentation, adapted to the workspace's native-select contract.
export function enhanceProjectSelect(select) {
    if (!select) return;
    const trigger = document.createElement('button');
    const menu = document.createElement('div');
    trigger.type = 'button';
    trigger.id = `${select.id}-trigger`;
    trigger.className = 'qr-field qr-field-default qr-select qr-select-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-controls', `${select.id}-options`);
    menu.id = `${select.id}-options`;
    menu.className = 'qr-select-listbox';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    const label = document.querySelector(`label[for="${select.id}"]`);
    label.htmlFor = trigger.id;
    label.id = `${select.id}-label`;
    trigger.setAttribute('aria-labelledby', label.id);
    menu.setAttribute('aria-labelledby', label.id);
    select.className = 'qr-select-native';
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.after(trigger);
    document.body.append(menu);
    let activeIndex = 0;
    let search = '';
    let searchTimer;
    const options = Array.from(select.options);
    const entries = options.map((option, index) => {
        const entry = document.createElement('div');
        entry.id = `${menu.id}-${index}`;
        entry.className = 'qr-select-option';
        entry.setAttribute('role', 'option');
        entry.textContent = option.label;
        entry.addEventListener('mousedown', event => event.preventDefault());
        entry.addEventListener('mouseenter', () => { activeIndex = index; highlight(); });
        entry.addEventListener('click', () => choose(index));
        menu.append(entry);
        return entry;
    });
    function sync() {
        trigger.textContent = select.selectedOptions[0]?.label ?? '';
        trigger.disabled = select.disabled || !options.length;
        entries.forEach((entry, index) => entry.setAttribute('aria-selected', String(index === select.selectedIndex)));
    }
    function highlight() {
        entries.forEach((entry, index) => entry.classList.toggle('qr-select-option-active', index === activeIndex));
        trigger.setAttribute('aria-activedescendant', entries[activeIndex]?.id ?? '');
        entries[activeIndex]?.scrollIntoView({ block: 'nearest' });
    }
    function position() {
        if (menu.hidden) return;
        const rect = trigger.getBoundingClientRect();
        const below = window.innerHeight - rect.bottom - 12;
        const above = rect.top - 12;
        const height = Math.min(280, Math.max(below, above));
        const onTop = below < 180 && above > below;
        Object.assign(menu.style, { left: `${rect.left}px`, width: `${rect.width}px`, maxHeight: `${height}px`, top: onTop ? 'auto' : `${rect.bottom + 6}px`, bottom: onTop ? `${window.innerHeight - rect.top + 6}px` : 'auto' });
    }
    function close() {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.removeAttribute('aria-activedescendant');
        search = '';
        clearTimeout(searchTimer);
    }
    function open() {
        sync();
        if (trigger.disabled) return;
        activeIndex = Math.max(0, select.selectedIndex);
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        position();
        highlight();
    }
    function choose(index) {
        select.selectedIndex = index;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        trigger.focus();
    }
    trigger.addEventListener('click', () => menu.hidden ? open() : close());
    trigger.addEventListener('keydown', event => {
        if (event.key === 'Tab' || event.key === 'Escape') { close(); return; }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            if (menu.hidden) { open(); return; }
            if (event.key === 'Enter' || event.key === ' ') { choose(activeIndex); return; }
            activeIndex = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            highlight();
        } else if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
            event.preventDefault();
            if (menu.hidden) open();
            search += event.key.toLocaleLowerCase();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { search = ''; }, 600);
            const match = options.findIndex(option => option.label.toLocaleLowerCase().startsWith(search));
            if (match >= 0) { activeIndex = match; highlight(); }
        }
    });
    select.addEventListener('change', sync);
    document.addEventListener('pointerdown', event => { if (!trigger.contains(event.target) && !menu.contains(event.target)) close(); });
    document.addEventListener('focusin', event => { if (event.target !== trigger && !menu.contains(event.target)) close(); });
    window.addEventListener('scroll', position, true);
    window.addEventListener('resize', position);
    close();
    sync();
}
