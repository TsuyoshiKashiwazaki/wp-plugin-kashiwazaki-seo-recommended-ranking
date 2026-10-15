<?php
/**
 * 公開ページへの差し込み（本文の上・下）とショートコード
 *
 * 本文の上・下への自動の差し込み：
 * 1. the_content の最初（優先度 1）に、本文の上・下へ「目印」を置く。
 *    パンくず・関連記事など、ほかのプラグインが本文の上下に足すものより内側（本文の直上・直下）になる。
 * 2. the_content の最後のほう（優先度 999）で、目印をランキングの HTML に置き換える。
 *    途中の加工（段落の自動整形・タグの自動リンク・目次の見出し収集など）がランキングの中身を書き換えないようにする。
 * 3. 目次プラグインなどが the_content の中でもう一度 the_content を走らせる（入れ子）ときは差し込まない。
 *    入れ子の側にだけ見出しが入ると、目次に「本文に無い見出し」へのリンクができてしまうため。
 *
 * ショートコード：本文の中で書いた位置に、その場で HTML を返す（本文のほかの見出しと同じ扱いになる）。
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 公開ページクラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Frontend {

    /**
     * ショートコード名
     */
    public const SHORTCODE = 'kashiwazaki_seo_recommended_ranking';

    /**
     * 目印の属性名
     */
    private const SLOT_ATTR = 'data-kashiwazaki-seo-recommended-ranking-slot';

    /**
     * 目印を置く優先度・置き換える優先度
     */
    private const PRIORITY_SLOT    = 1;
    private const PRIORITY_REPLACE = 999;

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
     * 置いた目印（目印の文字列 => 中身）
     *
     * @var array<string,int>
     */
    private array $slots = [];

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

        add_action('init', [$this, 'register_shortcode']);
        add_filter('the_content', [$this, 'insert_slots'], self::PRIORITY_SLOT);
        add_filter('the_content', [$this, 'replace_slots'], self::PRIORITY_REPLACE);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue']);
    }

    /**
     * ショートコードを登録
     */
    public function register_shortcode(): void {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
    }

    /**
     * 本文の中のショートコードに書かれたランキング ID（id の無いものは数えない）
     *
     * @param string $content 本文
     * @return int[]
     */
    public static function shortcode_ids_in(string $content): array {
        if (strpos($content, '[' . self::SHORTCODE) === false) {
            return [];
        }
        $ids = [];
        if (preg_match_all('/' . get_shortcode_regex([self::SHORTCODE]) . '/', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                // [[...]] はショートコードの書き方の例として、そのまま文字で表示される
                if ($match[1] === '[' && $match[6] === ']') {
                    continue;
                }
                $atts = shortcode_parse_atts($match[3]);
                $id   = is_array($atts) && isset($atts['id']) ? absint($atts['id']) : 0;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * ランキングを表示しない場面か（抜粋の作成・head の中・管理画面）
     *
     * @return bool
     */
    private function is_non_display_context(): bool {
        if (is_admin() && !wp_doing_ajax()) {
            return true;
        }
        // 抜粋（説明文など）にランキングの文字が混ざらないように
        if (doing_filter('get_the_excerpt') || doing_filter('wp_trim_excerpt')) {
            return true;
        }
        // head の中で本文を加工する処理（説明文の自動生成など）には出さない
        if (doing_action('wp_head')) {
            return true;
        }
        return false;
    }

    /**
     * the_content の中で、もう一度 the_content が走っているか（目次プラグインの見出し集めなど）
     *
     * @return bool
     */
    private static function is_nested_content_filter(): bool {
        global $wp_current_filter;
        $count = 0;
        foreach ((array) $wp_current_filter as $hook) {
            if ($hook === 'the_content') {
                $count++;
            }
        }
        return $count > 1;
    }

    /**
     * いま本文を表示している投稿（メインの1件）なら、その投稿を返す
     *
     * @return WP_Post|null
     */
    private function main_singular_post(): ?WP_Post {
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return null;
        }
        $post = get_post();
        if (!$post instanceof WP_Post || (int) $post->ID !== (int) get_queried_object_id()) {
            return null;
        }
        return $post;
    }

    /**
     * 目印を作る
     *
     * @param int $ranking_id ランキング ID
     * @return string
     */
    private function make_slot(int $ranking_id): string {
        $token = strtolower(wp_generate_password(16, false, false));
        $this->slots[$token] = $ranking_id;
        return '<div ' . self::SLOT_ATTR . '="' . esc_attr($token) . '"></div>';
    }

    /**
     * 本文の上・下に目印を置く（the_content 優先度 1）
     *
     * @param mixed $content 本文
     * @return mixed
     */
    public function insert_slots($content) {
        if (!is_string($content) || is_feed() || $this->is_non_display_context() || self::is_nested_content_filter()) {
            return $content;
        }
        $post = $this->main_singular_post();
        if ($post === null || post_password_required($post) || !$this->settings->is_enabled_post_type($post->post_type)) {
            return $content;
        }
        $sel = $this->rankings->get_post_selection((int) $post->ID);
        if ($sel['ranking_id'] <= 0 || !in_array($sel['position'], ['above', 'below'], true)) {
            return $content;
        }
        if (!$this->rankings->is_displayable($sel['ranking_id'])) {
            return $content;
        }
        // 複数ページに分けた投稿（<!--nextpage-->）は、上は最初のページ、下は最後のページだけ
        global $page, $numpages;
        $current = max(1, (int) $page);
        $total   = max(1, (int) $numpages);
        if ($sel['position'] === 'above') {
            if ($current > 1) {
                return $content;
            }
            return $this->make_slot($sel['ranking_id']) . "\n\n" . $content;
        }
        if ($current < $total) {
            return $content;
        }
        return $content . "\n\n" . $this->make_slot($sel['ranking_id']);
    }

    /**
     * 目印をランキングの HTML に置き換える（the_content 優先度 999）
     *
     * @param mixed $content 本文
     * @return mixed
     */
    public function replace_slots($content) {
        if (!is_string($content) || strpos($content, self::SLOT_ATTR) === false) {
            return $content;
        }
        // 段落の自動整形で <p> に包まれた目印は <p> ごと、そうでない目印はそれだけを置き換える
        // （片側の <p> だけを消すと、前後の段落のタグの対応が崩れるため）
        $slot    = '<div ' . preg_quote(self::SLOT_ATTR, '#') . '="([a-z0-9]+)"></div>';
        $pattern = '#<p>\s*' . $slot . '\s*</p>|' . $slot . '#';
        $token_of = static function (array $match): string {
            return isset($match[2]) && $match[2] !== '' ? $match[2] : (string) $match[1];
        };
        return (string) preg_replace_callback($pattern, function ($match) use ($content, $token_of) {
            $token = $token_of($match);
            if (!isset($this->slots[$token])) {
                return '';
            }
            // 目印は消さずに残す（同じ本文を別の処理がもう一度 the_content に通しても置き換えられるように）
            $ranking_id = $this->slots[$token];
            // 同じランキングをショートコードで本文に置いたときは、ショートコードの位置だけにする（2回出さない）
            if (strpos($content, 'data-kashiwazaki-seo-recommended-ranking="' . $ranking_id . '"') !== false) {
                return '';
            }
            return $this->renderer->render($ranking_id);
        }, $content);
    }

    /**
     * ショートコード [kashiwazaki_seo_recommended_ranking id="123"]
     * id を省いたときは、そのページの「Kashiwazaki SEO Recommended Ranking」のボックスで選んだランキングを表示する。
     *
     * @param mixed $atts 属性
     * @return string
     */
    public function shortcode($atts): string {
        $atts = shortcode_atts(['id' => ''], is_array($atts) ? $atts : [], self::SHORTCODE);
        if ($this->is_non_display_context()) {
            return '';
        }
        $id_attr    = is_scalar($atts['id']) ? trim((string) $atts['id']) : '';
        $ranking_id = absint($id_attr);
        // id を書いたのに数字でない・0 のときは何も出さない（ページで選んだランキングに置き換えない）
        if ($id_attr !== '' && ($ranking_id <= 0 || !preg_match('/^[0-9]+$/', $id_attr))) {
            return '';
        }
        if ($ranking_id <= 0) {
            $post = get_post();
            if (!$post instanceof WP_Post || !$this->settings->is_enabled_post_type($post->post_type)) {
                return '';
            }
            $sel = $this->rankings->get_post_selection((int) $post->ID);
            if ($sel['position'] === 'none') {
                return '';
            }
            $ranking_id = $sel['ranking_id'];
        }
        if ($ranking_id <= 0 || !$this->rankings->is_displayable($ranking_id)) {
            return '';
        }
        return $this->renderer->render($ranking_id);
    }

    /**
     * ランキングを表示するページでは、スタイルを head で読み込む（本文の途中で読み込むとちらつくため）
     */
    public function maybe_enqueue(): void {
        Kashiwazaki_SEO_Recommended_Ranking_Renderer::register_style();
        if (!is_singular()) {
            return;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || post_password_required($post)) {
            return;
        }
        $need = false;
        if ($this->settings->is_enabled_post_type($post->post_type)) {
            $sel  = $this->rankings->get_post_selection((int) $post->ID);
            $need = $sel['ranking_id'] > 0 && $sel['position'] !== 'none' && $this->rankings->is_displayable($sel['ranking_id']);
        }
        if (!$need && has_shortcode((string) $post->post_content, self::SHORTCODE)) {
            $need = true;
        }
        if ($need) {
            Kashiwazaki_SEO_Recommended_Ranking_Renderer::enqueue_style();
        }
    }
}
