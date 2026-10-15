<?php
/**
 * Plugin Name: Kashiwazaki SEO Recommended Ranking
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 投稿・固定ページ・カスタム投稿に、おすすめランキングを挿入できるプラグインです。ランキングの一元管理と、本文上下への自動挿入・ショートコードによる手動挿入に対応します。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kashiwazaki-seo-recommended-ranking
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('KASHIWAZAKI_SEO_RECOMMENDED_RANKING_VERSION', '1.0.0');
define('KASHIWAZAKI_SEO_RECOMMENDED_RANKING_FILE', __FILE__);
define('KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR', plugin_dir_path(__FILE__));
define('KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL', plugin_dir_url(__FILE__));
define('KASHIWAZAKI_SEO_RECOMMENDED_RANKING_BASENAME', plugin_basename(__FILE__));

/**
 * メインプラグインクラス
 */
final class Kashiwazaki_SEO_Recommended_Ranking {

    /**
     * シングルトンインスタンス
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * 全体設定
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Settings
     */
    public Kashiwazaki_SEO_Recommended_Ranking_Settings $settings;

    /**
     * ランキング（投稿タイプ）
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Rankings
     */
    public Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings;

    /**
     * 構造化データ
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Schema
     */
    public Kashiwazaki_SEO_Recommended_Ranking_Schema $schema;

    /**
     * 表示（HTML の組み立て）
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Renderer
     */
    public Kashiwazaki_SEO_Recommended_Ranking_Renderer $renderer;

    /**
     * 公開ページへの差し込み・ショートコード
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Frontend
     */
    public Kashiwazaki_SEO_Recommended_Ranking_Frontend $frontend;

    /**
     * ランキングの編集画面
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Ranking_Editor|null
     */
    public ?Kashiwazaki_SEO_Recommended_Ranking_Ranking_Editor $ranking_editor = null;

    /**
     * 投稿の編集画面のボックス
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Post_Meta_Box|null
     */
    public ?Kashiwazaki_SEO_Recommended_Ranking_Post_Meta_Box $post_meta_box = null;

    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * 依存ファイルを読み込み
     */
    private function load_dependencies(): void {
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-settings.php';
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-rankings.php';
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-schema.php';
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-renderer.php';
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-frontend.php';
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-ranking-editor.php';
        require_once KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . 'includes/class-post-meta-box.php';
    }

    /**
     * コンポーネントを初期化
     */
    private function init_components(): void {
        $this->settings = new Kashiwazaki_SEO_Recommended_Ranking_Settings();
        $this->rankings = new Kashiwazaki_SEO_Recommended_Ranking_Rankings($this->settings);
        $this->schema   = new Kashiwazaki_SEO_Recommended_Ranking_Schema($this->settings);
        $this->renderer = new Kashiwazaki_SEO_Recommended_Ranking_Renderer($this->settings, $this->rankings, $this->schema);
        $this->frontend = new Kashiwazaki_SEO_Recommended_Ranking_Frontend($this->settings, $this->rankings, $this->renderer, $this->schema);

        if (is_admin()) {
            $this->ranking_editor = new Kashiwazaki_SEO_Recommended_Ranking_Ranking_Editor($this->settings, $this->rankings, $this->renderer, $this->schema);
            $this->post_meta_box  = new Kashiwazaki_SEO_Recommended_Ranking_Post_Meta_Box($this->settings, $this->rankings);
        }
    }

    /**
     * フックを登録
     */
    private function register_hooks(): void {
        // プラグイン一覧に「設定」リンク
        add_filter(
            'plugin_action_links_' . KASHIWAZAKI_SEO_RECOMMENDED_RANKING_BASENAME,
            [$this, 'add_action_links']
        );

        // 投稿タイプの名前などより先に翻訳を読み込む
        add_action('init', [$this, 'load_textdomain'], 0);
    }

    /**
     * CSS/JS の版（プラグインの版＋ファイルの更新時刻）。
     * 版が同じままだとブラウザや配信の中継が古いファイルを使い続けるため、ファイルを更新したら URL が変わるようにする。
     *
     * @param string $rel プラグインフォルダからの相対パス
     * @return string
     */
    public static function asset_ver(string $rel): string {
        $path = KASHIWAZAKI_SEO_RECOMMENDED_RANKING_DIR . $rel;
        $time = file_exists($path) ? (int) filemtime($path) : 0;
        return KASHIWAZAKI_SEO_RECOMMENDED_RANKING_VERSION . ($time > 0 ? '.' . $time : '');
    }

    /**
     * プラグイン一覧に「設定」「ランキング一覧」リンクを追加
     *
     * @param array $links 既存のアクションリンク
     * @return array
     */
    public function add_action_links($links): array {
        $links = is_array($links) ? $links : [];
        $own   = [];
        if (current_user_can('manage_options')) {
            $own[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(Kashiwazaki_SEO_Recommended_Ranking_Settings::page_url()),
                esc_html__('設定', 'kashiwazaki-seo-recommended-ranking')
            );
        }
        $own[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('edit.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE)),
            esc_html__('ランキング一覧', 'kashiwazaki-seo-recommended-ranking')
        );
        return array_merge($own, $links);
    }

    /**
     * 翻訳ファイルを読み込み
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'kashiwazaki-seo-recommended-ranking',
            false,
            dirname(KASHIWAZAKI_SEO_RECOMMENDED_RANKING_BASENAME) . '/languages'
        );
    }
}

/**
 * プラグインインスタンスを取得
 *
 * @return Kashiwazaki_SEO_Recommended_Ranking
 */
function kashiwazaki_seo_recommended_ranking(): Kashiwazaki_SEO_Recommended_Ranking {
    return Kashiwazaki_SEO_Recommended_Ranking::get_instance();
}

kashiwazaki_seo_recommended_ranking();
