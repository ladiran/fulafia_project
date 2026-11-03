// Page behaviour that used to be inline attributes. Kept in one file so the site can forbid inline scripts (CSP).
document.addEventListener('submit', function (event) {
    var message = event.target && event.target.dataset ? event.target.dataset.confirm : null;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
}, true);

// Registration page: running total of the credit units ticked.
function updateUnitTotal() {
    var total = document.querySelector('[data-unit-total]');
    if (!total) { return; }
    var sum = 0;
    document.querySelectorAll('input[data-units]:checked').forEach(function (box) { sum += parseInt(box.dataset.units, 10) || 0; });
    total.textContent = sum;
    total.style.color = sum > parseInt(total.dataset.max, 10) ? '#a12626' : '';
}
document.addEventListener('change', function (event) {
    if (event.target.matches && event.target.matches('input[data-units]')) { updateUnitTotal(); }
});
document.addEventListener('DOMContentLoaded', updateUnitTotal);

document.addEventListener('click', function (event) {
    var print = event.target.closest('[data-print]');
    if (print) {
        event.preventDefault();
        window.print();
        return;
    }
    if (event.target.closest('[data-noop]')) {
        event.preventDefault();
    }
});
