/**
 * Admin-page behaviors. Lives in a file (not inline) so internal pages can
 * run under a Content-Security-Policy with no 'unsafe-inline'.
 */
(function () {
    'use strict';

    // Confirmation prompts: <form data-confirm="message">.
    document.addEventListener('submit', function (e) {
        var message = e.target.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });

    // Back buttons: <button data-back>.
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-back]')) {
            e.preventDefault();
            window.history.back();
        }
    });

    // Markdown editor on the write/edit screen.
    var content = document.getElementById('blogPostContent');
    if (content && window.EasyMDE) {
        new window.EasyMDE({
            element: content,
            spellChecker: false, // remote dictionary fetch; blocked by CSP and slow
            status: false
        });
    }

    // Client-side guard matching the server's 10 MB upload cap.
    var upload = document.getElementById('imageUpload');
    if (upload) {
        upload.addEventListener('change', function () {
            if (this.files[0] && this.files[0].size > 10485760) {
                window.alert('Uploaded file exceeds the 10 MB maximum.');
                this.value = '';
            }
        });
    }
})();
