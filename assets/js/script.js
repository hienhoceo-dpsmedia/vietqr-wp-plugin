jQuery(document).ready(function ($) {
    var banksData = [];
    var currentCaptchaAnswer = 8;
    var restUrl = (typeof vietqrData !== 'undefined' && vietqrData.restUrl) ? vietqrData.restUrl : '/wp-json/vietqr-generator/v1';
    var nonce = (typeof vietqrData !== 'undefined' && vietqrData.nonce) ? vietqrData.nonce : '';
    var clientId = (typeof vietqrData !== 'undefined' && vietqrData.clientId) ? vietqrData.clientId : '';
    var requireLogin = (typeof vietqrData !== 'undefined' && vietqrData.requireLogin === '1') ? true : false;
    var isLoggedIn = (typeof vietqrData !== 'undefined' && vietqrData.isLoggedIn === '1') ? true : false;

    // Google Sign-In Initialization
    function initGoogleSignIn() {
        if (!clientId || isLoggedIn) return;
        if (typeof google === 'undefined' || !google.accounts || !google.accounts.id) {
            setTimeout(initGoogleSignIn, 300);
            return;
        }

        google.accounts.id.initialize({
            client_id: clientId,
            callback: handleGoogleCredentialResponse,
            auto_select: false
        });

        var btnContainer = document.getElementById('vietqrGoogleBtn');
        if (btnContainer) {
            google.accounts.id.renderButton(btnContainer, {
                theme: 'outline',
                size: 'medium',
                type: 'standard',
                shape: 'rectangular',
                text: 'signin_with',
                logo_alignment: 'left'
            });
        }
    }

    function handleGoogleCredentialResponse(response) {
        if (!response || !response.credential) return;

        var $errorMessage = $('#vietqr-embed #errorMessage');
        $errorMessage.hide();

        $.ajax({
            url: restUrl + '/google-login',
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-WP-Nonce': nonce
            },
            data: JSON.stringify({
                credential: response.credential
            }),
            success: function (res) {
                if (res && res.success) {
                    if (res.nonce) {
                        nonce = res.nonce;
                    }
                    // Auto-refresh page to update session and UI state
                    window.location.reload();
                } else {
                    $errorMessage.text((res && res.message) ? res.message : 'Đăng nhập Google thất bại.').show();
                }
            },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Xác thực Google thất bại.';
                $errorMessage.text(msg).show();
            }
        });
    }

    initGoogleSignIn();

    // Logout Action
    $(document).on('click', '#vietqrLogoutBtn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).text('Đang thoát...');

        $.ajax({
            url: restUrl + '/logout',
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-WP-Nonce': nonce
            },
            success: function (res) {
                window.location.reload();
            },
            error: function () {
                window.location.reload();
            }
        });
    });

    function generateCaptcha() {
        var num1 = Math.floor(Math.random() * 10) + 1;
        var num2 = Math.floor(Math.random() * 10) + 1;
        var operators = ['+', '-'];
        var operator = operators[Math.floor(Math.random() * operators.length)];

        var question, answer;
        if (operator === '+') {
            question = num1 + ' + ' + num2 + ' = ?';
            answer = num1 + num2;
        } else {
            if (num1 < num2) {
                var temp = num1;
                num1 = num2;
                num2 = temp;
            }
            question = num1 + ' - ' + num2 + ' = ?';
            answer = num1 - num2;
        }

        currentCaptchaAnswer = answer;
        $('#vietqr-embed #captchaQuestion').text(question);
        $('#vietqr-embed #captchaAnswer').val('');
    }

    // Initialize captcha on page load
    generateCaptcha();

    $('#vietqr-embed #bankDropdown').on('click', function (e) {
        e.stopPropagation();
        var $menu = $('#vietqr-embed #bankMenu');
        if (banksData.length === 0) {
            renderBankList([]);
            $menu.show();
            $menu.find('#bankSearch').val('').prop('disabled', true).attr('placeholder', 'Đang tải...');

            $.ajax({
                url: restUrl + '/bank-list',
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-WP-Nonce': nonce
                },
                success: function (response) {
                    banksData = response && (response.data || response.banks || (Array.isArray(response) ? response : [])) || [];
                    $menu.find('#bankSearch').prop('disabled', false).attr('placeholder', 'Tìm ngân hàng...');
                    renderBankList(banksData);
                    $menu.show();
                },
                error: function (xhr) {
                    renderBankList([]);
                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Lỗi tải danh sách ngân hàng';
                    $menu.append('<div class="dropdown-item">' + errorMsg + '</div>');
                }
            });
        } else {
            $menu.toggle();
        }
    });

    function renderBankList(list) {
        var $menu = $('#vietqr-embed #bankMenu');
        $menu.find('.dropdown-item').remove();
        if (list && list.length) {
            list.forEach(function (bank) {
                $menu.append(
                    '<div class="dropdown-item" data-bin="' + (bank.bin || '') + '">' +
                    '<img src="' + (bank.logo || '') + '" alt="' + (bank.shortName || '') + ' logo" loading="lazy" width="90" height="34">' +
                    '<span>' + (bank.shortName || bank.name || '') + '</span>' +
                    '</div>'
                );
            });
        } else {
            $menu.append('<div class="dropdown-item">Không có dữ liệu ngân hàng</div>');
        }
    }

    $(document).on('input', '#vietqr-embed #bankSearch', function () {
        var kw = ($(this).val() || '').toLowerCase().trim();
        if (!kw) {
            renderBankList(banksData);
            return;
        }
        var filtered = banksData.filter(function (b) {
            return (b.shortName || '').toLowerCase().includes(kw) ||
                (b.name || '').toLowerCase().includes(kw) ||
                String(b.bin || '').includes(kw);
        });
        renderBankList(filtered);
    });

    $(document).on('click', '#vietqr-embed .dropdown-item', function () {
        var bin = $(this).attr('data-bin');
        if (bin) {
            var bankName = $(this).text().trim();
            $('#vietqr-embed #bankId').val(bin);
            $('#vietqr-embed #bankDropdown').html('<span>' + bankName + '</span> <span class="caret">&#9662;</span>');
        }
        $('#vietqr-embed #bankMenu').hide();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#vietqr-embed .custom-dropdown').length) {
            $('#vietqr-embed #bankMenu').hide();
        }
    });

    $('#vietqr-embed #refreshCaptcha').on('click', function () {
        generateCaptcha();
    });

    $('#vietqr-embed #vietqr-generator-form').on('submit', function (e) {
        e.preventDefault();
        var $generateBtn = $('#vietqr-embed #generateBtn');
        var $qrCodeResult = $('#vietqr-embed #qrCodeResult');
        var $errorMessage = $('#vietqr-embed #errorMessage');

        if (requireLogin && !isLoggedIn) {
            $errorMessage.text('Bạn phải đăng nhập bằng Google trước khi tạo mã VietQR.').show();
            return;
        }

        if (!$('#vietqr-embed #bankId').val()) {
            $errorMessage.text('Vui lòng chọn ngân hàng.').show();
            return;
        } else {
            $errorMessage.hide();
        }

        var userAnswer = parseInt($('#vietqr-embed #captchaAnswer').val());
        if (isNaN(userAnswer) || userAnswer !== currentCaptchaAnswer) {
            $errorMessage.text('Đáp án xác thực không chính xác.').show();
            generateCaptcha();
            return;
        } else {
            $errorMessage.hide();
        }

        $generateBtn.prop('disabled', true).text('Đang tạo...');
        $qrCodeResult.html('<div class="loader"></div>');
        $errorMessage.hide();

        var formData = {
            accountNo: $('#vietqr-embed #accountNumber').val(),
            accountName: $('#vietqr-embed #accountName').val(),
            acqId: $('#vietqr-embed #bankId').val(),
            amount: $('#vietqr-embed #amount').val() || null,
            addInfo: $('#vietqr-embed #description').val() || null
        };

        $.ajax({
            url: restUrl + '/generate-qr',
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-WP-Nonce': nonce
            },
            data: JSON.stringify(formData),
            success: function (response) {
                if (response && response.nonce) {
                    nonce = response.nonce;
                }
                var img = (response && (response.qrCodeBase64 || (response.data && response.data.qrCodeBase64))) || '';
                if (img) {
                    $qrCodeResult.html('<img src="' + img + '" alt="VietQR Code">');
                } else {
                    $qrCodeResult.html('<div>Không nhận được QR Code hợp lệ.</div>');
                    console.error('Invalid response from VietQR REST API:', response);
                }
            },
            error: function (xhr) {
                $qrCodeResult.html('<div>Tạo mã QR thất bại.</div>');
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Đã có lỗi xảy ra. Vui lòng thử lại.';
                $errorMessage.text(msg).show();
            },
            complete: function () {
                $generateBtn.prop('disabled', false).text('Tạo mã');
            }
        });
    });
});
