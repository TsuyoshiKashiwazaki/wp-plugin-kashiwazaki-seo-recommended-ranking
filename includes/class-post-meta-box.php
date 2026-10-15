<?php
/**
 * 投稿・固定ページ・カスタム投稿の編集画面のボックス（表示するランキングと表示位置）
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 投稿の編集画面のボックスクラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Post_Meta_Box {

    /**
     * 選択肢に出すランキングの上限
     */
    private const MAX_OPTIONS = 500;

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
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_Recommended_Ranking_Settings $settings 全体設定
     * @param Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings ランキング
     */
    public function __construct(Kashiwazaki_SEO_Recommended_Ranking_Settings $settings, Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings) {
        $this->settings = $settings;
        $this->rankings = $rankings;

        add_action('add_meta_boxes', [$this, 'add_meta_box'], 10, 2);
        add_action('save_post', [$this, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * 表示位置の名前
     *
     * @return array<string,string>
     */
    public static function position_labels(): array {
        return [
            'below'  => __('本文の下', 'kashiwazaki-seo-recommended-ranking'),
            'above'  => __('本文の上', 'kashiwazaki-seo-recommended-ranking'),
            'manual' => __('手動（ショートコードの位置）', 'kashiwazaki-seo-recommended-ranking'),
            'none'   => __('表示しない', 'kashiwazaki-seo-recommended-ranking'),
        ];
    }

    /**
     * ボックスを追加
     *
     * @param string $post_type 投稿タイプ
     * @param mixed  $post      投稿
     */
    public function add_meta_box($post_type, $post = null): void {
        if (!is_string($post_type) || !$this->settings->is_enabled_post_type($post_type)) {
            return;
        }
        add_meta_box(
            'kashiwazaki-seo-recommended-ranking-post',
            __('Kashiwazaki SEO Recommended Ranking', 'kashiwazaki-seo-recommended-ranking'),
            [$this, 'render'],
            $post_type,
            'side',
            'default'
        );
    }

    /**
     * 選択肢にするランキング（公開中のもの。公開していないものは、編集できるものだけ）
     *
     * @return WP_Post[]
     */
    private function selectable_rankings(): array {
        $posts = get_posts([
            'post_type'        => Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE,
            'post_status'      => ['publish', 'future', 'draft', 'pending', 'private'],
            'numberposts'      => self::MAX_OPTIONS,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach ($posts as $p) {
            if ($p->post_status !== 'publish' && !current_user_can('edit_post', $p->ID)) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * 状態の説明（公開中以外は表示されない）
     *
     * @param WP_Post $p ランキング
     * @return string
     */
    private static function status_note(WP_Post $p): string {
        if ($p->post_status === 'publish') {
            return $p->post_password !== '' ? __('パスワード保護・表示されません', 'kashiwazaki-seo-recommended-ranking') : '';
        }
        $obj = get_post_status_object($p->post_status);
        /* translators: %s: post status label */
        return sprintf(__('%s・表示されません', 'kashiwazaki-seo-recommended-ranking'), $obj ? $obj->label : $p->post_status);
    }

    /**
     * ボックスの中身
     *
     * @param WP_Post $post 投稿
     */
    public function render($post): void {
        if (!$post instanceof WP_Post) {
            return;
        }
        $sel      = $this->rankings->get_post_selection((int) $post->ID);
        $current  = $sel['ranking_id'];
        $position = $sel['position'];
        $options  = $this->selectable_rankings();
        $found    = false;
        $type     = get_post_type_object(Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE);
        $can_new  = $type && current_user_can($type->cap->create_posts);

        wp_nonce_field('kashiwazaki_seo_recommended_ranking_post_' . $post->ID, 'kashiwazaki_seo_recommended_ranking_post_nonce');
        ?>
        <div class="kashiwazaki-seo-recommended-ranking-post-box">
            <p>
                <label for="kashiwazaki-seo-recommended-ranking-ranking"><strong><?php esc_html_e('表示するランキング', 'kashiwazaki-seo-recommended-ranking'); ?></strong></label>
                <select id="kashiwazaki-seo-recommended-ranking-ranking" name="kashiwazaki_seo_recommended_ranking_post[ranking_id]" class="widefat">
                    <option value="0"><?php esc_html_e('— 表示しない —', 'kashiwazaki-seo-recommended-ranking'); ?></option>
                    <?php foreach ($options as $p) :
                        $note  = self::status_note($p);
                        $title = $p->post_title !== '' ? $p->post_title : __('（無題）', 'kashiwazaki-seo-recommended-ranking');
                        $label = $title . ($note !== '' ? '（' . $note . '）' : '') . ' #' . $p->ID;
                        $found = $found || $p->ID === $current;
                        ?>
                        <option value="<?php echo esc_attr((string) $p->ID); ?>"
                            data-edit="<?php echo esc_url((string) get_edit_post_link($p->ID, 'raw')); ?>"
                            data-hidden="<?php echo $note !== '' ? '1' : '0'; ?>"
                            <?php selected($p->ID, $current); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                    <?php if ($current > 0 && !$found) : ?>
                        <option value="<?php echo esc_attr((string) $current); ?>" data-edit="" data-hidden="1" selected>
                            <?php
                            /* translators: %d: ranking ID */
                            echo esc_html(sprintf(__('（見つからないランキング #%d・表示されません）', 'kashiwazaki-seo-recommended-ranking'), $current));
                            ?>
                        </option>
                    <?php endif; ?>
                </select>
            </p>
            <p class="kashiwazaki-seo-recommended-ranking-post-links">
                <a href="#" class="kashiwazaki-seo-recommended-ranking-edit-link" target="_blank" rel="noopener" hidden><?php esc_html_e('このランキングを編集（新しいタブ）', 'kashiwazaki-seo-recommended-ranking'); ?></a>
                <?php if ($can_new) : ?>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Kashiwazaki_SEO_Recommended_Ranking_Rankings::POST_TYPE)); ?>" target="_blank" rel="noopener"><?php esc_html_e('ランキングを新規作成（新しいタブ）', 'kashiwazaki-seo-recommended-ranking'); ?></a>
                <?php endif; ?>
            </p>
            <p class="kashiwazaki-seo-recommended-ranking-post-warning notice-warning" hidden><?php esc_html_e('このランキングは公開されていないため、ページには表示されません。', 'kashiwazaki-seo-recommended-ranking'); ?></p>

            <fieldset class="kashiwazaki-seo-recommended-ranking-positions">
                <legend><strong><?php esc_html_e('表示位置', 'kashiwazaki-seo-recommended-ranking'); ?></strong></legend>
                <?php foreach (self::position_labels() as $value => $label) : ?>
                    <label>
                        <input type="radio" name="kashiwazaki_seo_recommended_ranking_post[position]" value="<?php echo esc_attr($value); ?>" <?php checked($position, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </label><br>
                <?php endforeach; ?>
            </fieldset>

            <div class="kashiwazaki-seo-recommended-ranking-manual-help" <?php echo $position === 'manual' ? '' : 'hidden'; ?>>
                <p><?php esc_html_e('本文の表示したい位置に、次のショートコードを書いてください。', 'kashiwazaki-seo-recommended-ranking'); ?></p>
                <input type="text" class="widefat code kashiwazaki-seo-recommended-ranking-select-on-focus" readonly value="<?php echo esc_attr('[' . Kashiwazaki_SEO_Recommended_Ranking_Frontend::SHORTCODE . ']'); ?>" aria-label="<?php esc_attr_e('ショートコード', 'kashiwazaki-seo-recommended-ranking'); ?>">
            </div>
            <p class="description">
                <?php esc_html_e('ランキングの内容は「Kashiwazaki SEO Recommended Ranking」のメニューで編集します。更新すると、このページの表示にも反映されます。', 'kashiwazaki-seo-recommended-ranking'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * 保存
     *
     * @param int     $post_id 投稿 ID
     * @param WP_Post $post    投稿
     */
    public function save($post_id, $post): void {
        $post_id = (int) $post_id;
        if (!$post instanceof WP_Post || !$this->settings->is_enabled_post_type($post->post_type)) {
            return;
        }
        if (!isset($_POST['kashiwazaki_seo_recommended_ranking_post_nonce'], $_POST['kashiwazaki_seo_recommended_ranking_post'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['kashiwazaki_seo_recommended_ranking_post_nonce']));
        if (!wp_verify_nonce($nonce, 'kashiwazaki_seo_recommended_ranking_post_' . $post_id)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下で 1 項目ずつ検証する
        $raw = wp_unslash($_POST['kashiwazaki_seo_recommended_ranking_post']);
        $raw = is_array($raw) ? $raw : [];

        $ranking_id = isset($raw['ranking_id']) && is_scalar($raw['ranking_id']) ? absint($raw['ranking_id']) : 0;
        $position   = isset($raw['position']) && is_string($raw['position']) ? $raw['position'] : 'below';
        if (!in_array($position, Kashiwazaki_SEO_Recommended_Ranking_Rankings::POSITIONS, true)) {
            $position = 'below';
        }
        if ($ranking_id > 0) {
            $ranking = $this->rankings->get_post($ranking_id);
            $before  = (int) get_post_meta($post_id, Kashiwazaki_SEO_Recommended_Ranking_Rankings::META_POST_RANKING, true);
            // 選べるのは、公開中のランキングと、自分が編集できる（ゴミ箱以外の）ランキング。
            // 前から選んでいたものは、ゴミ箱に入っていてもそのまま残す（ゴミ箱から戻すと、また表示される）
            $allowed = $ranking !== null && (
                $before === $ranking_id
                || $ranking->post_status === 'publish'
                || ($ranking->post_status !== 'trash' && current_user_can('edit_post', $ranking_id))
            );
            if (!$allowed) {
                $ranking_id = 0;
            }
        }
        if ($ranking_id <= 0) {
            delete_post_meta($post_id, Kashiwazaki_SEO_Recommended_Ranking_Rankings::META_POST_RANKING);
            delete_post_meta($post_id, Kashiwazaki_SEO_Recommended_Ranking_Rankings::META_POST_POSITION);
            return;
        }
        update_post_meta($post_id, Kashiwazaki_SEO_Recommended_Ranking_Rankings::META_POST_RANKING, $ranking_id);
        update_post_meta($post_id, Kashiwazaki_SEO_Recommended_Ranking_Rankings::META_POST_POSITION, $position);
    }

    /**
     * 編集画面のスクリプト
     *
     * @param string $hook_suffix 画面
     */
    public function enqueue_assets($hook_suffix): void {
        if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || !$this->settings->is_enabled_post_type((string) $screen->post_type)) {
            return;
        }
        wp_enqueue_style(
            'kashiwazaki-seo-recommended-ranking-admin',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/css/admin.css',
            [],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/css/admin.css')
        );
        Kashiwazaki_SEO_Recommended_Ranking_Ranking_Editor::enqueue_common_script();
        wp_enqueue_script(
            'kashiwazaki-seo-recommended-ranking-post',
            KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/js/admin-post.js',
            [],
            Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/js/admin-post.js'),
            true
        );
    }
}
