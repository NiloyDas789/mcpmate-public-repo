<?php

namespace McpMate\Views;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Renders the root HTML container that the React SPA mounts into.
 *
 * React mounts into #mcpmate-app, replacing the inline loading screen.
 * Dialogs, drawers, menus and the live regions render into
 * #mcpmate-portal, its sibling, so the root's scoped styles and tokens
 * reach them, and the app can be made inert while a dialog is open.
 */
final class Body
{
    public function render(): void
    {
        $title = esc_html(Config::title());

        $html = '<noscript>You need to enable JavaScript to run this app.</noscript>';
        $html .= '<div id="mcpmate-root">';
        $html .= '<div id="mcpmate-app">';
        $html .= '<div style="display:flex;flex-direction:column;justify-content:center;align-items:center;height:90vh;font-family:\'Inter\',sans-serif;background:#F4F6FB;">';
        $html .= '<div style="padding:40px;background:white;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.1);text-align:center;">';
        $html .= '<h1 style="color:#324376;margin:0 0 16px 0;">' . $title . '</h1>';
        $html .= '<p style="color:#666;margin:0 0 20px 0;">Loading the App...</p>';
        $html .= '<div style="width:40px;height:40px;border:4px solid #E3E7F3;border-top-color:#586BA4;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>';
        $html .= '<p style="color:#999;font-size:12px;margin-top:16px;">If this persists, check browser console for errors</p>';
        $html .= '</div></div>';
        $html .= '</div>';
        $html .= '<div id="mcpmate-portal"></div>';
        $html .= '</div>';

        echo wp_kses($html, [
            'noscript' => [],
            'div'      => ['id' => [], 'style' => []],
            'h1'       => ['style' => []],
            'p'        => ['style' => []],
        ]);
    }
}
