<?php
/**
 * ランキングの編集画面（見出し・概要説明・順位・表示・構造化データ・プレビュー）
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ランキングの編集画面クラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Ranking_Editor {

    /**
     * 全体設定
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Settings
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Settings $settings;

    /**
     * ランキング
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Rankings
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings;

    /**
     * 表示
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Renderer
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Renderer $renderer;

    /**
     * 構造化データ
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Schema
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Schema $schema;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_Recommended_Ranking_Settings $settings 全体設定
     * @param Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings ランキング
     * @param Kashiwazaki_SEO_Recommended_Ranking_Renderer $renderer 表示
     * @param Kashiwazaki_SEO_Recommended_Ranking_Schema   $schema   構造化データ
     */
    public function __construct(
        Kashiwazaki_SEO_Recommended_Ranking_Settings $settings,
        Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings,
        Kashiwazaki_SEO_Recommended_Ranking_Renderer $renderer,
        Kashiwazaki_SEO_Recommended_Ranking_Schema $schema
    ) {
        $this->settings = $settings;
        $this->rankings = $rankings;
        $this->renderer = $renderer;
        $this->schema   = $schema;

        add_action('add_meta_boxes_' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE, [$this, 'add_meta_boxes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_kashiwazaki_seo_recommended_ranking_preview', [$this, 'ajax_preview']);
    }

    /**
     * ボックスを追加
     */
    public function add_meta_boxes(): void {
        $type = Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE;
        remove_meta_box('slugdiv', $type, 'normal');
        add_meta_box('kashiwazaki-seo-recommended-ranking-content', __('ランキングの内容', 'kashiwazaki-seo-recommended-ranking'), [$this, 'render_content_box'], $type, 'normal', 'high');
        add_meta_box('kashiwazaki-seo-recommended-ranking-display', __('デザイン・ボタン・リンク', 'kashiwazaki-seo-recommended-ranking'), [$this, 'render_display_box'], $type, 'normal', 'default');
        add_meta_box('kashiwazaki-seo-recommended-ranking-schema', __('構造化データ', 'kashiwazaki-seo-recommended-ranking'), [$this, 'render_schema_box'], $type, 'normal', 'default');
        add_meta_box('kashiwazaki-seo-recommended-ranking-preview', __('プレビュー', 'kashiwazaki-seo-recommended-ranking'), [$this, 'render_preview_box'], $type, 'normal', 'low');
        add_meta_box('kashiwazaki-seo-recommended-ranking-usage', __('ショートコードと使っているページ', 'kashiwazaki-seo-recommended-ranking'), [$this, 'render_usage_box'], $type, 'side', 'default');
    }

    /**
     * 編集画面のスクリプト・スタイル
     *
     * @param string $hook_suffix 画面
     */
    public function enqueue_assets($hook_suffix): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE) {
            return;
        }
        wp_enqueue_style(
            'kashiwazaki-seo-recommended-ranking-admin',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/css/admin.css',
            [],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/css/admin.css')
        );
        self::enqueue_common_script();
        if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }
        // プレビューは公開ページと同じスタイルで見せる
        Kashiwazaki_SEO_Recommended_Ranking_Renderer::register_style();
        wp_enqueue_style(Kashiwazaki_SEO_Recommended_Ranking_Renderer::STYLE_HANDLE);
        wp_enqueue_script(
            'kashiwazaki-seo-recommended-ranking-ranking',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/js/admin-ranking.js',
            ['jquery', 'jquery-ui-sortable'],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/js/admin-ranking.js'),
            true
        );
        wp_localize_script('kashiwazaki-seo-recommended-ranking-ranking', 'kashiwazakiSeoRecommendedRanking', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('kashiwazaki_seo_recommended_ranking_preview'),
            'minItems' => Kashiwazaki_SEO_Recommended_Ranking_Rankings::MIN_ITEMS,
            'maxItems' => Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_ITEMS,
            'i18n'     => [
                /* translators: %d: rank */
                'rank'         => __('%d位', 'kashiwazaki-seo-recommended-ranking'),
                /* translators: 1: current count, 2: max count */
                'count'        => __('%1$d / 最大 %2$d', 'kashiwazaki-seo-recommended-ranking'),
                /* translators: %d: minimum number of ranks */
                'needMin'      => __('公開するには、%d位までの名称を入力してください。', 'kashiwazaki-seo-recommended-ranking'),
                'emptyName'    => __('名称が空の順位があります。名称を入力するか、その順位を削除してください。', 'kashiwazaki-seo-recommended-ranking'),
                'badUrl'       => __('URL は https:// か http:// で始まる形、または「/」で始まるサイト内のパスで入力してください（このままでは保存されません）。', 'kashiwazaki-seo-recommended-ranking'),
                'previewError' => __('プレビューを作れませんでした。', 'kashiwazaki-seo-recommended-ranking'),
                'previewEmpty' => __('名称を入力すると、ここにプレビューが表示されます。', 'kashiwazaki-seo-recommended-ranking'),
                'copied'       => __('コピーしました', 'kashiwazaki-seo-recommended-ranking'),
                /* translators: %d: minimum number of ranks */
                'minReached'   => __('順位は %d つより少なくできません。', 'kashiwazaki-seo-recommended-ranking'),
            ],
        ]);
    }

    /**
     * 管理画面の共通のスクリプト（ショートコードの欄を選んだら全体を選択する）
     */
    public static function enqueue_common_script(): void {
        wp_enqueue_script(
            'kashiwazaki-seo-recommended-ranking-admin-common',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/js/admin-common.js',
            [],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/js/admin-common.js'),
            true
        );
    }

    /**
     * 1つの順位の入力欄
     *
     * @param int|string $index 番号（テンプレートでは __INDEX__）
     * @param array      $item  内容
     */
    private function render_item_row($index, array $item): void {
        $base = 'kashiwazaki_seo_recommended_ranking[items][' . $index . ']';
        $id   = 'kashiwazaki-seo-recommended-ranking-item-' . $index;
        $rank = is_int($index) ? $index + 1 : 1;
        ?>
        <div class="kashiwazaki-seo-recommended-ranking-item" data-index="<?php echo esc_attr((string) $index); ?>">
            <div class="kashiwazaki-seo-recommended-ranking-item__head">
                <span class="kashiwazaki-seo-recommended-ranking-item__handle dashicons dashicons-menu" title="<?php esc_attr_e('ドラッグして並べ替え', 'kashiwazaki-seo-recommended-ranking'); ?>" aria-hidden="true"></span>
                <span class="kashiwazaki-seo-recommended-ranking-item__rank">
                    <?php
                    /* translators: %d: rank */
                    echo esc_html(sprintf(__('%d位', 'kashiwazaki-seo-recommended-ranking'), $rank));
                    ?>
                </span>
                <span class="kashiwazaki-seo-recommended-ranking-item__tools">
                    <button type="button" class="button button-small kashiwazaki-seo-recommended-ranking-item__up" aria-label="<?php esc_attr_e('この順位を1つ上へ', 'kashiwazaki-seo-recommended-ranking'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button button-small kashiwazaki-seo-recommended-ranking-item__down" aria-label="<?php esc_attr_e('この順位を1つ下へ', 'kashiwazaki-seo-recommended-ranking'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button button-small kashiwazaki-seo-recommended-ranking-item__remove" aria-label="<?php esc_attr_e('この順位を削除', 'kashiwazaki-seo-recommended-ranking'); ?>"><?php esc_html_e('削除', 'kashiwazaki-seo-recommended-ranking'); ?></button>
                </span>
            </div>
            <div class="kashiwazaki-seo-recommended-ranking-item__fields">
                <p>
                    <label for="<?php echo esc_attr($id . '-name'); ?>"><?php esc_html_e('名称', 'kashiwazaki-seo-recommended-ranking'); ?> <span class="kashiwazaki-seo-recommended-ranking-required"><?php esc_html_e('（必須）', 'kashiwazaki-seo-recommended-ranking'); ?></span></label>
                    <input type="text" class="widefat kashiwazaki-seo-recommended-ranking-item__name" id="<?php echo esc_attr($id . '-name'); ?>" name="<?php echo esc_attr($base . '[name]'); ?>" value="<?php echo esc_attr($item['name']); ?>" maxlength="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_NAME); ?>" data-field="name">
                </p>
                <p>
                    <label for="<?php echo esc_attr($id . '-description'); ?>"><?php esc_html_e('簡単な説明', 'kashiwazaki-seo-recommended-ranking'); ?></label>
                    <textarea class="widefat" rows="3" id="<?php echo esc_attr($id . '-description'); ?>" name="<?php echo esc_attr($base . '[description]'); ?>" maxlength="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_ITEM_DESC); ?>" data-field="description"><?php echo esc_textarea($item['description']); ?></textarea>
                </p>
                <p>
                    <label for="<?php echo esc_attr($id . '-url'); ?>"><?php esc_html_e('URL', 'kashiwazaki-seo-recommended-ranking'); ?></label>
                    <input type="text" inputmode="url" autocomplete="off" spellcheck="false" class="widefat code kashiwazaki-seo-recommended-ranking-item__url" id="<?php echo esc_attr($id . '-url'); ?>" name="<?php echo esc_attr($base . '[url]'); ?>" value="<?php echo esc_attr($item['url']); ?>" placeholder="https://" maxlength="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_URL); ?>" data-field="url">
                    <span class="kashiwazaki-seo-recommended-ranking-item__url-error" role="alert" hidden></span>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * 「ランキングの内容」ボックス
     *
     * @param WP_Post $post ランキング
     */
    public function render_content_box($post): void {
        if (!$post instanceof WP_Post) {
            return;
        }
        $data  = $this->rankings->get_data((int) $post->ID);
        $items = $data['items'];
        while (count($items) < Kashiwazaki_SEO_Recommended_Ranking_Rankings::MIN_ITEMS) {
            $items[] = ['name' => '', 'description' => '', 'url' => ''];
        }
        $tags = [
            'h2'   => __('h2（大見出し）', 'kashiwazaki-seo-recommended-ranking'),
            'h3'   => __('h3（中見出し）', 'kashiwazaki-seo-recommended-ranking'),
            'h4'   => __('h4（小見出し）', 'kashiwazaki-seo-recommended-ranking'),
            'p'    => __('見出しにしない（太字の文字）', 'kashiwazaki-seo-recommended-ranking'),
            'none' => __('見出しを表示しない', 'kashiwazaki-seo-recommended-ranking'),
        ];
        wp_nonce_field('kashiwazaki_seo_recommended_ranking_save_ranking', 'kashiwazaki_seo_recommended_ranking_ranking_nonce');
        ?>
        <div class="kashiwazaki-seo-recommended-ranking-editor">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-heading"><?php esc_html_e('見出し', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                    <td>
                        <input type="text" id="kashiwazaki-seo-recommended-ranking-heading" class="widefat" name="kashiwazaki_seo_recommended_ranking[heading]" value="<?php echo esc_attr($data['heading']); ?>" maxlength="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_HEADING); ?>" placeholder="<?php esc_attr_e('例：初心者におすすめのSEOツール ランキング', 'kashiwazaki-seo-recommended-ranking'); ?>">
                        <p class="description"><?php esc_html_e('ページに表示する見出しです。空欄のときは、上の管理用の名前を使います。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-heading-tag"><?php esc_html_e('見出しの種類', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                    <td>
                        <select id="kashiwazaki-seo-recommended-ranking-heading-tag" name="kashiwazaki_seo_recommended_ranking[heading_tag]">
                            <?php foreach ($tags as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($data['heading_tag'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('記事の見出しの階層に合わせて選びます（本文の h2 の中に置くなら h3 など）。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-description"><?php esc_html_e('概要説明', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                    <td>
                        <textarea id="kashiwazaki-seo-recommended-ranking-description" class="widefat" rows="3" name="kashiwazaki_seo_recommended_ranking[description]" maxlength="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_DESCRIPTION); ?>" placeholder="<?php esc_attr_e('例：実際に使って比べた中から、目的別におすすめできるものを順に紹介します。', 'kashiwazaki-seo-recommended-ranking'); ?>"><?php echo esc_textarea($data['description']); ?></textarea>
                        <p class="description"><?php esc_html_e('見出しの下に表示する説明です（空欄なら表示しません）。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                    </td>
                </tr>
            </table>

            <h3 class="kashiwazaki-seo-recommended-ranking-items-title">
                <?php
                /* translators: 1: min ranks, 2: max ranks */
                echo esc_html(sprintf(__('順位（%1$d位〜%2$d位）', 'kashiwazaki-seo-recommended-ranking'), Kashiwazaki_SEO_Recommended_Ranking_Rankings::MIN_ITEMS, Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_ITEMS));
                ?>
            </h3>
            <p class="description"><?php esc_html_e('上から1位・2位…の順です。左のつまみをドラッグするか、矢印のボタンで並べ替えられます。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
            <div class="kashiwazaki-seo-recommended-ranking-items" data-min="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MIN_ITEMS); ?>" data-max="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_ITEMS); ?>">
                <?php foreach (array_values($items) as $i => $item) {
                    $this->render_item_row($i, $item);
                } ?>
            </div>
            <div class="kashiwazaki-seo-recommended-ranking-items-footer">
                <button type="button" class="button kashiwazaki-seo-recommended-ranking-add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e('順位を追加', 'kashiwazaki-seo-recommended-ranking'); ?></button>
                <span class="kashiwazaki-seo-recommended-ranking-count" aria-live="polite"></span>
            </div>
            <div class="kashiwazaki-seo-recommended-ranking-validation notice notice-warning inline" role="alert" hidden><p></p></div>
            <template id="kashiwazaki-seo-recommended-ranking-item-template">
                <?php $this->render_item_row('__INDEX__', ['name' => '', 'description' => '', 'url' => '']); ?>
            </template>
        </div>
        <?php
    }

    /**
     * 「デザイン・ボタン・リンク」ボックス
     *
     * @param WP_Post $post ランキング
     */
    public function render_display_box($post): void {
        if (!$post instanceof WP_Post) {
            return;
        }
        $data    = $this->rankings->get_data((int) $post->ID);
        $global  = (string) $this->settings->value('button_label');
        $designs = Kashiwazaki_SEO_Recommended_Ranking_Settings::design_choices();
        $current = (string) $this->settings->value('design');
        $rels   = [
            ''                   => __('付けない', 'kashiwazaki-seo-recommended-ranking'),
            'nofollow'           => __('nofollow（リンク先を評価しない）', 'kashiwazaki-seo-recommended-ranking'),
            'sponsored'          => __('sponsored（広告・アフィリエイトのリンク）', 'kashiwazaki-seo-recommended-ranking'),
            'sponsored nofollow' => __('sponsored nofollow（両方）', 'kashiwazaki-seo-recommended-ranking'),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-design"><?php esc_html_e('デザインの型', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                <td>
                    <select id="kashiwazaki-seo-recommended-ranking-design" name="kashiwazaki_seo_recommended_ranking[design]">
                        <option value="" <?php selected($data['design'], ''); ?>>
                            <?php
                            /* translators: %s: design name chosen in the settings */
                            echo esc_html(sprintf(__('設定画面の型に従う（いまは「%s」）', 'kashiwazaki-seo-recommended-ranking'), $designs[$current][0] ?? $current));
                            ?>
                        </option>
                        <?php foreach ($designs as $key => [$label, $desc]) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($data['design'], $key); ?>><?php echo esc_html($label . '：' . $desc); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('このランキングの見た目です。下のプレビューで確かめられます。色は設定画面の「メインの色」と「1位〜3位の色」を使います。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-button"><?php esc_html_e('ボタンの文言', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                <td>
                    <input type="text" id="kashiwazaki-seo-recommended-ranking-button" class="regular-text" name="kashiwazaki_seo_recommended_ranking[button_label]" value="<?php echo esc_attr($data['button_label']); ?>" maxlength="<?php echo esc_attr((string) Kashiwazaki_SEO_Recommended_Ranking_Rankings::MAX_BUTTON); ?>" placeholder="<?php echo esc_attr($global); ?>">
                    <p class="description">
                        <?php
                        /* translators: %s: default button label */
                        echo esc_html(sprintf(__('URL を入力した順位に表示するボタンです。空欄のときは設定画面の文言（%s）を使います。', 'kashiwazaki-seo-recommended-ranking'), $global));
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('リンクの開き方', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="kashiwazaki_seo_recommended_ranking[new_tab]" value="1" <?php checked($data['new_tab']); ?>>
                        <?php esc_html_e('リンクを新しいタブで開く', 'kashiwazaki-seo-recommended-ranking'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-rel"><?php esc_html_e('外部リンクの rel', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                <td>
                    <select id="kashiwazaki-seo-recommended-ranking-rel" name="kashiwazaki_seo_recommended_ranking[rel]">
                        <?php foreach ($rels as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($data['rel'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('ほかのサイトへのリンクだけに付けます（このサイトの中へのリンクには付けません）。広告やアフィリエイトのリンクには sponsored を選んでください。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * 「構造化データ」ボックス
     *
     * @param WP_Post $post ランキング
     */
    public function render_schema_box($post): void {
        if (!$post instanceof WP_Post) {
            return;
        }
        $data   = $this->rankings->get_data((int) $post->ID);
        $global = (bool) $this->settings->value('schema_enabled');
        $types  = [
            'Thing'   => __('指定しない（Thing）— 迷ったらこれ', 'kashiwazaki-seo-recommended-ranking'),
            'Product' => __('商品（Product）', 'kashiwazaki-seo-recommended-ranking'),
            'Service' => __('サービス（Service）', 'kashiwazaki-seo-recommended-ranking'),
        ];
        ?>
        <?php if (!$global) : ?>
            <div class="notice notice-info inline">
                <p>
                    <?php esc_html_e('設定画面で構造化データの出力が止まっているため、いまは出力されません。', 'kashiwazaki-seo-recommended-ranking'); ?>
                    <?php if (current_user_can('manage_options')) : ?>
                        <a href="<?php echo esc_url(Kashiwazaki_SEO_Recommended_Ranking_Settings::page_url()); ?>"><?php esc_html_e('設定を開く', 'kashiwazaki-seo-recommended-ranking'); ?></a>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('出力', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="kashiwazaki_seo_recommended_ranking[schema_enabled]" value="1" <?php checked($data['schema_enabled']); ?>>
                        <?php esc_html_e('このランキングの構造化データ（JSON-LD）を出力する', 'kashiwazaki-seo-recommended-ranking'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('ItemList（ランキング全体）の中に、ListItem（順位）と Recommendation（おすすめ）を入れて出力します。同じページに同じランキングを2回表示しても、出力は1回です。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="kashiwazaki-seo-recommended-ranking-item-type"><?php esc_html_e('おすすめする対象の種類', 'kashiwazaki-seo-recommended-ranking'); ?></label></th>
                <td>
                    <select id="kashiwazaki-seo-recommended-ranking-item-type" name="kashiwazaki_seo_recommended_ranking[schema_item_type]">
                        <?php foreach ($types as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($data['schema_item_type'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Google の商品のリッチリザルトは、1つの商品を扱うページで、価格・評価などが必要です。このプラグインは価格や評価を出力しないため、「商品」を選んでもリッチリザルトの対象にはならず、Search Console に注意が表示されることがあります。商品か決めかねるときは「指定しない」を選んでください。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('まとめの形', 'kashiwazaki-seo-recommended-ranking'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('まとめの形', 'kashiwazaki-seo-recommended-ranking'); ?></legend>
                        <label>
                            <input type="radio" name="kashiwazaki_seo_recommended_ranking[schema_wrapper]" value="itemlist" <?php checked($data['schema_wrapper'], 'itemlist'); ?>>
                            <?php esc_html_e('ItemList だけ（記事の一部としてランキングを載せるとき）', 'kashiwazaki-seo-recommended-ranking'); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="kashiwazaki_seo_recommended_ranking[schema_wrapper]" value="guide" <?php checked($data['schema_wrapper'], 'guide'); ?>>
                            <?php esc_html_e('Guide でまとめる（「おすすめ○選」のように、ランキングそのものが記事の主題のとき）', 'kashiwazaki-seo-recommended-ranking'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * 「プレビュー」ボックス
     *
     * @param WP_Post $post ランキング
     */
    public function render_preview_box($post): void {
        ?>
        <div class="kashiwazaki-seo-recommended-ranking-preview">
            <div class="kashiwazaki-seo-recommended-ranking-preview__toolbar" role="group" aria-label="<?php esc_attr_e('プレビューの幅', 'kashiwazaki-seo-recommended-ranking'); ?>">
                <button type="button" class="button kashiwazaki-seo-recommended-ranking-preview__width is-active" data-width="pc" aria-pressed="true"><?php esc_html_e('PC', 'kashiwazaki-seo-recommended-ranking'); ?></button>
                <button type="button" class="button kashiwazaki-seo-recommended-ranking-preview__width" data-width="sp" aria-pressed="false"><?php esc_html_e('スマホ', 'kashiwazaki-seo-recommended-ranking'); ?></button>
                <span class="kashiwazaki-seo-recommended-ranking-preview__status description" aria-live="polite"></span>
            </div>
            <p class="description"><?php esc_html_e('入力中の内容で表示します（保存するまでページには反映されません）。実際の文字の大きさ・フォントは、テーマによって変わります。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
            <div class="kashiwazaki-seo-recommended-ranking-preview__frame" data-width="pc">
                <div class="kashiwazaki-seo-recommended-ranking-preview__canvas"></div>
            </div>
            <details class="kashiwazaki-seo-recommended-ranking-preview__json">
                <summary><?php esc_html_e('構造化データ（JSON-LD）を見る', 'kashiwazaki-seo-recommended-ranking'); ?></summary>
                <p class="description"><?php esc_html_e('URL の部分は例です。実際には、ランキングを表示したページの URL になります。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                <pre class="kashiwazaki-seo-recommended-ranking-preview__json-body"></pre>
            </details>
        </div>
        <?php
    }

    /**
     * 「ショートコードと使っているページ」ボックス
     *
     * @param WP_Post $post ランキング
     */
    public function render_usage_box($post): void {
        if (!$post instanceof WP_Post) {
            return;
        }
        $code   = sprintf('[%s id="%d"]', Kashiwazaki_SEO_Recommended_Ranking_Frontend::SHORTCODE, $post->ID);
        $usage  = $post->post_status === 'auto-draft' ? [] : $this->rankings->get_usage((int) $post->ID);
        $labels = Kashiwazaki_SEO_Recommended_Ranking_Post_Meta_Box::position_labels();
        ?>
        <p><label for="kashiwazaki-seo-recommended-ranking-shortcode"><strong><?php esc_html_e('ショートコード', 'kashiwazaki-seo-recommended-ranking'); ?></strong></label></p>
        <div class="kashiwazaki-seo-recommended-ranking-copy">
            <input type="text" id="kashiwazaki-seo-recommended-ranking-shortcode" class="widefat code kashiwazaki-seo-recommended-ranking-select-on-focus" readonly value="<?php echo esc_attr($code); ?>">
            <button type="button" class="button kashiwazaki-seo-recommended-ranking-copy__button" data-target="kashiwazaki-seo-recommended-ranking-shortcode"><?php esc_html_e('コピー', 'kashiwazaki-seo-recommended-ranking'); ?></button>
        </div>
        <p class="description"><?php esc_html_e('本文の好きな位置に貼ると、このランキングを表示します（公開中のときだけ）。', 'kashiwazaki-seo-recommended-ranking'); ?></p>

        <p><strong><?php esc_html_e('使っているページ', 'kashiwazaki-seo-recommended-ranking'); ?></strong></p>
        <?php if (empty($usage)) : ?>
            <p class="description"><?php esc_html_e('まだどのページでも使われていません。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
        <?php else : ?>
            <ul class="kashiwazaki-seo-recommended-ranking-usage-list">
                <?php foreach (array_slice($usage, 0, 50) as $u) :
                    $p = get_post($u['post_id']);
                    if (!$p) {
                        continue;
                    }
                    // ほかの人の非公開・下書きのページのタイトルは、読める人・編集できる人にだけ出す
                    $can_see = current_user_can('read_post', $p->ID) || current_user_can('edit_post', $p->ID);
                    $title   = !$can_see ? __('（表示する権限のないページ）', 'kashiwazaki-seo-recommended-ranking')
                        : ($p->post_title !== '' ? $p->post_title : __('（無題）', 'kashiwazaki-seo-recommended-ranking'));
                    $how   = [];
                    if (in_array('select', $u['via'], true)) {
                        $how[] = $labels[$u['position']] ?? '';
                    }
                    if (in_array('shortcode', $u['via'], true)) {
                        $how[] = __('ショートコード', 'kashiwazaki-seo-recommended-ranking');
                    }
                    $status = get_post_status_object($p->post_status);
                    $edit   = current_user_can('edit_post', $p->ID) ? get_edit_post_link($p->ID, 'raw') : '';
                    ?>
                    <li>
                        <?php if ($edit) : ?>
                            <a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($title); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($title); ?>
                        <?php endif; ?>
                        <span class="description">（<?php echo esc_html(implode('・', array_filter($how))); ?><?php echo $p->post_status !== 'publish' && $status ? esc_html('・' . $status->label) : ''; ?>）</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($usage) > 50) : ?>
                <p class="description">
                    <?php
                    /* translators: %d: number of pages */
                    echo esc_html(sprintf(__('ほか %d 件', 'kashiwazaki-seo-recommended-ranking'), count($usage) - 50));
                    ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * プレビュー（admin-ajax.php）
     */
    public function ajax_preview(): void {
        check_ajax_referer('kashiwazaki_seo_recommended_ranking_preview', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $type    = get_post_type_object(Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE);
        $allowed = $post_id > 0
            ? ($this->rankings->get_post($post_id) !== null && current_user_can('edit_post', $post_id))
            : ($type !== null && current_user_can($type->cap->create_posts));
        if (!$allowed) {
            wp_send_json_error(['message' => __('権限がありません。', 'kashiwazaki-seo-recommended-ranking')], 403);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_input で検証する
        $raw    = isset($_POST['kashiwazaki_seo_recommended_ranking']) ? wp_unslash($_POST['kashiwazaki_seo_recommended_ranking']) : [];
        $result = Kashiwazaki_SEO_Recommended_Ranking_Rankings::sanitize_input($raw);
        $data   = Kashiwazaki_SEO_Recommended_Ranking_Rankings::normalize($result['data']);
        // 管理用の名前は、本体が保存するときと同じ処理（sanitize_post_field の 'db'＝pre_post_title と title_save_pre）を通す。
        // 保存後の表示（見出しの代わり・JSON-LD）とプレビューを同じにするため
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 本体と同じ保存の処理を通す
        $raw_title = isset($_POST['title']) && is_string($_POST['title']) ? wp_unslash($_POST['title']) : '';
        $title     = (string) wp_unslash(sanitize_post_field('post_title', wp_slash($raw_title), $post_id, 'db'));

        $html = $this->renderer->render_data($data, $post_id, $title, 'kashiwazaki-seo-recommended-ranking-preview');
        $json = '';
        if ($html !== '' && $this->schema->is_enabled($data)) {
            $built = $this->schema->build($data, $post_id, $title, home_url('/example/'), $this->preview_author());
            $json  = $built !== null ? Kashiwazaki_SEO_Recommended_Ranking_Schema::encode($built, true) : '';
        }
        wp_send_json_success([
            'html'         => $html,
            'json'         => $json,
            'named'        => $result['named'],
            'invalid_rows' => $result['invalid_rows'],
        ]);
    }

    /**
     * プレビューの author（投稿者を使う設定では、例として自分を出す）
     *
     * @return array|null
     */
    private function preview_author(): ?array {
        $mode = (string) $this->settings->value('schema_author');
        if ($mode === 'none') {
            return null;
        }
        if ($mode === 'post_author') {
            $user = wp_get_current_user();
            if ($user && $user->exists()) {
                return ['@type' => 'Person', 'name' => Kashiwazaki_SEO_Recommended_Ranking_Schema::plain((string) $user->display_name), 'url' => get_author_posts_url($user->ID)];
            }
        }
        return ['@type' => 'Organization', 'name' => Kashiwazaki_SEO_Recommended_Ranking_Schema::plain(wp_strip_all_tags(get_bloginfo('name'))), 'url' => home_url('/')];
    }
}
