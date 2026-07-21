define([
    'jquery',
    'Folix_AttributeBatchImport/js/batch-import-parser'
], function ($, parser) {
    'use strict';

    var SUPPORTED_TYPES = ['select', 'multiselect', 'swatch_visual', 'swatch_text'];

    return function () {
        var $panel = $('#batch-import-options-panel'),
            $textarea = $('#batch-import-textarea'),
            $btnAdd = $('#batch-import-btn-add'),
            $status = $('#batch-import-status'),
            $frontendInput = $('#frontend_input');

        // ============================================================
        // 1. Panel toggle — show/hide based on frontend_input
        // ============================================================
        function togglePanel() {
            var value = $frontendInput.val();
            if (SUPPORTED_TYPES.indexOf(value) >= 0) {
                $panel.show();
            } else {
                $panel.hide();
            }
        }

        togglePanel();
        $frontendInput.on('change', function () {
            $textarea.val('');
            togglePanel();
        });

        // ============================================================
        // 2. Handler & container resolution
        // ============================================================
        var OPTIONS_HANDLER_MAP = {
            select:        'attributeOption',
            multiselect:   'attributeOption',
            swatch_visual: 'swatchVisualOption',
            swatch_text:   'swatchTextOption'
        };

        var OPTIONS_CONTAINER_MAP = {
            select:        '[data-role="options-container"]',
            multiselect:   '[data-role="options-container"]',
            swatch_visual: '[data-role="swatch-visual-options-container"]',
            swatch_text:   '[data-role="swatch-text-options-container"]'
        };

        function getOptionsHandler(inputType) {
            var key = OPTIONS_HANDLER_MAP[inputType];
            return key ? window[key] : null;
        }

        function getOptionsContainer(inputType) {
            var selector = OPTIONS_CONTAINER_MAP[inputType];
            return selector ? $(selector) : $();
        }

        // ============================================================
        // 3. Row filler — back-fill rendered option rows with values
        // ============================================================
        var INPUT_SELECTOR_MAP = {
            select:        '[name$="[{store}]"]',
            multiselect:   '[name$="[{store}]"]',
            swatch_visual: '[name*="optionvisual"][name$="[{store}]"]',
            swatch_text:   '[name*="[{store}]"]'
        };

        function fillRow($row, inputType, rowData) {
            $.each(rowData, function (storeId, label) {
                var selector = INPUT_SELECTOR_MAP[inputType];
                if (!selector) { return; }
                $row.find(selector.replace('{store}', storeId))
                    .val(label)
                    .trigger('change');
            });
        }

        // ============================================================
        // 4. Import action
        // ============================================================
        $btnAdd.on('click', function () {
            var text = $textarea.val();

            if (!text.trim()) {
                showStatus('Nothing to add.');
                return;
            }

            var inputType = $frontendInput.val(),
                opt = getOptionsHandler(inputType);

            if (!opt) {
                showStatus('Error: Options panel not initialized for this input type.');
                return;
            }

            var parsedData = parser.parse(text),
                $container = getOptionsContainer(inputType),
                startCount = opt.itemCount,
                addedCount = 0;

            // 1) Create empty rows with native IDs (buffered)
            for (var i = 0; i < parsedData.length; i++) {
                opt.add({}, false);
            }
            opt.render();

            // 2) Back-fill rendered rows
            var $rows = $container.find('tr');
            $.each(parsedData, function (idx, rowData) {
                var $row = $rows.eq(startCount + idx);
                fillRow($row, inputType, rowData);
                addedCount++;
            });

            if (addedCount > 0) {
                $textarea.val('');
                showStatus(addedCount + ' option(s) added.', 'ok');
            }
        });

        // ============================================================
        // 5. Status helper
        // ============================================================
        var STATUS_COLORS = { ok: '#006400', error: '#b00020', neutral: '#6c757d' };

        function showStatus(msg, type) {
            type = type || 'neutral';
            $status.css('color', STATUS_COLORS[type] || STATUS_COLORS.neutral).text(msg);
            setTimeout(function () { $status.text(''); }, 5000);
        }
    };
});
