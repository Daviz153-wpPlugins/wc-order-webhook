(function () {
    'use strict';

    // Settings page: Test send button
    var testBtn = document.getElementById('wcmw-test-btn');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var btn    = this;
            var result = document.getElementById('wcmw-test-result');
            btn.disabled    = true;
            btn.textContent = '발송 중...';
            fetch(ajaxurl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'action=wcmw_test_send&nonce=' + wcmwAdmin.testNonce
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                result.textContent   = data.message;
                result.className     = 'wcmw-notice ' + (data.success ? 'wcmw-success' : 'wcmw-error');
                result.style.display = 'block';
            })
            .finally(function () {
                btn.disabled    = false;
                btn.textContent = '테스트 발송';
            });
        });
    }

    // Logs page: Clear logs button
    var clearBtn = document.getElementById('wcmw-clear-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!confirm('모든 로그를 삭제하시겠습니까?')) return;
            fetch(ajaxurl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'action=wcmw_clear_logs&nonce=' + wcmwAdmin.clearNonce
            }).then(function () { location.reload(); });
        });
    }

    // Product panel: Toggle + test send
    var wrap = document.getElementById('wcmw-toggle-wrap');
    if (wrap) {
        var checkbox  = document.getElementById('wcmw_product_enabled');
        var track     = document.getElementById('wcmw-track');
        var thumb     = document.getElementById('wcmw-thumb');
        var urlField  = document.getElementById('wcmw_url_field');
        var testField = document.getElementById('wcmw_test_field');

        function applyState(on) {
            track.style.background  = on ? '#2271b1' : '#ccc';
            thumb.style.left        = on ? '22px' : '2px';
            urlField.style.display  = on ? '' : 'none';
            testField.style.display = on ? '' : 'none';
        }

        wrap.addEventListener('click', function () {
            checkbox.checked = !checkbox.checked;
            applyState(checkbox.checked);
        });

        var productTestBtn = document.getElementById('wcmw-product-test-btn');
        if (productTestBtn) {
            productTestBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var btn    = this;
                var result = document.getElementById('wcmw-product-test-result');
                var url    = document.getElementById('wcmw_product_url').value;
                if (!url) {
                    result.textContent = 'URL을 먼저 입력하세요.';
                    result.style.color = '#c00';
                    return;
                }
                btn.disabled    = true;
                btn.textContent = '발송 중...';
                result.textContent = '';
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=wcmw_product_test_send&nonce=' + btn.dataset.nonce
                           + '&product_id=' + btn.dataset.productId
                           + '&url=' + encodeURIComponent(url)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    result.textContent = data.message;
                    result.style.color = data.success ? '#155724' : '#721c24';
                })
                .finally(function () {
                    btn.disabled    = false;
                    btn.textContent = '테스트 발송';
                });
            });
        }
    }
})();
