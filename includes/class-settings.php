<?php
/**
 * 全体設定（オプションの読み書きと設定画面）
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 全体設定クラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Settings {

    /**
     * オプション名
     */
    public const OPTION_NAME = 'kashiwazaki_seo_recommended_ranking_settings';

    /**
     * 設定画面のスラッグ
     */
    public const PAGE_SLUG = 'kashiwazaki-seo-recommended-ranking-settings';

    /**
     * 設定のグループ名（Settings API）
     */
    private const GROUP = 'kashiwazaki_seo_recommended_ranking_settings_group';

    /**
     * 既定のアクセントカラー
     */
    public const DEFAULT_ACCENT = '#1f5fbf';

    /**
     * 読み込んだ設定（リクエスト中のキャッシュ）
     *
     * @var array|null
     */
    private ?array $cache = null;

    /**
     * コンストラクタ
     */
    public function __construct() {
        add_action('admin_init', [$this, 'register_setting']);
        add_action('admin_menu', [$this, 'add_menu'], 20);
        // 左のメニューは 1 項目だけにし、一覧と設定は画面の上のタブで行き来する（公式: admin_menu の遅い優先度で外す。外すのは新規作成だけ）
        add_action('admin_menu', [$this, 'tidy_menu'], 110);
        add_filter('parent_file', [$this, 'parent_file']);
        add_filter('views_edit-' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE, [$this, 'list_tabs']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('update_option_' . self::OPTION_NAME, [$this, 'flush_cache']);
        add_action('add_option_' . self::OPTION_NAME, [$this, 'flush_cache']);
    }

    /**
     * 設定画面の URL
     *
     * @return string
     */
    public static function page_url(): string {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    /**
     * 既定値
     *
     * @return array
     */
    public function get_defaults(): array {
        return [
            'post_types'          => array_keys($this->get_selectable_post_types()),
            'button_label'        => __('詳しく見る', 'kashiwazaki-seo-recommended-ranking'),
            'design'              => 'standard',
            'accent_color'        => self::DEFAULT_ACCENT,
            'medal_colors'        => true,
            'schema_enabled'      => true,
            'schema_author'       => 'post_author',
            'delete_on_uninstall' => false,
        ];
    }

    /**
     * デザインの型の表示名と説明
     *
     * @return array<string,array{0:string,1:string}> 型 => [表示名, 説明]
     */
    public static function design_choices(): array {
        return [
            'standard' => [__('スタンダード', 'kashiwazaki-seo-recommended-ranking'), __('順位の丸・名称・説明・ボタンを 1 枚ずつのカードに。どんな記事にもなじむ基本の形', 'kashiwazaki-seo-recommended-ranking')],
            'crown'    => [__('王冠', 'kashiwazaki-seo-recommended-ranking'), __('1位〜3位に王冠と金・銀・銅の帯。おすすめ記事・比較記事で定番の形', 'kashiwazaki-seo-recommended-ranking')],
            'minimal'  => [__('シンプル', 'kashiwazaki-seo-recommended-ranking'), __('大きな順位の数字と罫線だけ。雑誌のような落ち着いた形', 'kashiwazaki-seo-recommended-ranking')],
            'table'    => [__('比較表', 'kashiwazaki-seo-recommended-ranking'), __('1 行に 1 つずつの表の形。順位が多いときに見比べやすい', 'kashiwazaki-seo-recommended-ranking')],
            'podium'   => [__('上位3つを大きく', 'kashiwazaki-seo-recommended-ranking'), __('1位を大きく、2位・3位を並べ、4位以下は小さな行に。上位を強く勧めたいとき', 'kashiwazaki-seo-recommended-ranking')],
        ];
    }

    /**
     * 選べる投稿タイプ（公開されている投稿タイプ。添付ファイルとランキング自身を除く）
     *
     * @return array<string,string> 投稿タイプ => 表示名
     */
    public function get_selectable_post_types(): array {
        $types = get_post_types(['public' => true], 'objects');
        $out   = [];
        foreach ($types as $name => $obj) {
            if ($name === 'attachment' || $name === Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE) {
                continue;
            }
            $out[$name] = $obj->labels->singular_name ?? $obj->label ?? $name;
        }
        return $out;
    }

    /**
     * 設定を取得（既定値とマージ）
     *
     * @return array
     */
    public function get(): array {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $saved    = get_option(self::OPTION_NAME, []);
        $saved    = is_array($saved) ? $saved : [];
        $defaults = $this->get_defaults();
        $merged   = array_merge($defaults, array_intersect_key($saved, $defaults));
        // 型を揃える（壊れた値・古い値でも動くように）
        $merged['post_types']          = is_array($merged['post_types']) ? array_values(array_filter(array_map('strval', $merged['post_types']))) : $defaults['post_types'];
        $merged['button_label']        = is_string($merged['button_label']) ? $merged['button_label'] : $defaults['button_label'];
        $merged['design']              = in_array($merged['design'], Kashiwazaki_SEO_Recommended_Ranking_Rankings::DESIGNS, true) ? $merged['design'] : $defaults['design'];
        $merged['accent_color']        = sanitize_hex_color((string) $merged['accent_color']) ?: self::DEFAULT_ACCENT;
        $merged['medal_colors']        = (bool) $merged['medal_colors'];
        $merged['schema_enabled']      = (bool) $merged['schema_enabled'];
        $merged['schema_author']       = in_array($merged['schema_author'], ['post_author', 'site', 'none'], true) ? $merged['schema_author'] : 'post_author';
        $merged['delete_on_uninstall'] = (bool) $merged['delete_on_uninstall'];
        // 投稿タイプが出そろう init より前は、既定値（選べる投稿タイプ）が欠けるため覚えない
        if (did_action('init')) {
            $this->cache = $merged;
        }
        return $merged;
    }

    /**
     * 設定値を1つ取得
     *
     * @param string $key キー
     * @return mixed
     */
    public function value(string $key) {
        $all = $this->get();
        return $all[$key] ?? null;
    }

    /**
     * 投稿タイプでランキングの選択ボックスと自動挿入を使うか
     *
     * @param string $post_type 投稿タイプ
     * @return bool
     */
    public function is_enabled_post_type(string $post_type): bool {
        if ($post_type === '' || $post_type === Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE || $post_type === 'attachment') {
            return false;
        }
        return in_array($post_type, $this->get()['post_types'], true);
    }

    /**
     * リクエスト中のキャッシュを捨てる
     */
    public function flush_cache(): void {
        $this->cache = null;
    }

    /**
     * Settings API に登録
     */
    public function register_setting(): void {
        register_setting(self::GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => [],
            'show_in_rest'      => false,
        ]);
    }

    /**
     * 保存値の検証
     *
     * @param mixed $input 入力
     * @return array
     */
    public function sanitize($input): array {
        // options.php が wp_unslash 済みの値を渡すため、ここでは外さない（2回外すと「\」が消える）
        $input    = is_array($input) ? $input : [];
        $defaults = $this->get_defaults();
        $out      = [];

        $selectable = array_keys($this->get_selectable_post_types());
        $types      = isset($input['post_types']) && is_array($input['post_types']) ? array_map('sanitize_key', $input['post_types']) : [];
        $out['post_types'] = array_values(array_intersect($selectable, $types));

        // ランキングごとの文言と同じ整形（sanitize_text_field は「%20」のような「% と 16 進数 2 桁」を消す）。
        // 切った位置が空白でも、保存し直して同じ値になるよう最後に trim する
        $label = isset($input['button_label']) && is_string($input['button_label']) ? Kashiwazaki_SEO_Recommended_Ranking_Rankings::clean_text($input['button_label']) : '';
        $label = trim(Kashiwazaki_SEO_Recommended_Ranking_Rankings::cut($label, Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_BUTTON));
        $out['button_label'] = $label !== '' ? $label : $defaults['button_label'];

        $design        = isset($input['design']) && is_string($input['design']) ? $input['design'] : '';
        $out['design'] = in_array($design, Kashiwazaki_SEO_Recommended_Ranking_Rankings::DESIGNS, true) ? $design : $defaults['design'];

        $color = isset($input['accent_color']) && is_string($input['accent_color']) ? sanitize_hex_color($input['accent_color']) : '';
        $out['accent_color'] = $color ?: self::DEFAULT_ACCENT;

        $out['medal_colors']        = !empty($input['medal_colors']);
        $out['schema_enabled']      = !empty($input['schema_enabled']);
        $author                     = isset($input['schema_author']) && is_string($input['schema_author']) ? $input['schema_author'] : '';
        $out['schema_author']       = in_array($author, ['post_author', 'site', 'none'], true) ? $author : 'post_author';
        $out['delete_on_uninstall'] = !empty($input['delete_on_uninstall']);

        $this->flush_cache();
        return $out;
    }

    /**
     * 設定画面を登録する（左のメニューには出さず、画面の上のタブから開く。親を空文字にするとメニューに出ない画面になる）
     */
    public function add_menu(): void {
        $hook = add_submenu_page(
            '',
            __('Kashiwazaki SEO Recommended Ranking 設定', 'kashiwazaki-seo-recommended-ranking'),
            __('設定', 'kashiwazaki-seo-recommended-ranking'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
        if ($hook) {
            // メニューに出さない画面は題名が空になるので、ブラウザのタブの題名を入れる
            add_action('load-' . $hook, static function (): void {
                global $title;
                $title = __('Kashiwazaki SEO Recommended Ranking 設定', 'kashiwazaki-seo-recommended-ranking');
            });
        }
    }

    /**
     * 左のメニューのサブメニューから新規作成を外し、メニューを 1 項目にする（新規作成の画面は一覧の見出しの横のボタンから開ける）。
     * ランキング一覧の項目は外さない。サブメニューがそれだけになると、WordPress がサブメニューを消すので 1 項目になる
     * （wp-admin/includes/menu.php: 項目が 1 つで行き先が親と同じなら消す）。ほかのプラグインが項目（並べ替え・分類など）を
     * 足したときは一覧が先頭に残り、左のメニューを押すと一覧が開く（外すと、残ったほかの項目が親のリンク先になる）
     */
    public function tidy_menu(): void {
        $parent = 'edit.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE;
        remove_submenu_page($parent, 'post-new.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE);
        // 設定画面（メニューに出さない画面）も一覧から外す。残すと、メニューを描く直前の get_admin_page_parent() が
        // 選択中のメニューを空に戻し、設定画面で左のメニューが選択中にならない。入れるかどうかは登録済みの画面の記録で決まる
        remove_submenu_page('', self::PAGE_SLUG);
    }

    /**
     * 設定画面を開いているときも、左のメニューの「Kashiwazaki SEO Recommended Ranking」を選択中にする
     *
     * @param string $parent_file 親のメニュー
     * @return string
     */
    public function parent_file($parent_file) {
        global $plugin_page;
        if ($plugin_page === self::PAGE_SLUG) {
            return 'edit.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE;
        }
        return $parent_file;
    }

    /**
     * 画面の上のタブ（ランキング一覧・設定）。一覧の画面と設定の画面で同じものを出す
     *
     * @param string $current 'list' か 'settings'
     */
    public static function render_tabs(string $current): void {
        $tabs = [];
        if (current_user_can('edit_posts')) {
            $tabs['list'] = [__('ランキング一覧', 'kashiwazaki-seo-recommended-ranking'), admin_url('edit.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE)];
        }
        if (current_user_can('manage_options')) {
            $tabs['settings'] = [__('設定', 'kashiwazaki-seo-recommended-ranking'), self::page_url()];
        }
        if (empty($tabs)) {
            return;
        }
        echo '<nav class="nav-tab-wrapper kashiwazaki-seo-recommended-ranking-tabs" aria-label="' . esc_attr__('Kashiwazaki SEO Recommended Ranking の画面', 'kashiwazaki-seo-recommended-ranking') . '">';
        foreach ($tabs as $key => [$label, $url]) {
            $active = $key === $current;
            printf(
                '<a href="%s" class="nav-tab%s"%s>%s</a>',
                esc_url($url),
                $active ? ' nav-tab-active' : '',
                $active ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    /**
     * ランキング一覧の画面に、見出しのすぐ下（「すべて・公開済み」などの切り替えの上）にタブを出す
     *
     * @param mixed $views 一覧の表示の切り替え
     * @return mixed そのまま返す
     */
    public function list_tabs($views) {
        static $done = false;
        if (!$done) {
            $done = true;
            self::render_tabs('list');
        }
        return $views;
    }

    /**
     * 設定画面の「見本」（公開ページと同じ HTML・CSS で、見本の内容を表示する。型・色はスクリプトでその場で切り替える）
     */
    private function render_design_sample(): void {
        $data = Kashiwazaki_SEO_Recommended_Ranking_Rankings::normalize([
            'heading_tag' => 'none',
            'description' => __('見本のランキングです。実際の文字の大きさ・フォントは、テーマによって変わります。', 'kashiwazaki-seo-recommended-ranking'),
            'items'       => [
                ['name' => __('見本の商品 A', 'kashiwazaki-seo-recommended-ranking'), 'description' => __('いちばんのおすすめ。使いやすさと価格のバランスが良い。', 'kashiwazaki-seo-recommended-ranking'), 'url' => home_url('/')],
                ['name' => __('見本の商品 B', 'kashiwazaki-seo-recommended-ranking'), 'description' => __('機能が多く、慣れた人に向く。', 'kashiwazaki-seo-recommended-ranking'), 'url' => home_url('/')],
                ['name' => __('見本の商品 C', 'kashiwazaki-seo-recommended-ranking'), 'description' => __('無料で始められる。まず試したい人に。', 'kashiwazaki-seo-recommended-ranking'), 'url' => home_url('/')],
                ['name' => __('見本の商品 D', 'kashiwazaki-seo-recommended-ranking'), 'description' => __('特定の用途に強い。', 'kashiwazaki-seo-recommended-ranking'), 'url' => home_url('/')],
            ],
        ]);
        $renderer = Kashiwazaki_SEO_Recommended_Ranking::get_instance()->renderer;
        ?>
        <div class="kashiwazaki-seo-recommended-ranking-preview kashiwazaki-seo-recommended-ranking-design-sample">
            <div class="kashiwazaki-seo-recommended-ranking-preview__toolbar" role="group" aria-label="<?php esc_attr_e('見本の幅', 'kashiwazaki-seo-recommended-ranking'); ?>">
                <button type="button" class="button kashiwazaki-seo-recommended-ranking-preview__width is-active" data-width="pc" aria-pressed="true"><?php esc_html_e('PC', 'kashiwazaki-seo-recommended-ranking'); ?></button>
                <button type="button" class="button kashiwazaki-seo-recommended-ranking-preview__width" data-width="sp" aria-pressed="false"><?php esc_html_e('スマホ', 'kashiwazaki-seo-recommended-ranking'); ?></button>
            </div>
            <div class="kashiwazaki-seo-recommended-ranking-preview__frame" data-width="pc">
                <div class="kashiwazaki-seo-recommended-ranking-preview__canvas">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_data がエスケープ済み
                    echo $renderer->render_data($data, 0, '', 'kashiwazaki-seo-recommended-ranking-design-sample');
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 設定画面のスクリプト（カラーピッカー）
     *
     * @param string $hook_suffix 画面
     */
    public function enqueue_assets($hook_suffix): void {
        if (!is_string($hook_suffix) || strpos($hook_suffix, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        // 見本は公開ページと同じスタイルで見せる
        Kashiwazaki_SEO_Recommended_Ranking_Renderer::register_style();
        wp_enqueue_style(Kashiwazaki_SEO_Recommended_Ranking_Renderer::STYLE_HANDLE);
        wp_enqueue_script(
            'kashiwazaki-seo-recommended-ranking-settings',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/js/admin-settings.js',
            ['jquery', 'wp-color-picker'],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/js/admin-settings.js'),
            true
        );
        wp_enqueue_style(
            'kashiwazaki-seo-recommended-ranking-admin',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/css/admin.css',
            [],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/css/admin.css')
        );
    }

    /**
     * 設定画面
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('この画面を表示する権限がありません。', 'kashiwazaki-seo-recommended-ranking'));
        }
        $s     = $this->get();
        $name  = self::OPTION_NAME;
        $types = $this->get_selectable_post_types();
        ?>
        <div class="wrap kashiwazaki-seo-recommended-ranking-settings">
            <h1 class="wp-heading-inline"><?php esc_html_e('Kashiwazaki SEO Recommended Ranking', 'kashiwazaki-seo-recommended-ranking'); ?></h1>
            <?php
            $type = get_post_type_object(Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE);
            if ($type && current_user_can($type->cap->create_posts)) :
                ?>
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE)); ?>" class="page-title-action"><?php echo esc_html($type->labels->add_new_item); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">
            <?php self::render_tabs('settings'); ?>
            <?php settings_errors(); ?>

            <div class="kashiwazaki-seo-recommended-ranking-howto">
                <h2><?php esc_html_e('使い方', 'kashiwazaki-seo-recommended-ranking'); ?></h2>
                <ol>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: link to the new ranking screen */
                            esc_html__('「%s」で、見出し・概要説明・各順位の名称・説明・URL を入力して公開します（3位から10位まで）。', 'kashiwazaki-seo-recommended-ranking'),
                            '<a href="' . esc_url(admin_url('post-new.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE)) . '">' . esc_html__('新規作成', 'kashiwazaki-seo-recommended-ranking') . '</a>'
                        );
                        ?>
                    </li>
                    <li><?php esc_html_e('投稿・固定ページ・カスタム投稿の編集画面にある「Kashiwazaki SEO Recommended Ranking」のボックスで、表示するランキングと表示位置（本文の上・本文の下・手動・表示しない）を選びます。', 'kashiwazaki-seo-recommended-ranking'); ?></li>
                    <li>
                        <?php esc_html_e('手動を選んだときは、本文の好きな位置にショートコードを書きます：', 'kashiwazaki-seo-recommended-ranking'); ?>
                        <code>[<?php echo esc_html(Kashiwazaki_SEO_Recommended_Ranking_Frontend::SHORTCODE); ?>]</code>
                        <?php esc_html_e('（別のランキングを出すときは', 'kashiwazaki-seo-recommended-ranking'); ?>
                        <code>[<?php echo esc_html(Kashiwazaki_SEO_Recommended_Ranking_Frontend::SHORTCODE); ?> id="123"]</code><?php esc_html_e('）', 'kashiwazaki-seo-recommended-ranking'); ?>
                    </li>
                    <li><?php esc_html_e('ランキングを更新すると、そのランキングを表示しているすべてのページに反映されます。', 'kashiwazaki-seo-recommended-ranking'); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>

                <h2><?php esc_html_e('表示するページ', 'kashiwazaki-seo-recommended-ranking'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('ランキングを選べる投稿タイプ', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('ランキングを選べる投稿タイプ', 'kashiwazaki-seo-recommended-ranking'); ?></legend>
                                <?php foreach ($types as $type => $label) : ?>
                                    <label class="kashiwazaki-seo-recommended-ranking-check">
                                        <input type="checkbox" name="<?php echo esc_attr($name); ?>[post_types][]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $s['post_types'], true)); ?>>
                                        <?php echo esc_html($label); ?> <span class="description">(<?php echo esc_html($type); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('チェックした投稿タイプの編集画面に、ランキングを選ぶボックスが表示され、本文の上・下への自動の表示が働きます。ショートコードは、チェックに関係なくどのページでも使えます。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('デザイン', 'kashiwazaki-seo-recommended-ranking'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('デザインの型', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                        <td>
                            <fieldset class="kashiwazaki-seo-recommended-ranking-designs">
                                <legend class="screen-reader-text"><?php esc_html_e('デザインの型', 'kashiwazaki-seo-recommended-ranking'); ?></legend>
                                <?php foreach (self::design_choices() as $key => [$label, $desc]) : ?>
                                    <label class="kashiwazaki-seo-recommended-ranking-design">
                                        <input type="radio" name="<?php echo esc_attr($name); ?>[design]" value="<?php echo esc_attr($key); ?>" <?php checked($s['design'], $key); ?>>
                                        <span class="kashiwazaki-seo-recommended-ranking-design__name"><?php echo esc_html($label); ?></span>
                                        <span class="kashiwazaki-seo-recommended-ranking-design__desc"><?php echo esc_html($desc); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('すべてのランキングの既定の見た目です。ランキングの編集画面で、ランキングごとに別の型を選ぶこともできます。下の見本は、選んだ型・色ですぐに切り替わります。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-button-label"><?php esc_html_e('ボタンの文言（既定）', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                        <td>
                            <input type="text" id="kashiwazaki-seo-recommended-ranking-button-label" class="regular-text" maxlength="40" name="<?php echo esc_attr($name); ?>[button_label]" value="<?php echo esc_attr($s['button_label']); ?>">
                            <p class="description"><?php esc_html_e('URL を入力した順位に表示するボタンの文言です。ランキングごとに変えることもできます。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-accent"><?php esc_html_e('メインの色', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                        <td>
                            <input type="text" id="kashiwazaki-seo-recommended-ranking-accent" class="kashiwazaki-seo-recommended-ranking-color" name="<?php echo esc_attr($name); ?>[accent_color]" value="<?php echo esc_attr($s['accent_color']); ?>" data-default-color="<?php echo esc_attr(self::DEFAULT_ACCENT); ?>">
                            <p class="description"><?php esc_html_e('順位の印の背景・ボタン・飾りの線の色です。この色の上の文字（白か黒）は、読みやすいほうが自動で選ばれます。ページの地の上の文字は、テーマの文字の色のままです。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('1位〜3位の色', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[medal_colors]" value="1" <?php checked($s['medal_colors']); ?>>
                                <?php esc_html_e('1位を金色、2位を銀色、3位を銅色にする', 'kashiwazaki-seo-recommended-ranking'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('見本', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                        <td>
                            <?php $this->render_design_sample(); ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('構造化データ', 'kashiwazaki-seo-recommended-ranking'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('構造化データの出力', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[schema_enabled]" value="1" <?php checked($s['schema_enabled']); ?>>
                                <?php esc_html_e('ランキングを表示したページに構造化データ（JSON-LD）を出力する', 'kashiwazaki-seo-recommended-ranking'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('ItemList（ランキング全体）・ListItem（各順位）・Recommendation（おすすめ）で出力します。ランキングごとに出力を止めることもできます。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-author"><?php esc_html_e('おすすめしている人（author）', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                        <td>
                            <select id="kashiwazaki-seo-recommended-ranking-author" name="<?php echo esc_attr($name); ?>[schema_author]">
                                <option value="post_author" <?php selected($s['schema_author'], 'post_author'); ?>><?php esc_html_e('表示しているページの投稿者（人）', 'kashiwazaki-seo-recommended-ranking'); ?></option>
                                <option value="site" <?php selected($s['schema_author'], 'site'); ?>><?php esc_html_e('サイト名（組織）', 'kashiwazaki-seo-recommended-ranking'); ?></option>
                                <option value="none" <?php selected($s['schema_author'], 'none'); ?>><?php esc_html_e('出力しない', 'kashiwazaki-seo-recommended-ranking'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('投稿・固定ページ以外（一覧ページのウィジェットなど）では、サイト名を使います。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('プラグインの削除', 'kashiwazaki-seo-recommended-ranking'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('削除するときのデータ', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>[delete_on_uninstall]" value="1" <?php checked($s['delete_on_uninstall']); ?>>
                                <?php esc_html_e('プラグインを削除するときに、すべてのランキング・各ページの選択・この設定も削除する', 'kashiwazaki-seo-recommended-ranking'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('チェックしないときは、プラグインを削除してもランキングは残り、入れ直すと元どおりに使えます（停止するだけならデータは消えません）。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
