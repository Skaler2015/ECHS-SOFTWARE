/* Noble Health Software - front-end helpers */

// ---- Bill: dynamic rows + live totals ----
function recalcRow(row) {
    const qty  = parseFloat(row.querySelector('.qty')?.value)  || 0;
    const rate = parseFloat(row.querySelector('.rate')?.value) || 0;
    const amt  = qty * rate;
    const cell = row.querySelector('.amount');
    if (cell) cell.textContent = amt.toFixed(2);
    return amt;
}

function recalcAll() {
    let sub = 0;
    document.querySelectorAll('#itemsTable .item-row').forEach(r => { sub += recalcRow(r); });
    const disc = parseFloat(document.getElementById('discount')?.value) || 0;
    const grand = Math.max(0, sub - disc);
    const st = document.getElementById('subTotal');
    const gt = document.getElementById('grandTotal');
    if (st) st.textContent = sub.toFixed(2);
    if (gt) gt.textContent = grand.toFixed(2);
}

function addRow() {
    const tbody = document.querySelector('#itemsTable tbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
        '<td><input name="medicine[]" list="medlist" autocomplete="off"></td>' +
        '<td><input name="batch[]"></td>' +
        '<td><input name="expiry[]" placeholder="MM/YY"></td>' +
        '<td><input class="qty r" name="qty[]" type="number" step="0.01" value="1"></td>' +
        '<td><input class="rate r" name="rate[]" type="number" step="0.01" value="0"></td>' +
        '<td class="r amount">0.00</td>' +
        '<td class="r"><button type="button" class="btn-x" onclick="removeRow(this)">✕</button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('#itemsTable .item-row');
    if (rows.length <= 1) {
        // clear instead of removing the last row
        btn.closest('tr').querySelectorAll('input').forEach(i => { i.value = i.classList.contains('qty') ? '1' : (i.classList.contains('rate') ? '0' : ''); });
    } else {
        btn.closest('tr').remove();
    }
    recalcAll();
}

// ---- Patient picker autofill on bill form ----
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('itemsTable');
    if (table) {
        table.addEventListener('input', function (e) {
            if (e.target.classList.contains('qty') || e.target.classList.contains('rate')) recalcAll();
        });
        const disc = document.getElementById('discount');
        if (disc) disc.addEventListener('input', recalcAll);
        recalcAll();
    }

    const picker = document.getElementById('patientPicker');
    if (picker) {
        picker.addEventListener('change', function () {
            const opt = picker.options[picker.selectedIndex];
            document.getElementById('patient_id').value = picker.value || '';
            if (picker.value) {
                document.getElementById('patient_name').value = opt.dataset.name || '';
                document.getElementById('card_no').value = opt.dataset.card || '';
            }
        });
    }
});
