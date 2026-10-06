document.addEventListener('DOMContentLoaded', function () {
    const toggleButton = document.querySelector('.sidebar-toggle');

    if (toggleButton) {
        toggleButton.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-open');
        });
    }

    const yearNode = document.getElementById('current-year');
    if (yearNode) {
        yearNode.textContent = new Date().getFullYear();
    }

    const modalOpenButtons = document.querySelectorAll('[data-open-modal]');
    modalOpenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const modalId = button.getAttribute('data-open-modal');
            if (modalId) {
                openModal(modalId);
            }
        });
    });

    const modalEditButtons = document.querySelectorAll('[data-open-edit]');
    modalEditButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            const modalKey = button.getAttribute('data-open-edit');
            const currentUrl = new URL(window.location.href);
            const id = button.getAttribute('data-customer-id') || button.getAttribute('data-package-id') || button.getAttribute('data-subscription-id') || button.getAttribute('data-user-id');

            if (!id || !modalKey) {
                return;
            }

            currentUrl.searchParams.set('edit_id', id);
            window.location.href = currentUrl.toString();
        });
    });

    const modalCloseButtons = document.querySelectorAll('[data-close-modal]');
    modalCloseButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const modalId = button.getAttribute('data-close-modal');
            if (modalId) {
                closeModal(modalId);
            }
        });
    });

    document.querySelectorAll('.modal-close').forEach(function (button) {
        button.addEventListener('click', function () {
            const modalId = button.getAttribute('data-close-modal');
            if (modalId) {
                closeModal(modalId);
            }
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(modal.id);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.is-open').forEach(function (modal) {
                closeModal(modal.id);
            });
        }
    });

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const submitButton = form.querySelector('[data-submit-button]');
            if (submitButton) {
                submitButton.disabled = true;
                const originalText = submitButton.textContent.trim();
                submitButton.dataset.originalText = originalText;
                submitButton.textContent = 'Menyimpan...';
            }
        });
    });

    if (window.__modalToOpen) {
        openModal(window.__modalToOpen);
    }
});

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) {
        return;
    }

    modal.classList.add('is-open');
    document.body.classList.add('modal-open');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    if (!document.querySelector('.modal-overlay.is-open')) {
        document.body.classList.remove('modal-open');
    }
}
