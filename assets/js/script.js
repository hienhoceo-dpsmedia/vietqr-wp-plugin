jQuery(function ($) {
    'use strict';

    let banksData = [];
    let selectedBank = null;
    let captchaAnswer = 0;
    let lastGeneratedQr = '';
    let lastCopyText = '';

    function showError(message) {
        $('#vqg-error-message').text(message).fadeIn(120);
    }

    function hideError() {
        $('#vqg-error-message').hide().text('');
    }

    function showToast() {
        const $toast = $('#vietqr-toast');
        $toast.stop(true, true).fadeIn(140);
        setTimeout(function () {
            $toast.fadeOut(200);
        }, 2200);
    }

    function formatVND(rawAmount) {
        const num = (rawAmount || '').toString().replace(/\D/g, '');
        if (!num) return '';
        return new Intl.NumberFormat('vi-VN').format(Number(num)) + ' VND';
    }

    function generateCaptcha() {
        const a = Math.floor(Math.random() * 9) + 1;
        const b = Math.floor(Math.random() * 9) + 1;
        const plus = Math.random() > 0.5;

        if (plus) {
            captchaAnswer = a + b;
            $('#vqg-captcha-question').text(a + ' + ' + b + ' = ?');
        } else {
            const max = Math.max(a, b);
            const min = Math.min(a, b);
            captchaAnswer = max - min;
            $('#vqg-captcha-question').text(max + ' - ' + min + ' = ?');
        }
        $('#vqg-captcha-answer').val('');
    }

    function updateCounter($input) {
        const max = parseInt($input.attr('data-max') || '0', 10);
        if (!max) return;

        const id = $input.attr('id');
        const counterMap = {
            'vqg-account-number': '#vqg-account-no-counter',
            'vqg-account-name': '#vqg-account-name-counter',
            'vqg-description': '#vqg-description-counter',
            'vqg-store-code': '#vqg-store-code-counter',
            'vqg-pos-code': '#vqg-pos-code-counter'
        };

        const selector = counterMap[id];
        if (!selector) return;

        const len = ($input.val() || '').length;
        $(selector).text(len + '/' + max);
    }

    async function fetchBanks() {
        const $list = $('.dropdown-items-list');
        $list.html('<div class="bank-item">Đang tải danh sách ngân hàng...</div>');

        try {
            const res = await fetch(vietqrVars.restUrl + '/bank-list', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': vietqrVars.nonce
                }
            });

            const payload = await res.json();
            if (!payload.success || !payload.banks) {
                throw new Error(payload.message || 'Không thể tải danh sách ngân hàng.');
            }

            banksData = payload.banks;
            renderBanks(banksData);
        } catch (err) {
            $list.html('<div class="bank-item">Lỗi tải danh sách ngân hàng.</div>');
        }
    }

    function renderBanks(list) {
        const $list = $('.dropdown-items-list');
        $list.empty();

        if (!list.length) {
            $list.html('<div class="bank-item">Không có kết quả.</div>');
            return;
        }

        list.forEach(function (bank) {
            const item = $(
                '<div class="bank-item" data-bin="' + bank.bin + '">' +
                    '<img class="bank-logo-mini" src="' + bank.logo + '" alt="' + bank.shortName + '" loading="lazy">' +
                    '<div class="bank-meta">' +
                        '<div><strong>' + bank.shortName + '</strong></div>' +
                        '<div class="bank-fn">' + bank.name + '</div>' +
                    '</div>' +
                '</div>'
            );
            $list.append(item);
        });
    }

    function openBankDropdown() {
        $('.dropdown-wrap').addClass('open');
        $('#vqg-bank-dropdown').attr('aria-expanded', 'true');
        $('#vqg-bank-menu').stop(true, true).fadeIn(140);
        $('#vqg-bank-search').val('').trigger('input').focus();
    }

    function closeBankDropdown() {
        $('.dropdown-wrap').removeClass('open');
        $('#vqg-bank-dropdown').attr('aria-expanded', 'false');
        $('#vqg-bank-menu').stop(true, true).fadeOut(120);
    }

    function wrapTextByWords(ctx, text, maxWidth) {
        const source = (text || '').trim();
        if (!source) return [];

        const words = source.split(/\s+/);
        const lines = [];
        let current = '';

        words.forEach(function (word) {
            let test = current ? current + ' ' + word : word;
            if (ctx.measureText(test).width <= maxWidth) {
                current = test;
                return;
            }

            if (current) {
                lines.push(current);
                current = '';
            }

            if (ctx.measureText(word).width <= maxWidth) {
                current = word;
                return;
            }

            let fragment = '';
            for (let i = 0; i < word.length; i += 1) {
                const candidate = fragment + word[i];
                if (ctx.measureText(candidate).width <= maxWidth) {
                    fragment = candidate;
                } else {
                    if (fragment) lines.push(fragment);
                    fragment = word[i];
                }
            }
            current = fragment;
        });

        if (current) lines.push(current);
        return lines;
    }

    function loadImage(src) {
        return new Promise(function (resolve) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { resolve(null); };
            img.src = src;
        });
    }

    async function composeFullPreview(rawQrUrl, meta) {
        const qrImg = await loadImage(rawQrUrl);
        const bankImg = meta.bankLogo ? await loadImage(meta.bankLogo) : null;
        const napasImg = await loadImage('https://vietqr.net/assets/img/napas247.png');

        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        const width = 1080;
        const frameX = 60;
        const frameY = 44;
        const frameW = width - frameX * 2;
        const innerPad = 26;
        const innerX = frameX + innerPad;
        const innerY = frameY + innerPad;
        const innerW = frameW - innerPad * 2;
        const qrBoxW = innerW - 56;
        const qrX = innerX + (innerW - qrBoxW) / 2;
        const qrY = innerY + 24;
        const logoRowY = qrY + qrBoxW + 16;

        ctx.font = '700 36px Roboto';
        const amountLine = meta.amountText ? 'Số tiền: ' + meta.amountText : '';
        const contentPrefix = 'Nội dung: ';
        const contentLines = meta.addInfo
            ? wrapTextByWords(ctx, contentPrefix + meta.addInfo, innerW - 70)
            : [];

        ctx.font = '700 33px Roboto';
        const ownerLines = wrapTextByWords(ctx, 'Tên chủ TK: ' + (meta.accountName || '').toUpperCase(), innerW - 70);

        ctx.font = '700 31px Roboto';
        let accountLine = 'Số TK: ' + meta.accountNo;
        if (meta.storeCode) accountLine += ' - Mã cửa hàng: ' + meta.storeCode;
        if (meta.posCode) accountLine += ' - Mã điểm bán: ' + meta.posCode;
        const accLines = wrapTextByWords(ctx, accountLine, innerW - 70);

        ctx.font = '500 31px Roboto';
        const bankLines = wrapTextByWords(ctx, 'Ngân hàng: ' + meta.bankShort, innerW - 70);

        const textTopY = logoRowY + 92;
        const totalTextHeight =
            (amountLine ? 48 : 0) +
            (contentLines.length ? contentLines.length * 40 + 10 : 0) +
            ownerLines.length * 42 + 6 +
            accLines.length * 38 + 8 +
            bankLines.length * 38;

        const minHeight = 1480;
        const height = Math.max(minHeight, textTopY + totalTextHeight + 72);

        canvas.width = width;
        canvas.height = height;

        // Base
        ctx.fillStyle = '#f5f6f8';
        ctx.fillRect(0, 0, width, height);

        // Outer rounded frame
        const outerRadius = 38;
        roundRect(ctx, frameX, frameY, frameW, height - frameY * 2, outerRadius);
        ctx.fillStyle = '#204e9a';
        ctx.fill();

        // Accent strips
        ctx.fillStyle = '#2ca6e6';
        roundRect(ctx, frameX + frameW - 350, frameY, 230, 22, 0);
        ctx.fill();
        ctx.fillStyle = '#96c93a';
        roundRect(ctx, frameX + frameW - 120, frameY, 120, 22, 0);
        ctx.fill();
        ctx.fillStyle = '#96c93a';
        roundRect(ctx, frameX, height - frameY - 22, 130, 22, 0);
        ctx.fill();

        // Inner white card: keep a larger bottom margin so the blue frame is visible.
        const innerBottomGap = 28;
        const innerCardH = height - innerY - frameY - innerBottomGap;
        roundRect(ctx, innerX, innerY, innerW, innerCardH, 22);
        ctx.fillStyle = '#ffffff';
        ctx.fill();

        // QR block image (compact template)
        if (qrImg) {
            ctx.drawImage(qrImg, qrX, qrY, qrBoxW, qrBoxW);
        }

        // Divider
        const dividerY = logoRowY + 74;
        ctx.strokeStyle = '#d5dfec';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(innerX + 32, dividerY);
        ctx.lineTo(innerX + innerW - 32, dividerY);
        ctx.stroke();

        // Overlay logos if available
        if (napasImg) {
            ctx.drawImage(napasImg, innerX + 176, logoRowY + 8, 140, 42);
        }
        if (bankImg) {
            const bw = 160;
            const bh = Math.max(30, Math.round((bankImg.naturalHeight / bankImg.naturalWidth) * bw));
            ctx.drawImage(bankImg, innerX + innerW - 176 - bw, logoRowY + 3, bw, bh);
        }

        // Text info
        let y = textTopY;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';

        if (amountLine) {
            ctx.font = '700 50px Roboto';
            ctx.fillStyle = '#1e4f94';
            ctx.fillText(amountLine, width / 2, y);
            y += 58;
        }

        if (contentLines.length) {
            ctx.font = '500 33px Roboto';
            ctx.fillStyle = '#24528f';
            contentLines.forEach(function (line) {
                ctx.fillText(line, width / 2, y);
                y += 40;
            });
            y += 8;
        }

        ctx.font = '700 34px Roboto';
        ctx.fillStyle = '#234d86';
        ownerLines.forEach(function (line) {
            ctx.fillText(line, width / 2, y);
            y += 42;
        });

        y += 4;
        ctx.font = '700 31px Roboto';
        accLines.forEach(function (line) {
            ctx.fillText(line, width / 2, y);
            y += 38;
        });

        y += 4;
        ctx.font = '500 31px Roboto';
        bankLines.forEach(function (line) {
            ctx.fillText(line, width / 2, y);
            y += 38;
        });

        return canvas.toDataURL('image/png');
    }

    function roundRect(ctx, x, y, width, height, radius) {
        const r = Math.max(0, Math.min(radius, Math.min(width, height) / 2));
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + width - r, y);
        ctx.quadraticCurveTo(x + width, y, x + width, y + r);
        ctx.lineTo(x + width, y + height - r);
        ctx.quadraticCurveTo(x + width, y + height, x + width - r, y + height);
        ctx.lineTo(x + r, y + height);
        ctx.quadraticCurveTo(x, y + height, x, y + height - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    function renderResult(imageDataUrl) {
        $('#vqg-qr-result').html(
            '<div class="preview-frame">' +
                '<img id="finalQrImage" src="' + imageDataUrl + '" alt="VietQR preview">' +
            '</div>' +
            '<div class="preview-actions">' +
                '<button type="button" class="preview-btn" id="vqg-btn-copy-image" title="Sao chép ảnh QR">🖼️ Sao chép hình ảnh</button>' +
                '<button type="button" class="preview-btn" id="vqg-btn-copy-text" title="Sao chép nội dung chuyển khoản">🧾 Sao chép text</button>' +
            '</div>'
        );
    }

    function dataUrlToBlob(dataUrl) {
        const parts = dataUrl.split(',');
        const mime = parts[0].match(/:(.*?);/)[1];
        const binary = atob(parts[1]);
        const len = binary.length;
        const arr = new Uint8Array(len);

        for (let i = 0; i < len; i += 1) {
            arr[i] = binary.charCodeAt(i);
        }
        return new Blob([arr], { type: mime });
    }

    async function copyQrImage() {
        if (!lastGeneratedQr) return;

        try {
            if (navigator.clipboard && window.ClipboardItem) {
                const blob = dataUrlToBlob(lastGeneratedQr);
                await navigator.clipboard.write([
                    new ClipboardItem({ 'image/png': blob })
                ]);
                showToast();
                return;
            }

            if (navigator.clipboard) {
                await navigator.clipboard.writeText(lastGeneratedQr);
                showToast();
                return;
            }

            showError('Trình duyệt không hỗ trợ copy ảnh trực tiếp.');
        } catch (e) {
            showError('Không thể copy ảnh QR.');
        }
    }

    async function copyTextOnly() {
        if (!lastCopyText) return;

        const text = lastCopyText || '';
        if (!text.trim()) {
            showError('Không có nội dung text để sao chép.');
            return;
        }

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                showToast();
                return;
            }

            showError('Trình duyệt không hỗ trợ sao chép text.');
        } catch (e) {
            showError('Không thể sao chép text.');
        }
    }

    async function submitForm(e) {
        e.preventDefault();
        hideError();

        if (vietqrVars.requireLogin === '1' && vietqrVars.isLoggedIn !== '1') {
            showError('Vui lòng đăng nhập bằng Google trước khi tạo mã QR.');
            return;
        }

        const captchaInput = parseInt($('#vqg-captcha-answer').val(), 10);
        if (captchaInput !== captchaAnswer) {
            showError('Mã xác thực chưa đúng.');
            generateCaptcha();
            return;
        }

        const bankId = $('#vqg-bank-id').val();
        if (!bankId) {
            showError('Vui lòng chọn ngân hàng thụ hưởng.');
            return;
        }

        if (!$('#vqg-agree-terms').is(':checked')) {
            showError('Vui lòng đồng ý điều khoản và điều kiện.');
            return;
        }

        const accountNo = ($('#vqg-account-number').val() || '').replace(/\D/g, '').trim();
        const accountName = ($('#vqg-account-name').val() || '').trim();
        const amount = ($('#vqg-amount').val() || '').replace(/\D/g, '').trim();
        const addInfo = ($('#vqg-description').val() || '').trim();
        const storeCode = ($('#vqg-store-code').val() || '').trim();
        const posCode = ($('#vqg-pos-code').val() || '').trim();

        if (accountNo.length < 6 || accountNo.length > 19) {
            showError('Số tài khoản phải từ 6 đến 19 ký tự số.');
            return;
        }

        if (accountName.length < 5) {
            showError('Tên chủ tài khoản phải có ít nhất 5 ký tự.');
            return;
        }

        const $btn = $('#vqg-generate-btn');
        const $loader = $btn.find('.btn-loader');
        const $text = $btn.find('.btn-text');
        $btn.prop('disabled', true);
        $loader.show();
        $text.text('Đang tạo mã...');

        try {
            const res = await fetch(vietqrVars.restUrl + '/generate-qr', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': vietqrVars.nonce
                },
                body: JSON.stringify({
                    accountNo: accountNo,
                    accountName: accountName.toUpperCase(),
                    acqId: bankId,
                    amount: amount,
                    addInfo: addInfo
                })
            });
            const payload = await res.json();

            if (!payload.success || !payload.qrDataURL) {
                throw new Error(payload.message || 'Không tạo được mã QR.');
            }

            const finalImage = await composeFullPreview(payload.data.qrDataURL, {
                amountText: formatVND(amount),
                addInfo: addInfo,
                accountName: accountName,
                accountNo: $('#vqg-show-account-full').is(':checked') ? accountNo : accountNo.slice(0, 3) + '***' + accountNo.slice(-3),
                bankShort: selectedBank ? selectedBank.shortName : 'N/A',
                bankLogo: selectedBank ? selectedBank.logo : '',
                storeCode: storeCode,
                posCode: posCode
            });

            const bankName = selectedBank ? (selectedBank.name || selectedBank.shortName) : '';
            const amountLine = formatVND(amount);
            const lines = [];
            if (amountLine) lines.push('Số tiền: ' + amountLine);
            if (addInfo) lines.push('Nội dung: ' + addInfo);
            lines.push('Tên chủ TK: ' + accountName.toUpperCase());
            lines.push('Số TK: ' + accountNo);
            if (bankName) lines.push('Ngân hàng: ' + bankName);
            if (storeCode) lines.push('Mã cửa hàng: ' + storeCode);
            if (posCode) lines.push('Mã điểm bán: ' + posCode);
            lastCopyText = lines.join('\n');

            lastGeneratedQr = finalImage;
            renderResult(finalImage);
            showToast();

            if (window.innerWidth < 1090) {
                $('html, body').animate({
                    scrollTop: $('.vietqr-preview-panel').offset().top - 16
                }, 300);
            }
        } catch (err) {
            showError(err.message || 'Đã có lỗi xảy ra.');
        } finally {
            $btn.prop('disabled', false);
            $loader.hide();
            $text.text('Tạo mã');
            generateCaptcha();
        }
    }

    function initEvents() {
        $('#vqg-bank-dropdown').on('click', function (e) {
            e.stopPropagation();
            if ($('#vqg-bank-menu').is(':visible')) {
                closeBankDropdown();
            } else {
                openBankDropdown();
            }
        });

        $('#vqg-bank-search').on('input', function () {
            const q = ($(this).val() || '').toLowerCase().trim();
            const filtered = banksData.filter(function (b) {
                return b.shortName.toLowerCase().includes(q) || b.name.toLowerCase().includes(q) || String(b.bin).includes(q);
            });
            renderBanks(filtered);
        });

        $(document).on('click', '.bank-item', function () {
            const bin = String($(this).data('bin'));
            selectedBank = banksData.find(function (b) { return String(b.bin) === bin; }) || null;
            if (!selectedBank) return;

            $('#vqg-bank-id').val(selectedBank.bin);
            $('#vqg-bank-dropdown .selected-text').text(selectedBank.code + ' - ' + selectedBank.name);
            closeBankDropdown();
            hideError();
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.custom-dropdown').length) {
                closeBankDropdown();
            }
        });

        $('#vqg-amount').on('input', function () {
            const cleaned = ($(this).val() || '').replace(/\D/g, '');
            if (!cleaned) {
                $(this).val('');
                return;
            }
            $(this).val(new Intl.NumberFormat('vi-VN').format(Number(cleaned)));
        });

        $('input[data-max]').on('input', function () {
            updateCounter($(this));
        });

        $('#vqg-account-number').on('input', function () {
            $(this).val(($(this).val() || '').replace(/\D/g, '').slice(0, 19));
            updateCounter($(this));
        });

        $('#vqg-account-name').on('input', function () {
            $(this).val(($(this).val() || '').toUpperCase());
            updateCounter($(this));
        });

        $('#vqg-extra-trigger').on('click', function () {
            const $fields = $('#vqg-extra-fields');
            const $arrow = $(this).find('.arrow');
            $fields.toggleClass('show');
            $arrow.css('transform', $fields.hasClass('show') ? 'rotate(180deg)' : 'rotate(0deg)');
        });

        $('#vqg-refresh-captcha').on('click', function () {
            const $btn = $(this);
            $btn.addClass('spin');
            setTimeout(function () { $btn.removeClass('spin'); }, 520);
            generateCaptcha();
        });

        $('#vqg-form').on('submit', submitForm);

        $(document).on('click', '#vqg-btn-copy-image', function () {
            copyQrImage();
        });

        $(document).on('click', '#vqg-btn-copy-text', function () {
            copyTextOnly();
        });

        $(document).on('click', '#vqg-logout-btn', function (e) {
            e.preventDefault();
            handleLogout();
        });
    }

    function initGoogleAuth() {
        const $authBox = $('#vqg-auth-box');
        const $appGrid = $('#vqg-app-grid');
        if (!$authBox.length) return;

        const requireLogin = vietqrVars.requireLogin === '1';
        const isLoggedIn = vietqrVars.isLoggedIn === '1';
        const clientId = vietqrVars.googleClientId || '';

        if (isLoggedIn) {
            $authBox.html('<div class="vqg-auth-status success">✓ Bạn đã đăng nhập hệ thống. <a href="#" id="vqg-logout-btn">Đăng xuất</a></div>');
            $appGrid.show();
            return;
        }

        if (requireLogin) {
            $appGrid.hide();
            if (!clientId) {
                $authBox.html('<div class="vqg-auth-alert danger">⚠️ Yêu cầu đăng nhập Google nhưng hệ thống chưa được cấu hình Google Client ID. Vui lòng liên hệ quản trị viên.</div>');
                return;
            }

            $authBox.html(
                '<div class="vqg-auth-card-block">' +
                    '<h3>🔒 Yêu cầu đăng nhập</h3>' +
                    '<p>Vui lòng đăng nhập bằng tài khoản Google để sử dụng công cụ tạo mã VietQR.</p>' +
                    '<div id="vqg-gsi-btn" class="vqg-gsi-center"></div>' +
                '</div>'
            );
        } else {
            $appGrid.show();
            if (clientId) {
                $authBox.html(
                    '<div class="vqg-auth-alert info">' +
                        '<p style="margin:0 0 8px 0;">Đăng nhập Google (tùy chọn):</p>' +
                        '<div id="vqg-gsi-btn"></div>' +
                    '</div>'
                );
            } else {
                $authBox.empty();
            }
        }

        function renderGsiButton() {
            if (window.google && window.google.accounts && window.google.accounts.id) {
                window.google.accounts.id.initialize({
                    client_id: clientId,
                    callback: handleGoogleLoginResponse
                });

                const btnElem = document.getElementById('vqg-gsi-btn');
                if (btnElem) {
                    window.google.accounts.id.renderButton(btnElem, {
                        theme: 'outline',
                        size: 'large',
                        text: 'signin_with'
                    });
                }
            } else {
                setTimeout(renderGsiButton, 300);
            }
        }

        renderGsiButton();
    }

    async function handleGoogleLoginResponse(response) {
        if (!response || !response.credential) return;

        try {
            const res = await fetch(vietqrVars.restUrl + '/google-login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': vietqrVars.nonce
                },
                body: JSON.stringify({ credential: response.credential })
            });

            const payload = await res.json();
            if (!payload.success) {
                showError(payload.message || 'Đăng nhập Google thất bại.');
                return;
            }

            vietqrVars.isLoggedIn = '1';
            if (payload.nonce) {
                vietqrVars.nonce = payload.nonce;
            }

            initGoogleAuth();
            hideError();
            showToast();
        } catch (err) {
            showError('Lỗi kết nối khi xác thực Google.');
        }
    }

    async function handleLogout() {
        try {
            const res = await fetch(vietqrVars.restUrl + '/logout', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': vietqrVars.nonce
                }
            });

            const payload = await res.json();
            vietqrVars.isLoggedIn = '0';
            if (payload.nonce) {
                vietqrVars.nonce = payload.nonce;
            }

            initGoogleAuth();
        } catch (err) {
            vietqrVars.isLoggedIn = '0';
            initGoogleAuth();
        }
    }

    function init() {
        generateCaptcha();
        initEvents();
        fetchBanks();
        initGoogleAuth();
        $('input[data-max]').each(function () {
            updateCounter($(this));
        });
    }

    init();
});
