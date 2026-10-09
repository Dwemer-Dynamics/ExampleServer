// Local replacement for Bootstrap's brand-dropdown behavior; no server runtime calls.
const toggle = document.getElementById('brand-toggle');
const menu = document.getElementById('brand-menu');

function setMenu(open) {
    menu.classList.toggle('show', open);
    toggle.setAttribute('aria-expanded', String(open));
}
toggle.addEventListener('click', () => setMenu(toggle.getAttribute('aria-expanded') !== 'true'));
document.addEventListener('click', event => {
    if (!toggle.parentElement.contains(event.target)) setMenu(false);
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        toggle.focus();
    }
});
toggle.parentElement.addEventListener('keydown', event => {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    event.preventDefault();
    setMenu(true);
    const items = Array.from(menu.querySelectorAll('a, button'));
    const index = items.indexOf(document.activeElement);
    const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index <= 0 ? items.length - 1 : index - 1);
    items[next].focus();
});
toggle.parentElement.addEventListener('focusout', event => {
    if (!toggle.parentElement.contains(event.relatedTarget)) setMenu(false);
});
