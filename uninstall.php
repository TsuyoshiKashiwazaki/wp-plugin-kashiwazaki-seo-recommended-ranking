<?php
/**
 * プラグインを削除するときの処理
 *
 * 設定画面で「削除するときに、すべてのランキング・各ページの選択・この設定も削除する」を選んだサイトだけ、データを消す。
 * 選んでいないときは何も消さない（入れ直すと元どおりに使える）。
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

// アンインストール以外からのアクセスを禁止
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * 1つのサイトのデータを消す（消す設定のときだけ）
 */
function kashiwazaki_seo_recommended_ranking_uninstall_site() {
    global $wpdb;

    $settings = get_option('kashiwazaki_seo_recommended_ranking_settings');
    if (!is_array($settings) || empty($settings['delete_on_uninstall'])) {
        return;
    }

    // ランキング（投稿タイプ）とそのメタ
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ids = $wpdb->get_col(
        $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'kashiwazaki_ranking')
    );
    foreach ((array) $ids as $id) {
        wp_delete_post((int) $id, true);
    }

    // 各ページで選んだランキング・表示位置
    delete_post_meta_by_key('_kashiwazaki_seo_recommended_ranking_ranking_id');
    delete_post_meta_by_key('_kashiwazaki_seo_recommended_ranking_position');

    // 全体設定
    delete_option('kashiwazaki_seo_recommended_ranking_settings');
}

if (is_multisite()) {
    $kashiwazaki_seo_recommended_ranking_sites = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($kashiwazaki_seo_recommended_ranking_sites as $kashiwazaki_seo_recommended_ranking_site_id) {
        switch_to_blog((int) $kashiwazaki_seo_recommended_ranking_site_id);
        kashiwazaki_seo_recommended_ranking_uninstall_site();
        restore_current_blog();
    }
} else {
    kashiwazaki_seo_recommended_ranking_uninstall_site();
}
