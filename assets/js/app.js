/**
 * Bússola do Tráfego - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu toggle
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (mobileBtn && sidebar) {
        mobileBtn.addEventListener('click', () => {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        // Close sidebar when clicking overlay
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }

        // Close sidebar when clicking outside (fallback)
        document.addEventListener('click', (e) => {
            if (sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) &&
                !mobileBtn.contains(e.target) &&
                (!sidebarOverlay || !sidebarOverlay.contains(e.target))) {
                closeSidebar();
            }
        });
    }

    // Auto-close alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Table row hover animation
    document.querySelectorAll('table tbody tr').forEach(row => {
        row.style.transition = 'all 0.2s ease';
    });

    // Animate cards on scroll
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.card, .chart-container, .table-container').forEach(el => {
        observer.observe(el);
    });

    // Number counter animation for card values
    document.querySelectorAll('.card-value').forEach(el => {
        const text = el.textContent.trim();
        // Only animate if it's a number
        const match = text.match(/^[R$\s]*(-?[\d.,]+)/);
        if (match) {
            const finalValue = parseFloat(match[1].replace(/\./g, '').replace(',', '.'));
            if (!isNaN(finalValue) && Math.abs(finalValue) > 0) {
                animateValue(el, text, finalValue);
            }
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey) {
            switch (e.key) {
                case '1': e.preventDefault(); window.location = '?page=dashboard'; break;
                case '2': e.preventDefault(); window.location = '?page=campaigns'; break;
                case '3': e.preventDefault(); window.location = '?page=revenue'; break;
                case '4': e.preventDefault(); window.location = '?page=financial'; break;
            }
        }
    });
});

function animateValue(element, originalText, finalValue) {
    const duration = 800;
    const start = performance.now();
    const startValue = 0;

    function tick(now) {
        const elapsed = now - start;
        const progress = Math.min(elapsed / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        const current = startValue + (finalValue - startValue) * eased;

        // Keep original formatting
        const formatted = originalText.replace(
            /(-?[\d.,]+)/,
            current.toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })
        );
        element.textContent = formatted;

        if (progress < 1) {
            requestAnimationFrame(tick);
        } else {
            element.textContent = originalText; // Ensure exact final value
        }
    }

    requestAnimationFrame(tick);
}

// Utility: Format currency
function formatBRL(value) {
    return 'R$ ' + parseFloat(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatUSD(value) {
    return '$ ' + parseFloat(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Utility: Show toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;animation:fadeIn 0.3s ease;';
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// API helper
async function apiCall(url, options = {}) {
    try {
        const res = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', ...options.headers },
            ...options
        });
        return await res.json();
    } catch (e) {
        console.error('API Error:', e);
        return { error: e.message };
    }
}

// ==========================================
// Auto-Sync FB + GAM every 30 minutes
// ==========================================
(function() {
    const SYNC_INTERVAL_MS = 30 * 60 * 1000; // 30 minutes
    const SYNC_COOLDOWN_MS = 10 * 60 * 1000; // 10 min cooldown between syncs
    const LS_KEY = 'bussola_last_auto_sync';

    function getLastSync() {
        const ts = localStorage.getItem(LS_KEY);
        return ts ? parseInt(ts, 10) : 0;
    }

    function setLastSync() {
        localStorage.setItem(LS_KEY, Date.now().toString());
    }

    function timeSinceLastSync() {
        return Date.now() - getLastSync();
    }

    async function autoSync() {
        // Skip if synced recently (across all tabs)
        if (timeSinceLastSync() < SYNC_COOLDOWN_MS) {
            console.log('[AutoSync] Skipped — last sync was', Math.round(timeSinceLastSync() / 60000), 'min ago');
            return;
        }

        console.log('[AutoSync] Starting FB + GAM sync...');
        setLastSync(); // Mark early to prevent other tabs from syncing

        try {
            // Sync FB
            const fbRes = await fetch('api/sync.php?action=sync_fb');
            const fbData = await fbRes.json();

            // Sync GAM
            const gamRes = await fetch('api/sync.php?action=sync_gam');
            const gamData = await gamRes.json();

            const fbMsg = fbData.success ? `FB: ${fbData.imported || 0} reg` : 'FB: erro';
            const gamMsg = gamData.success ? `GAM: ${gamData.imported || 0} reg` : 'GAM: erro';

            console.log(`[AutoSync] Done — ${fbMsg} | ${gamMsg}`);
            showToast(`🔄 Auto-sync: ${fbMsg} | ${gamMsg}`, 'success');

            // Refresh page after short delay if on dashboard
            const page = new URLSearchParams(window.location.search).get('page') || 'dashboard';
            if (page === 'dashboard') {
                setTimeout(() => location.reload(), 3000);
            }
        } catch (e) {
            console.error('[AutoSync] Error:', e);
        }
    }

    // Run first sync after a short delay (check if needed)
    setTimeout(() => {
        if (timeSinceLastSync() >= SYNC_INTERVAL_MS) {
            autoSync();
        }
    }, 5000); // Wait 5s after page load

    // Then schedule every 30 minutes
    setInterval(autoSync, SYNC_INTERVAL_MS);

    console.log('[AutoSync] Initialized — interval: 30min, last sync:', 
        getLastSync() ? new Date(getLastSync()).toLocaleTimeString() : 'never');
})();

// ==========================================
// CSV Export Utilities
// ==========================================

/**
 * Export a single HTML table to CSV and trigger download.
 * @param {string} tableId - The ID of the table element
 * @param {string} filename - Download filename (without extension)
 * @param {object} options - { skipColumns: [indexes], extraHeader: string }
 */
function exportTableToCSV(tableId, filename, options = {}) {
    const table = document.getElementById(tableId);
    if (!table) { showToast('Tabela não encontrada', 'error'); return; }

    const skipCols = options.skipColumns || [];
    const rows = [];

    // Header
    const headers = [];
    table.querySelectorAll('thead th').forEach((th, i) => {
        if (skipCols.includes(i)) return;
        // Get clean text (remove sort arrows, help icons)
        let text = th.textContent.replace(/[↕↑↓?]/g, '').trim();
        headers.push(text);
    });
    rows.push(headers);

    // Body rows (skip empty-state rows)
    table.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.querySelector('.empty-state')) return;
        const cells = [];
        tr.querySelectorAll('td').forEach((td, i) => {
            if (skipCols.includes(i)) return;
            // Prefer raw data-val for numeric cells, else use text content
            let val = td.getAttribute('data-val');
            if (val !== null) {
                cells.push(val);
            } else {
                cells.push(td.textContent.trim().replace(/\s+/g, ' '));
            }
        });
        if (cells.length > 0) rows.push(cells);
    });

    downloadCSV(rows, filename);
}

/**
 * Export all placement tables (grouped by campaign) into a single CSV.
 * @param {string} filename - Download filename (without extension)
 */
function exportPlacementsToCSV(filename) {
    const cards = document.querySelectorAll('.campaign-placement-card');
    if (!cards.length) { showToast('Nenhum dado para exportar', 'error'); return; }

    const rows = [];
    // Build header: Campanha + table headers (skip last column "Fonte")
    const firstTable = cards[0].querySelector('.placementsTable');
    if (!firstTable) return;
    const headers = ['Campanha'];
    firstTable.querySelectorAll('thead th').forEach((th, i) => {
        let text = th.textContent.replace(/[↕↑↓?]/g, '').trim();
        headers.push(text);
    });
    rows.push(headers);

    // Iterate each campaign card
    cards.forEach(card => {
        const campEl = card.querySelector('.campaign-header-name');
        const campName = campEl ? campEl.textContent.trim() : 'N/A';
        const table = card.querySelector('.placementsTable');
        if (!table) return;

        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [campName];
            tr.querySelectorAll('td').forEach((td, i) => {
                let val = td.getAttribute('data-val');
                if (val !== null) {
                    cells.push(val);
                } else {
                    cells.push(td.textContent.trim().replace(/\s+/g, ' '));
                }
            });
            rows.push(cells);
        });
    });

    downloadCSV(rows, filename);
}

/**
 * Convert rows array to CSV string and trigger download.
 */
function downloadCSV(rows, filename) {
    const csvContent = rows.map(row =>
        row.map(cell => {
            let c = String(cell);
            // Escape quotes and wrap in quotes if contains comma, quote, or newline
            if (c.includes('"') || c.includes(',') || c.includes('\n') || c.includes(';')) {
                c = '"' + c.replace(/"/g, '""') + '"';
            }
            return c;
        }).join(';') // Use semicolon for Brazilian Excel compatibility
    ).join('\r\n');

    // BOM for UTF-8 Excel compatibility
    const bom = '\uFEFF';
    const blob = new Blob([bom + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', (filename || 'export') + '.csv');
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    showToast('📥 Planilha exportada com sucesso!', 'success');
}
