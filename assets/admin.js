document.addEventListener('DOMContentLoaded', function () {
    const l10n = window.scopedsrL10n || {};

    document.querySelectorAll('.scopedsr-card form').forEach(function (form) {
        const table = form.querySelector('.scopedsr-table-selector');
        const column = form.querySelector('.scopedsr-column-selector');
        const type = form.querySelector('.scopedsr-post-type-selector');
        const status = form.querySelector('.scopedsr-status-selector');
        const item = form.querySelector('.scopedsr-item-selector');

        function updateColumns() {
            if (!table || !column) return;
            let valid = false;
            column.querySelectorAll('option').forEach(function (option) {
                const show = option.dataset.table === table.value;
                option.hidden = !show;
                option.disabled = !show;
                if (show && option.selected) valid = true;
            });
            if (!valid) {
                const first = Array.from(column.options).find(function (o) { return !o.disabled; });
                if (first) first.selected = true;
            }
        }

        function updateItems() {
            if (!type || !status || !item) return;
            Array.from(item.options).forEach(function (option, index) {
                if (index === 0) return;
                const typeOK = type.value === 'all' || option.dataset.postType === type.value;
                const statusOK = status.value === 'all' || option.dataset.postStatus === status.value;
                option.hidden = !(typeOK && statusOK);
                option.disabled = !(typeOK && statusOK);
                if (option.selected && option.disabled) item.value = '0';
            });
        }

        if (table) table.addEventListener('change', updateColumns);
        if (type) type.addEventListener('change', updateItems);
        if (status) status.addEventListener('change', updateItems);
        updateColumns();
        updateItems();
    });

    document.querySelectorAll('.scopedsr-replace-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const oldValue = form.querySelector('[name="old_text"]').value;
            const newValue = form.querySelector('[name="new_text"]').value;
            const table = form.querySelector('[name="selected_table"] option:checked').textContent.trim();
            const field = form.querySelector('[name="selected_column"] option:checked').textContent.trim();
            const item = form.querySelector('[name="selected_post_id"] option:checked').textContent.trim();
            const message = (l10n.review || 'Please review the replacement:') + '\n\n'
                + (l10n.oldValue || 'Old value:') + '\n' + oldValue + '\n\n'
                + (l10n.newValue || 'New value:') + '\n' + newValue + '\n\n'
                + (l10n.table || 'Table:') + ' ' + table + '\n'
                + (l10n.field || 'Field:') + ' ' + field + '\n'
                + (l10n.scope || 'Scope:') + ' ' + item + '\n\n'
                + (l10n.proceed || 'Create a backup before continuing. Do you want to proceed?');
            if (!window.confirm(message)) event.preventDefault();
        });
    });
});
