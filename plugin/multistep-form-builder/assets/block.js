(function (wp) {
    if (!wp || !wp.blocks) {
        return;
    }

    var el = wp.element.createElement;
    var registerBlockType = wp.blocks.registerBlockType;
    var SelectControl = wp.components.SelectControl;
    var Placeholder = wp.components.Placeholder;
    var useBlockProps = wp.blockEditor.useBlockProps;

    function formOptions() {
        var forms = (window.msfbBlockData && window.msfbBlockData.forms) || [];
        var options = [{ label: '— Select a form —', value: 0 }];
        forms.forEach(function (f) {
            options.push({ label: f.title + ' (#' + f.id + ')', value: f.id });
        });
        return options;
    }

    function formTitle(id) {
        var forms = (window.msfbBlockData && window.msfbBlockData.forms) || [];
        for (var k = 0; k < forms.length; k++) {
            if (forms[k].id === id) {
                return forms[k].title;
            }
        }
        return '';
    }

    registerBlockType('msfb/form', {
        title: 'Multistep Form',
        description: 'Display a multistep form built with Multistep Form Builder.',
        icon: 'feedback',
        category: 'widgets',
        attributes: {
            formId: { type: 'number', default: 0 }
        },
        edit: function (props) {
            var blockProps = useBlockProps();
            var formId = props.attributes.formId;

            return el(
                'div',
                blockProps,
                el(
                    Placeholder,
                    {
                        icon: 'feedback',
                        label: 'Multistep Form',
                        instructions: formId
                            ? 'Showing: ' + formTitle(formId)
                            : 'Choose which form to display.'
                    },
                    el(SelectControl, {
                        value: formId,
                        options: formOptions(),
                        onChange: function (value) {
                            props.setAttributes({ formId: parseInt(value, 10) || 0 });
                        }
                    })
                )
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp);
