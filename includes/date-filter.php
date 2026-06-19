<?php
/**
 * Date Filter Presets Helper
 * 
 * Resolves date presets (today, yesterday, last7, custom) into date_from/date_to.
 * Include this file before accessing $dateFrom / $dateTo in any page.
 * 
 * Usage:
 *   list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
 */

function resolveDateFilter(): array {
    $preset = $_GET['date_preset'] ?? '';
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    switch ($preset) {
        case 'today':
            return [$today, $today, 'today'];
        case 'yesterday':
            return [$yesterday, $yesterday, 'yesterday'];
        case 'last7':
            return [date('Y-m-d', strtotime('-6 days')), $today, 'last7'];
        case 'last30':
            return [date('Y-m-d', strtotime('-29 days')), $today, 'last30'];
        case 'this_month':
            return [date('Y-m-01'), $today, 'this_month'];
        case 'custom':
            $from = $_GET['date_from'] ?? $today;
            $to = $_GET['date_to'] ?? $today;
            return [$from, $to, 'custom'];
        default:
            // Backwards compatibility: if date_from/date_to are set directly
            if (!empty($_GET['date_from']) || !empty($_GET['date_to'])) {
                return [
                    $_GET['date_from'] ?? $today,
                    $_GET['date_to'] ?? $today,
                    'custom'
                ];
            }
            // Default: today
            return [$today, $today, 'today'];
    }
}

/**
 * Get a human-readable label for the active preset
 */
function datePresetLabel(string $preset, string $dateFrom, string $dateTo): string {
    return match ($preset) {
        'today' => 'Hoje (' . formatDate($dateFrom) . ')',
        'yesterday' => 'Ontem (' . formatDate($dateFrom) . ')',
        'last7' => 'Últimos 7 dias (' . formatDate($dateFrom) . ' → ' . formatDate($dateTo) . ')',
        'last30' => 'Últimos 30 dias (' . formatDate($dateFrom) . ' → ' . formatDate($dateTo) . ')',
        'this_month' => 'Este mês (' . formatDate($dateFrom) . ' → ' . formatDate($dateTo) . ')',
        'custom' => 'Período: ' . formatDate($dateFrom) . ' → ' . formatDate($dateTo),
        default => formatDate($dateFrom) . ' → ' . formatDate($dateTo),
    };
}
