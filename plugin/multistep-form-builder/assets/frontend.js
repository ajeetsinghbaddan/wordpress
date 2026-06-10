(function () {
    'use strict';

    function initForm(wrap) {
        var form = wrap.querySelector('.msfb-form');
        var steps = Array.prototype.slice.call(wrap.querySelectorAll('.msfb-step'));
        var dots = Array.prototype.slice.call(wrap.querySelectorAll('.msfb-progress-dot'));
        var prevBtn = wrap.querySelector('.msfb-prev');
        var nextBtn = wrap.querySelector('.msfb-next');
        var submitBtn = wrap.querySelector('.msfb-submit');
        var message = wrap.querySelector('.msfb-message');
        var current = 0;
        var total = steps.length;

        function showStep(index) {
            current = index;
            steps.forEach(function (step, i) {
                step.classList.toggle('active', i === index);
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('active', i === index);
                dot.classList.toggle('completed', i < index);
            });
            prevBtn.style.display = index > 0 ? '' : 'none';
            nextBtn.style.display = index < total - 1 ? '' : 'none';
            submitBtn.style.display = index === total - 1 ? '' : 'none';
        }

        function setError(field, text) {
            var error = field.querySelector('.msfb-error');
            if (error) {
                error.textContent = text || '';
            }
        }

        function validateStep(index) {
            var valid = true;
            var step = steps[index];

            step.querySelectorAll('.msfb-field').forEach(function (field) {
                setError(field, '');
            });

            step.querySelectorAll('[data-required="1"]').forEach(function (el) {
                var field = el.closest('.msfb-field');
                var filled;

                if (el.classList.contains('msfb-choice-group')) {
                    filled = el.querySelector('input:checked') !== null;
                } else {
                    filled = el.value.trim() !== '';
                }

                if (!filled) {
                    setError(field, 'This field is required.');
                    valid = false;
                    return;
                }

                if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())) {
                    setError(field, 'Please enter a valid email address.');
                    valid = false;
                }
            });

            return valid;
        }

        function collectData() {
            var data = {};
            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (!el.name || el.name === 'msfb_hp') {
                    return;
                }
                if (el.type === 'checkbox') {
                    if (el.checked) {
                        if (!Array.isArray(data[el.name])) {
                            data[el.name] = [];
                        }
                        data[el.name].push(el.value);
                    }
                } else if (el.type === 'radio') {
                    if (el.checked) {
                        data[el.name] = el.value;
                    }
                } else {
                    data[el.name] = el.value;
                }
            });
            return data;
        }

        function showServerErrors(fields) {
            var firstErrorStep = null;

            Object.keys(fields).forEach(function (name) {
                var el = form.querySelector('[name="' + name + '"]');
                if (!el) {
                    return;
                }
                var field = el.closest('.msfb-field');
                var step = el.closest('.msfb-step');
                if (field) {
                    setError(field, fields[name]);
                }
                if (step) {
                    var stepIndex = steps.indexOf(step);
                    if (firstErrorStep === null || stepIndex < firstErrorStep) {
                        firstErrorStep = stepIndex;
                    }
                }
            });

            if (firstErrorStep !== null) {
                showStep(firstErrorStep);
            }
        }

        nextBtn.addEventListener('click', function () {
            if (validateStep(current)) {
                showStep(current + 1);
            }
        });

        prevBtn.addEventListener('click', function () {
            showStep(current - 1);
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!validateStep(current)) {
                return;
            }

            message.textContent = '';
            message.classList.remove('success', 'error');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';

            var body = new URLSearchParams();
            body.append('action', 'msfb_submit');
            body.append('nonce', window.msfbAjax.nonce);
            body.append('form_id', wrap.getAttribute('data-form-id'));
            body.append('msfb_hp', form.querySelector('[name="msfb_hp"]').value);
            body.append('data', JSON.stringify(collectData()));

            fetch(window.msfbAjax.url, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (res) {
                    if (res.success) {
                        steps.forEach(function (step) {
                            step.style.display = 'none';
                        });
                        wrap.querySelectorAll('.msfb-nav, .msfb-progress').forEach(function (node) {
                            node.style.display = 'none';
                        });
                        message.classList.add('success');
                        message.textContent = res.data.message;
                    } else {
                        message.classList.add('error');
                        message.textContent = (res.data && res.data.message) || 'Something went wrong.';
                        if (res.data && res.data.fields) {
                            showServerErrors(res.data.fields);
                        }
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                })
                .catch(function () {
                    message.classList.add('error');
                    message.textContent = 'Server error. Please try again.';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit';
                });
        });

        showStep(0);
    }

    function boot() {
        document.querySelectorAll('.msfb-form-wrap').forEach(initForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
