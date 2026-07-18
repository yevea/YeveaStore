/**
 * "Tabla de Precios" tab (EditFamilia): shows/hides the config sections
 * according to the selected calculator mode, and manages the dynamic rows
 * of the capture thickness-rate table.
 */
(function () {
    'use strict';

    var modeSelect = document.getElementById('calc_mode');
    if (!modeSelect) {
        return;
    }

    var UNIT_DEFAULTS = { area: 'm²', volume: 'm³' };
    var LABEL_DEFAULTS = { area: 'Precio por m²', volume: 'Precio por m³' };

    function toggleSections() {
        var mode = modeSelect.value;
        document.querySelectorAll('.yc-calc-section').forEach(function (section) {
            var modes = (section.getAttribute('data-modes') || '').split(' ');
            section.style.display = modes.indexOf(mode) === -1 ? 'none' : '';
        });

        // Keep the price unit/label defaults in sync with the mode: update
        // placeholders always, and swap the value only when it is empty or
        // still the default of the other mode.
        if (mode === 'area' || mode === 'volume') {
            var unitInput = document.getElementById('price_unit');
            var labelInput = document.getElementById('price_label');
            var knownUnits = ['', 'm2', 'm²', 'm3', 'm³', 'l'];
            if (unitInput) {
                unitInput.placeholder = UNIT_DEFAULTS[mode];
                if (knownUnits.indexOf(unitInput.value.trim()) !== -1) {
                    unitInput.value = UNIT_DEFAULTS[mode];
                }
            }
            if (labelInput) {
                labelInput.placeholder = LABEL_DEFAULTS[mode];
                var knownLabels = ['', 'Precio por m²', 'Precio por m³', 'Precio per m2'];
                if (knownLabels.indexOf(labelInput.value.trim()) !== -1) {
                    labelInput.value = LABEL_DEFAULTS[mode];
                }
            }
        }
    }

    modeSelect.addEventListener('change', toggleSections);
    toggleSections();

    // ---- capture-rate rows ----

    var table = document.getElementById('yc-rates-table');
    var addBtn = document.getElementById('yc-rate-add');

    function bindDelete(button) {
        button.addEventListener('click', function () {
            button.closest('tr').remove();
        });
    }

    table.querySelectorAll('.yc-rate-del').forEach(bindDelete);

    addBtn.addEventListener('click', function () {
        var row = document.createElement('tr');
        row.innerHTML =
            '<td><input type="number" class="form-control form-control-sm" name="rate_desde[]" step="0.1" min="0"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="rate_hasta[]" step="0.1" min="0"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="rate_precio[]" step="0.01" min="0"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger yc-rate-del"><i class="fa-solid fa-trash"></i></button></td>';
        table.querySelector('tbody').appendChild(row);
        bindDelete(row.querySelector('.yc-rate-del'));
    });
})();
