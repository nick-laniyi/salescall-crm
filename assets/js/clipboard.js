/**
 * Clipboard helper for copying email addresses
 */
function copyToClipboard(text) {
    if (!text || text === '—') return;
    
    // Use modern clipboard API if available
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Email copied to clipboard', 'success');
        }).catch(err => {
            console.error('Clipboard error:', err);
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showNotification('Email copied to clipboard', 'success');
    } catch (err) {
        console.error('Fallback copy error:', err);
        showNotification('Failed to copy email', 'error');
    }
    document.body.removeChild(textarea);
}

// Add event listeners to email icons
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.copy-email').forEach(icon => {
        icon.addEventListener('click', function(e) {
            e.preventDefault();
            const email = this.dataset.email;
            copyToClipboard(email);
        });
    });
});