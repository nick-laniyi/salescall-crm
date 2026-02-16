/**
 * Sales Calls CRM - Common JavaScript functions
 * Designed by Nicholas Olaniyi (https://naijabased.fun/nick-laniyi)
 */

// Show a temporary notification
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '200px';
    notification.innerText = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Generic AJAX error handler
function handleAjaxError(error) {
    console.error('AJAX error:', error);
    showNotification('Network error occurred', 'error');
}

// You can add more global functions here