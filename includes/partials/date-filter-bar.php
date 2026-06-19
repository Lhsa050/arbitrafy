<?php
/**
 * Date Filter Bar Component
 * 
 * Renders preset buttons (Hoje, Ontem, 7 Dias, Período) + custom date inputs.
 * 
 * Required variables before include:
 *   $datePreset  — current active preset
 *   $dateFrom    — current date_from
 *   $dateTo      — current date_to
 *   $currentPage — current page name (e.g. 'dashboard', 'campaigns')
 * 
 * Optional:
 *   $dateFilterExtra — extra HTML to append after the date buttons (e.g. account select, sync button)
 */

$currentPage = $currentPage ?? ($_GET['page'] ?? 'dashboard');
$presetLabel = datePresetLabel($datePreset, $dateFrom, $dateTo);
?>

<div class="date-filter-bar">
    <div class="date-presets">
        <button type="button" class="date-preset-btn <?= $datePreset === 'today' ? 'active' : '' ?>" onclick="applyDatePreset('today')">Hoje</button>
        <button type="button" class="date-preset-btn <?= $datePreset === 'yesterday' ? 'active' : '' ?>" onclick="applyDatePreset('yesterday')">Ontem</button>
        <button type="button" class="date-preset-btn <?= $datePreset === 'last7' ? 'active' : '' ?>" onclick="applyDatePreset('last7')">Últimos 7 dias</button>
        <button type="button" class="date-preset-btn <?= $datePreset === 'custom' ? 'active' : '' ?>" onclick="toggleCustomDates()" id="btnCustomDate">Período personalizado</button>
    </div>

    <div class="date-custom-range <?= $datePreset === 'custom' ? 'open' : '' ?>" id="customDateRange">
        <input type="date" id="dfDateFrom" class="filter-input" value="<?= $dateFrom ?>">
        <span style="color:var(--text-muted);font-size:12px;">→</span>
        <input type="date" id="dfDateTo" class="filter-input" value="<?= $dateTo ?>">
        <button type="button" class="btn btn-primary btn-sm" onclick="applyCustomDates()">Aplicar</button>
    </div>

    <?php if (!empty($dateFilterExtra)): ?>
    <div class="date-filter-extra">
        <?= $dateFilterExtra ?>
    </div>
    <?php endif; ?>
</div>

<div class="date-active-label">
    📅 <strong><?= $presetLabel ?></strong>
</div>

<script>
(function() {
    const currentPage = '<?= $currentPage ?>';
    
    // Preserve existing query params except date-related ones
    function getBaseParams() {
        const params = new URLSearchParams(window.location.search);
        params.delete('date_preset');
        params.delete('date_from');
        params.delete('date_to');
        params.set('page', currentPage);
        return params;
    }
    
    window.applyDatePreset = function(preset) {
        const params = getBaseParams();
        params.set('date_preset', preset);
        window.location.href = '?' + params.toString();
    };
    
    window.toggleCustomDates = function() {
        const el = document.getElementById('customDateRange');
        el.classList.toggle('open');
    };
    
    window.applyCustomDates = function() {
        const from = document.getElementById('dfDateFrom').value;
        const to = document.getElementById('dfDateTo').value;
        if (!from || !to) return alert('Selecione as duas datas.');
        
        const params = getBaseParams();
        params.set('date_preset', 'custom');
        params.set('date_from', from);
        params.set('date_to', to);
        window.location.href = '?' + params.toString();
    };
})();
</script>
