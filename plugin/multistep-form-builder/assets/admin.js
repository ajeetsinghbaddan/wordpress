jQuery(function ($) {
    var $steps = $('#msfb-steps');

    if (!$steps.length) {
        $(document).on('click', '.msfb-delete', function (e) {
            if (!confirm('Are you sure you want to delete this? This cannot be undone.')) {
                e.preventDefault();
            }
        });
        return;
    }

    function fieldRow(field) {
        field = field || { label: '', type: 'text', required: false, options: '' };
        var types = ['text', 'email', 'number', 'tel', 'date', 'textarea', 'select', 'radio', 'checkbox'];
        var typeOptions = types.map(function (t) {
            var selected = field.type === t ? ' selected' : '';
            return '<option value="' + t + '"' + selected + '>' + t + '</option>';
        }).join('');

        var needsOptions = ['select', 'radio', 'checkbox'].indexOf(field.type) !== -1;

        return $(
            '<div class="msfb-field-row">' +
                '<input type="text" class="msfb-field-label" placeholder="Field label" value="' + escapeAttr(field.label) + '">' +
                '<select class="msfb-field-type">' + typeOptions + '</select>' +
                '<input type="text" class="msfb-field-options" placeholder="Options (comma separated)" value="' + escapeAttr(field.options) + '" style="display:' + (needsOptions ? 'inline-block' : 'none') + ';">' +
                '<label class="msfb-required-toggle"><input type="checkbox" class="msfb-field-required"' + (field.required ? ' checked' : '') + '> Required</label>' +
                '<button type="button" class="button-link-delete msfb-remove-field">Remove</button>' +
            '</div>'
        );
    }

    function stepBox(step, index) {
        step = step || { step_title: '', fields: [] };
        var $box = $(
            '<div class="msfb-step-box">' +
                '<div class="msfb-step-head">' +
                    '<strong class="msfb-step-number">Step ' + (index + 1) + '</strong>' +
                    '<input type="text" class="msfb-step-title" placeholder="Step title (optional)" value="' + escapeAttr(step.step_title) + '">' +
                    '<button type="button" class="button-link-delete msfb-remove-step">Remove Step</button>' +
                '</div>' +
                '<div class="msfb-fields"></div>' +
                '<button type="button" class="button msfb-add-field">+ Add Field</button>' +
            '</div>'
        );

        var $fields = $box.find('.msfb-fields');
        if (step.fields && step.fields.length) {
            step.fields.forEach(function (f) {
                $fields.append(fieldRow(f));
            });
        } else {
            $fields.append(fieldRow());
        }

        return $box;
    }

    function escapeAttr(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renumberSteps() {
        $steps.find('.msfb-step-box').each(function (i) {
            $(this).find('.msfb-step-number').text('Step ' + (i + 1));
        });
    }

    function collectStructure() {
        var structure = [];
        $steps.find('.msfb-step-box').each(function () {
            var $box = $(this);
            var step = {
                step_title: $box.find('.msfb-step-title').val(),
                fields: []
            };
            $box.find('.msfb-field-row').each(function () {
                var $row = $(this);
                var label = $.trim($row.find('.msfb-field-label').val());
                if (!label) {
                    return;
                }
                step.fields.push({
                    label: label,
                    type: $row.find('.msfb-field-type').val(),
                    required: $row.find('.msfb-field-required').is(':checked'),
                    options: $row.find('.msfb-field-options').val()
                });
            });
            if (step.fields.length) {
                structure.push(step);
            }
        });
        return structure;
    }

    if (Array.isArray(window.msfbExistingStructure) && window.msfbExistingStructure.length) {
        window.msfbExistingStructure.forEach(function (step, i) {
            $steps.append(stepBox(step, i));
        });
    } else {
        $steps.append(stepBox(null, 0));
    }

    $('#msfb-add-step').on('click', function () {
        $steps.append(stepBox(null, $steps.find('.msfb-step-box').length));
    });

    $steps.on('click', '.msfb-add-field', function () {
        $(this).siblings('.msfb-fields').append(fieldRow());
    });

    $steps.on('click', '.msfb-remove-field', function () {
        $(this).closest('.msfb-field-row').remove();
    });

    $steps.on('click', '.msfb-remove-step', function () {
        if (confirm('Remove this step and all its fields?')) {
            $(this).closest('.msfb-step-box').remove();
            renumberSteps();
        }
    });

    $steps.on('change', '.msfb-field-type', function () {
        var $row = $(this).closest('.msfb-field-row');
        var needsOptions = ['select', 'radio', 'checkbox'].indexOf($(this).val()) !== -1;
        $row.find('.msfb-field-options').toggle(needsOptions);
    });

    $('#msfb-builder-form').on('submit', function () {
        var structure = collectStructure();
        if (!structure.length) {
            alert('Please add at least one step with one field.');
            return false;
        }
        $('#msfb-structure').val(JSON.stringify(structure));
    });

    $(document).on('click', '.msfb-delete', function (e) {
        if (!confirm('Are you sure you want to delete this? This cannot be undone.')) {
            e.preventDefault();
        }
    });
});
