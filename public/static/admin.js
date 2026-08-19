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

    // Markdown editor on the write/edit screen. Explicit toolbar so the
    // heading button becomes a dropdown offering H1/H2/H3.
    var content = document.getElementById('blogPostContent');
    if (content && window.EasyMDE) {
        new window.EasyMDE({
            element: content,
            spellChecker: false, // remote dictionary fetch; blocked by CSP and slow
            status: false,
            toolbar: [
                'bold', 'italic',
                {
                    name: 'heading',
                    action: window.EasyMDE.toggleHeadingSmaller,
                    className: 'fa fa-header fa-heading',
                    title: 'Headings',
                    children: ['heading-1', 'heading-2', 'heading-3']
                },
                '|', 'quote', 'unordered-list', 'ordered-list',
                '|', 'link', 'image',
                '|', 'preview', 'side-by-side', 'fullscreen',
                '|', 'guide'
            ]
        });
    }

    // Two-factor enrollment: render the otpauth:// URI as a QR code, locally
    // (sending the secret to a remote QR service would leak it).
    var qrEl = document.getElementById('totpQr');
    if (qrEl && window.qrcode) {
        var qr = window.qrcode(0, 'M');
        qr.addData(qrEl.getAttribute('data-otpauth'));
        qr.make();
        qrEl.innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
    }

    // Client-side guard matching the server's effective upload cap, which the
    // write view passes in via data-max-bytes (min of app cap and PHP limits).
    // No fallback figure on purpose: the attribute carries the EFFECTIVE cap,
    // which is lower than the app cap wherever PHP's own limits bind. Guessing
    // one here would tell the writer the server accepts 10 MB on a host that
    // accepts 2 — worse than staying quiet. fn_resolve_image() rejects an
    // oversized file server-side either way, so skipping the hint is safe.
    var upload = document.getElementById('imageUpload');
    var maxBytes = upload ? parseInt(upload.getAttribute('data-max-bytes'), 10) : 0;
    if (upload && maxBytes > 0) {
        upload.addEventListener('change', function () {
            if (this.files[0] && this.files[0].size > maxBytes) {
                window.alert(
                    'That file is ' + (this.files[0].size / 1048576).toFixed(1)
                    + ' MB; the server accepts at most ' + (maxBytes / 1048576).toFixed(1) + ' MB.'
                );
                this.value = '';
            }
        });
    }
})();
