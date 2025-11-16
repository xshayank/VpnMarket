import QRCode from 'qrcodejs2';

const qrModal = document.getElementById('qrModal');
const qrCodeContainer = document.getElementById('qrCodeContainer');
const qrStatus = document.getElementById('qrStatus');
const qrTitle = document.getElementById('qrModalTitle');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

const setStatus = (message = '') => {
    if (!qrStatus) return;

    if (message) {
        qrStatus.textContent = message;
        qrStatus.classList.remove('hidden');
    } else {
        qrStatus.textContent = '';
        qrStatus.classList.add('hidden');
    }
};

const logFailure = async (payload) => {
    console.error('QR modal error', payload);

    if (!csrfToken) return;

    try {
        await fetch('/reseller/configs/qr-error', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });
    } catch (error) {
        console.debug('Failed to send QR error log', error);
    }
};

const resetModal = () => {
    setStatus('');
    if (qrCodeContainer) {
        qrCodeContainer.innerHTML = '';
    }
};

if (typeof window !== 'undefined') {
    window.copyToClipboard = (text) => {
        if (!navigator?.clipboard) {
            alert('Clipboard API در دسترس نیست.');
            return;
        }

        navigator.clipboard
            .writeText(text)
            .then(() => alert('لینک سابسکریپشن کپی شد!'))
            .catch((error) => {
                console.error('Failed to copy:', error);
                alert('خطا در کپی کردن لینک.');
            });
    };

    window.showQRCode = (url, title = 'QR Code') => {
        if (!qrModal || !qrCodeContainer) return;

        resetModal();

        try {
            const QRClass = QRCode || window.QRCode;

            if (!QRClass) {
                throw new Error('QRCode library is not available');
            }

            new QRClass(qrCodeContainer, {
                text: url,
                width: 256,
                height: 256,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRClass.CorrectLevel?.H ?? 2,
            });

            if (qrTitle) {
                qrTitle.textContent = title;
            }

            qrModal.classList.remove('hidden');
        } catch (error) {
            const fallbackMessage = 'خطا در تولید QR code — لطفاً مجدداً تلاش کنید.';
            setStatus(fallbackMessage);
            alert(fallbackMessage);
            logFailure({ reason: error?.message ?? 'unknown', url });
        }
    };

    window.closeQRModal = () => {
        if (!qrModal) return;

        qrModal.classList.add('hidden');
        resetModal();
    };

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && qrModal && !qrModal.classList.contains('hidden')) {
            window.closeQRModal();
        }
    });
}
