document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.wpsrt-card form').forEach(function (form) {
        const table = form.querySelector('.wpsrt-table-selector');
        const column = form.querySelector('.wpsrt-column-selector');
        const type = form.querySelector('.wpsrt-post-type-selector');
        const status = form.querySelector('.wpsrt-status-selector');
        const item = form.querySelector('.wpsrt-item-selector');

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

    document.querySelectorAll('.wpsrt-replace-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const oldValue = form.querySelector('[name="old_text"]').value;
            const newValue = form.querySelector('[name="new_text"]').value;
            const table = form.querySelector('[name="selected_table"] option:checked').textContent.trim();
            const field = form.querySelector('[name="selected_column"] option:checked').textContent.trim();
            const item = form.querySelector('[name="selected_post_id"] option:checked').textContent.trim();
            const message = 'Please review the replacement:\n\nOld value:\n' + oldValue + '\n\nNew value:\n' + newValue + '\n\nTable: ' + table + '\nField: ' + field + '\nScope: ' + item + '\n\nCreate a backup before continuing. Do you want to proceed?';
            if (!window.confirm(message)) event.preventDefault();
        });
    });
});
