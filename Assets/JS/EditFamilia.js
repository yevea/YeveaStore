/**
 * This file is part of YeveaStore plugin for FacturaScripts.
 * Copyright (C) 2024 FacturaScripts Community
 *
 * Dynamic toggle for the dimension-limits section in EditFamilia.
 * Shows the section only when "Tipo de Familia" is set to "tableros".
 */
document.addEventListener('DOMContentLoaded', function () {
    var select = document.querySelector('select[name="tipofamilia"]');
    var dimensionGroup = document.getElementById('dimension-limits');

    if (!select || !dimensionGroup) {
        return;
    }

    function toggleDimensions() {
        dimensionGroup.style.display = select.value === 'tableros' ? '' : 'none';
    }

    select.addEventListener('change', toggleDimensions);
    toggleDimensions();
});
