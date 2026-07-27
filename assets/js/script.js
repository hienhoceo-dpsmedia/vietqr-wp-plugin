jQuery(document).ready(function ($) {
    var banksData = [];
    var currentCaptchaAnswer = 8;

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
                url: 'https://auto.dpsmedia.vn/webhook/banklistdpsmedia',
                type: 'POST', contentType: 'application/json', data: JSON.stringify({}),
                success: function (response) {
                    banksData = response && (response.data || response.banks) || [];
                    $menu.find('#bankSearch').prop('disabled', false).attr('placeholder', 'Tìm ngân hàng...');
                    renderBankList(banksData); $menu.show();
                },
                error: function () {
                    renderBankList([]);
                    $menu.append('<div class="dropdown-item">Lỗi tải danh sách ngân hàng</div>');
                }
            });
        } else { $menu.toggle(); }
    });

    function renderBankList(list) {
        var $menu = $('#vietqr-embed #bankMenu');
        $menu.find('.dropdown-item').remove();
        if (list && list.length) {
            list.forEach(function (bank) {
                // Trust API for images as requested
                $menu.append('\n              <div class="dropdown-item" data-bin="' + bank.bin + '">\n                <img src="' + bank.logo + '" alt="' + (bank.shortName || '') + ' logo" loading="lazy" width="90" height="34">\n                <span>' + (bank.shortName || bank.name || '') + '</span>\n              </div>');
            });
        } else { $menu.append('<div class="dropdown-item">Không có dữ liệu ngân hàng</div>'); }
    }

    $(document).on('input', '#vietqr-embed #bankSearch', function () {
        var kw = ($(this).val() || '').toLowerCase().trim();
        if (!kw) { renderBankList(banksData); return; }
        var filtered = banksData.filter(function (b) {
            return (b.shortName || '').toLowerCase().includes(kw) || (b.name || '').toLowerCase().includes(kw) || String(b.bin || '').includes(kw);
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
        if (!$(e.target).closest('#vietqr-embed .custom-dropdown').length) { $('#vietqr-embed #bankMenu').hide(); }
    });

    $('#vietqr-embed #refreshCaptcha').on('click', function () {
        generateCaptcha();
    });

    $('#vietqr-embed #vietqr-generator-form').on('submit', function (e) {
        e.preventDefault();
        var n8nWebhookUrl = 'https://auto.dpsmedia.vn/webhook/qrdpsmedia';
        var $generateBtn = $('#vietqr-embed #generateBtn');
        var $qrCodeResult = $('#vietqr-embed #qrCodeResult');
        var $errorMessage = $('#vietqr-embed #errorMessage');

        if (!$('#vietqr-embed #bankId').val()) { $errorMessage.text('Vui lòng chọn ngân hàng.').show(); return; } else { $errorMessage.hide(); }

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
            addInfo: $('#vietqr-embed #description').val() || null,
            format: 'text', template: 'compact'
        };

        $.ajax({
            url: n8nWebhookUrl, type: 'POST', contentType: 'application/json', data: JSON.stringify(formData),
            success: function (response) {
                var img = (response && (response.qrCodeBase64 || (response.data && response.data.qrCodeBase64))) || '';
                if (img) { $qrCodeResult.html('<img src="' + img + '" alt="VietQR Code">'); }
                else { $qrCodeResult.html('<div>Không nhận được QR Code hợp lệ.</div>'); console.error('Invalid response from n8n:', response); }
            },
            error: function () {
                $qrCodeResult.html('<div>Tạo mã QR thất bại.</div>');
                $errorMessage.text('Đã có lỗi xảy ra. Vui lòng thử lại.').show();
            },
            complete: function () {
                $generateBtn.prop('disabled', false).text('Tạo mã');
            }
        });
    });
});
