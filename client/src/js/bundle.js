import A11yDialog from 'a11y-dialog';
import $ from 'jquery';
require('./TranslatableDataObjectField');
require('./TranslationAdmin');
require('./CMSBatchAction_Translate');

let changes = {};

$.entwine('ss', function($) {
    $('.text-assistant').entwine({
        onchange: function(e) {
            this._super(e);
            changes[$(this).attr('name')] = $(this).val();
            let json = JSON.stringify(changes);
            $(this).closest('form').find('.text-assistant-button').each(function() {
                $(this).attr('data-fieldvalues', json);
            });
        }
    });
});

document.addEventListener('click', function(e) {
    if (e.target.matches('.text-assistant button, .text-assistant button *')) {
        let button = e.target.closest('button') || e.target;
        let dialogID = button.getAttribute('data-dialog');
        let dialog = document.getElementById(dialogID);
        if (!dialog) {
            let template = button.parentElement.querySelector('template');
            dialog = template.content.cloneNode(true);
            document.body.appendChild(dialog);
            dialog = document.getElementById(dialogID);
            // init a11y dialog
            dialog.textAssistantDialog = new A11yDialog(dialog);
            dialog.textAssistantDialog.on('hide', function() {
                dialog.querySelector('.dialog-form').innerHTML = '';
            });
        }
        dialog.querySelector('.dialog-form').innerHTML = '';
        let href = button.getAttribute('data-url');
        if (button.getAttribute('data-fieldvalues')) {
            href += '?FieldValues='+button.getAttribute('data-fieldvalues');
        }
        $('.cms-content').first().addClass('loading');
        fetch(href, {
            credentials: 'include',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function(response) {
            if (!response.ok) {
                $('.cms-content').first().removeClass('loading');
                throw new Error('HTTP error, status = ' + response.status);
            }
            return response.text();
        }).then(function(data) {
            dialog.querySelector('.dialog-form').innerHTML = data;
            $('.cms-content').first().removeClass('loading');
        });
        dialog.textAssistantDialog.show();

        e.stopPropagation();
        e.preventDefault();
        return false;
    }
    else if (e.target.matches('.text-assistant-dialog form .btn-toolbar .action[name="action_close"], .text-assistant-dialog form .btn-toolbar .action[name="action_close"] *')) {
        e.target.closest('.text-assistant-dialog').textAssistantDialog.hide();
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
    else if (e.target.matches('.text-assistant-dialog form .btn-toolbar .action[name="action_accept"], .text-assistant-dialog form .btn-toolbar .action[name="action_accept"] *')) {
        let buttonID = e.target.closest('.text-assistant-dialog').getAttribute('data-button');
        let button = document.getElementById(buttonID);
        let contentField = e.target.closest('form').querySelector('#Content');
        if (contentField.classList.contains('form__fieldgroup')) {
            // multiple fields
            contentField.querySelectorAll('.form-control-static').forEach(function(element) {
                let elementName = element.getAttribute('id');
                let field = button.closest('form').querySelector(`[name="${elementName}"]`).closest('.field');
                let content = element.innerHTML.trim();
                setFieldContent(field, content);
            });
        }
        else {
            let content = contentField.innerHTML.trim();
            let field = button.closest('.field');
            setFieldContent(field, content);
        }
        e.target.closest('.text-assistant-dialog').textAssistantDialog.hide();
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
    else if (e.target.matches('.text-assistant-dialog form .btn-toolbar button[type="submit"], .text-assistant-dialog form .btn-toolbar button[type="submit"] *')) {
        let button = e.target.closest('button') || e.target;
        let form = button.closest('form');
        let formData = new FormData(form);
        formData.append(button.getAttribute('name'), 1);
        $('.cms-content').first().addClass('loading');
        fetch(form.getAttribute('action'), {
            method: 'POST',
            credentials: 'include',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        }).then(function(response) {
            if (!response.ok) {
                $('.cms-content').first().removeClass('loading');
                throw new Error('HTTP error, status = ' + response.status);
            }
            if (response.headers.has('x-close')) {
                console.log('x-close');
                let dialog = e.target.closest('.text-assistant-dialog');
                dialog.querySelector('.dialog-form').innerHTML = '';
                dialog.textAssistantDialog.hide();
            }
            if (response.headers.has('x-redirect')) {
                console.log('x-redirect: ' + response.headers.get('x-redirect'));
                window.location.href = response.headers.get('x-redirect');
                return;
            }
            return response.text();
        }).then(function(data) {
            if (data) {
                $('.cms-content').first().removeClass('loading');
                let dialog = e.target.closest('.text-assistant-dialog');
                dialog.querySelector('.dialog-form').innerHTML = data;
            }
        });
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
});

function setFieldContent(field, content) {
    let input = null;
    if (field.classList.contains('htmleditor')) {
        input = field.querySelector('textarea');

        let editor = tinymce.get(input.id);

        editor.setContent(content);
        editor.save();
    }
    else if (field.classList.contains('textarea')) {
        input = field.querySelector('textarea');
        $(input).val(content).change();
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }
    else {
        input = field.querySelector('input');
        $(input).val(content).change();
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }

    let hiddenDataField = document.querySelector('input[name="TextAssistantData['+ input.getAttribute('name') +']"]');
    let data = {
        'GeneratedByAI': true
    }

    hiddenDataField.value = JSON.stringify(data);
}
