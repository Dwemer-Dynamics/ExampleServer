// Fictional editors and sample-log filters operate on this page only.
const preview = document.querySelector('[data-preview]');
if (preview) {
    const form = preview.querySelector('[data-preview-form]');
    const choices = [...preview.querySelectorAll('[data-sample]')];
    preview.querySelector('[data-search]').addEventListener('input', event => {
        const query = event.target.value.toLowerCase();
        choices.forEach(choice => { choice.hidden = !choice.textContent.toLowerCase().includes(query); });
    });
    choices.forEach(choice => choice.addEventListener('click', () => {
        const sample = JSON.parse(choice.dataset.sample);
        for (const [key, value] of Object.entries(sample)) form.elements.namedItem(key).value = value;
        choices.forEach(item => {
            item.classList.toggle('selected', item === choice);
            item.setAttribute('aria-pressed', String(item === choice));
        });
        preview.querySelector('.preview-output').hidden = true;
        preview.querySelector('[data-preview-status]').textContent = 'Sample loaded. Nothing saved.';
    }));
    form.addEventListener('submit', event => {
        event.preventDefault();
        preview.querySelector('[data-preview-output]').textContent = [...new FormData(form).values()].join('\n\n');
        preview.querySelector('.preview-output').hidden = false;
        preview.querySelector('[data-preview-status]').textContent = 'Local preview updated. Nothing saved.';
    });
}
const logs = document.querySelector('[data-log-view]');
if (logs) {
    const level = logs.querySelector('#log-level');
    const search = logs.querySelector('#log-search');
    // Both toolbar controls filter the same small set of fictional rows.
    function filterLogs() {
        let count = 0;
        logs.querySelectorAll('[data-level]').forEach(row => {
            row.hidden = (level.value !== '' && row.dataset.level !== level.value) || !row.textContent.toLowerCase().includes(search.value.toLowerCase());
            if (!row.hidden) count++;
        });
        logs.querySelector('#log-count').textContent = `${count} sample entries`;
    }
    level.addEventListener('change', filterLogs);
    search.addEventListener('input', filterLogs);
}
