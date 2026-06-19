<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$revenue = fetchAll("SELECT date, campaign_id, receita_usd, site_name, gam_impressions, gam_ad_requests FROM revenue ORDER BY date DESC, campaign_id LIMIT 50");
$fbClicks = fetchAll("SELECT date, campaign_id, campaign_name, cliques FROM fb_campaigns ORDER BY date DESC LIMIT 20");

echo json_encode([
    'revenue_rows' => $revenue,
    'fb_sample' => $fbClicks,
    'revenue_count' => fetchOne("SELECT COUNT(*) as cnt FROM revenue")['cnt'],
    'total_usd' => fetchOne("SELECT COALESCE(SUM(receita_usd),0) as total FROM revenue")['total'],
    'distinct_campaigns' => fetchAll("SELECT DISTINCT campaign_id FROM revenue ORDER BY campaign_id"),
], JSON_PRETTY_PRINT);
