<?php
/**
 * ランキングの HTML を組み立てる（公開ページと管理画面のプレビューで共通）
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 表示クラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Renderer {

    /**
     * スタイルのハンドル
     */
    public const STYLE_HANDLE = 'kashiwazaki-seo-recommended-ranking';

    /**
     * 王冠の印（色は CSS の color。外部の画像・フォントを読まない）
     */
    private const CROWN_SVG = '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path fill="currentColor" d="M2.6 7.2l4.9 3.9L12 4.2l4.5 6.9 4.9-3.9-1.9 10.3H4.5zM4.6 19h14.8v1.8H4.6z"/></svg>';

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
     * 構造化データ
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Schema
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Schema $schema;

    /**
     * 同じランキングを同じページに何回表示したか（HTML の id を重ねないため）
     *
     * @var array<int,int>
     */
    private array $instances = [];

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_Recommended_Ranking_Settings $settings 全体設定
     * @param Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings ランキング
     * @param Kashiwazaki_SEO_Recommended_Ranking_Schema   $schema   構造化データ
     */
    public function __construct(
        Kashiwazaki_SEO_Recommended_Ranking_Settings $settings,
        Kashiwazaki_SEO_Recommended_Ranking_Rankings $rankings,
        Kashiwazaki_SEO_Recommended_Ranking_Schema $schema
    ) {
        $this->settings = $settings;
        $this->rankings = $rankings;
        $this->schema   = $schema;
    }

    /**
     * スタイルを登録
     */
    public static function register_style(): void {
        if (!wp_style_is(self::STYLE_HANDLE, 'registered')) {
            wp_register_style(
                self::STYLE_HANDLE,
                KASHIWAZAKI_SEO_RECOMMENDED_RANKING_URL . 'assets/css/frontend.css',
                [],
                Kashiwazaki_SEO_Recommended_Ranking::asset_ver('assets/css/frontend.css')
            );
        }
    }

    /**
     * スタイルを読み込む（まだなら）
     */
    public static function enqueue_style(): void {
        self::register_style();
        if (!wp_style_is(self::STYLE_HANDLE, 'enqueued')) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
    }

    /**
     * 公開中のランキングを表示する（公開していない・存在しないときは空）
     *
     * @param int $ranking_id ランキング ID
     * @return string
     */
    public function render(int $ranking_id): string {
        if (!$this->rankings->is_displayable($ranking_id)) {
            return '';
        }
        $post = $this->rankings->get_post($ranking_id);
        $data = $this->rankings->get_data($ranking_id);
        if ($post === null || empty(Kashiwazaki_SEO_Recommended_Ranking_Rankings::visible_items($data))) {
            return '';
        }
        $n = ($this->instances[$ranking_id] ?? 0) + 1;
        $this->instances[$ranking_id] = $n;
        $html_id = 'kashiwazaki-seo-recommended-ranking-' . $ranking_id . ($n > 1 ? '-' . $n : '');

        self::enqueue_style();
        $html = $this->render_data($data, $ranking_id, $post->post_title, $html_id);

        if ($this->schema->is_enabled($data)) {
            $json = $this->schema->build($data, $ranking_id, $post->post_title, $this->schema->current_page_url(), $this->schema->author_node());
            if ($json !== null) {
                $html .= $this->schema->add($ranking_id, $json);
            }
        }
        return $html;
    }

    /**
     * テキストを HTML にする（エスケープし、ショートコードとして読まれないよう [ ] も文字参照にする）
     *
     * @param string $text テキスト
     * @return string
     */
    public static function text(string $text): string {
        return str_replace(['[', ']'], ['&#91;', '&#93;'], esc_html($text));
    }

    /**
     * 属性値にする
     *
     * @param string $text テキスト
     * @return string
     */
    public static function attr(string $text): string {
        return str_replace(['[', ']'], ['&#91;', '&#93;'], esc_attr($text));
    }

    /**
     * 複数行のテキストを HTML にする（改行は <br>）
     *
     * @param string $text テキスト
     * @return string
     */
    public static function multiline(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        return nl2br(self::text($text), false);
    }

    /**
     * 色の明るさから、上に載せる文字の色（白か黒）を決める（WCAG の相対輝度）
     *
     * @param string $hex #rrggbb
     * @return string
     */
    public static function contrast_text(string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#ffffff';
        }
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v   = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }
        $l = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        // 白との比 (1.05)/(L+0.05) と黒との比 (L+0.05)/0.05 の大きいほう
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.05) ? '#ffffff' : '#111111';
    }

    /**
     * サイト外へのリンクか（www. の有無は同じサイトとみなす）
     *
     * @param string $url URL
     * @return bool
     */
    public static function is_external(string $url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $norm = static function (string $h): string {
            $h = strtolower($h);
            return strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
        };
        return !is_string($home) || $norm($host) !== $norm($home);
    }

    /**
     * リンクの属性
     *
     * @param string $url  URL
     * @param array  $data ランキングの内容
     * @return string
     */
    private static function link_attrs(string $url, array $data): string {
        // esc_url はパス以降の [ ] を %5B %5D にし、ホスト（IPv6）の [ ] だけを残す。
        // 残った [ ] は数値文字参照にする（ブラウザは属性値の中で解いて元のホストで開く。文字の「[」が無いのでショートコードとして読まれない）
        $attrs = ' href="' . str_replace(['[', ']'], ['&#91;', '&#93;'], esc_url($url)) . '"';
        $rel   = [];
        if ($data['rel'] !== '' && self::is_external($url)) {
            $rel = array_merge($rel, explode(' ', $data['rel']));
        }
        if (!empty($data['new_tab'])) {
            $attrs .= ' target="_blank"';
            $rel[]  = 'noopener';
        }
        $rel = array_values(array_unique(array_filter($rel)));
        if (!empty($rel)) {
            $attrs .= ' rel="' . esc_attr(implode(' ', $rel)) . '"';
        }
        return $attrs;
    }

    /**
     * ランキングの HTML（保存済みの内容・プレビューの内容のどちらにも使う）
     *
     * @param array  $data       ランキングの内容（normalize 済み）
     * @param int    $ranking_id ランキング ID（プレビューでは 0 もありうる）
     * @param string $title      管理用の名前（見出しが空のときに使う）
     * @param string $html_id    HTML の id
     * @return string
     */
    public function render_data(array $data, int $ranking_id, string $title, string $html_id): string {
        $items = Kashiwazaki_SEO_Recommended_Ranking_Rankings::visible_items($data);
        if (empty($items)) {
            return '';
        }
        $settings = $this->settings->get();
        $accent   = $settings['accent_color'];
        $button   = $data['button_label'] !== '' ? $data['button_label'] : $settings['button_label'];
        $heading  = $data['heading'] !== '' ? $data['heading'] : $title;
        $tag      = $data['heading_tag'];
        $has_head = $tag !== 'none' && trim($heading) !== '';
        $head_id  = $html_id . '-heading';

        $design  = in_array($data['design'], Kashiwazaki_SEO_Recommended_Ranking_Rankings::DESIGNS, true) ? $data['design'] : $settings['design'];
        $classes = ['kashiwazaki-seo-recommended-ranking', 'kashiwazaki-seo-recommended-ranking--design-' . $design];
        if (!empty($settings['medal_colors'])) {
            $classes[] = 'kashiwazaki-seo-recommended-ranking--medal';
        }
        if ($has_head) {
            $classes[] = 'kashiwazaki-seo-recommended-ranking--has-heading';
        }
        $style = sprintf(
            '--kashiwazaki-seo-recommended-ranking-accent:%s;--kashiwazaki-seo-recommended-ranking-accent-text:%s;',
            $accent,
            self::contrast_text($accent)
        );

        $out = '';
        // 見出しは枠（div）の外、直前に置く。
        // 目次のプラグインは「最初の見出しの直前」に目次を差し込むため、見出しが枠の中にあると目次が枠の中に入ってしまう
        if ($has_head) {
            $out .= sprintf(
                '<%1$s class="kashiwazaki-seo-recommended-ranking__heading" id="%2$s">%3$s</%1$s>' . "\n",
                $tag,
                esc_attr($head_id),
                self::text($heading)
            );
        }
        // 外側の包み（余白と回り込みの解除を持ち、幅を中身から決める置き場所での幅を決める。frontend.css の -frame の規則）
        $frame_classes = ['kashiwazaki-seo-recommended-ranking-frame'];
        if ($has_head) {
            $frame_classes[] = 'kashiwazaki-seo-recommended-ranking-frame--has-heading';
        }
        $out .= '<div class="' . esc_attr(implode(' ', $frame_classes)) . '">';
        $out .= '<div class="' . esc_attr(implode(' ', $classes)) . '" id="' . esc_attr($html_id) . '"';
        $out .= ' data-kashiwazaki-seo-recommended-ranking="' . esc_attr((string) $ranking_id) . '"';
        $out .= ' style="' . esc_attr($style) . '"';
        if ($has_head && $tag !== 'p') {
            $out .= ' role="region" aria-labelledby="' . esc_attr($head_id) . '"';
        }
        $out .= '>' . "\n";

        if (trim($data['description']) !== '') {
            $out .= '<p class="kashiwazaki-seo-recommended-ranking__lead">' . self::multiline($data['description']) . '</p>' . "\n";
        }

        $out .= '<ol class="kashiwazaki-seo-recommended-ranking__list" role="list">' . "\n";
        foreach ($items as $i => $item) {
            $rank  = $i + 1;
            $class = 'kashiwazaki-seo-recommended-ranking__item kashiwazaki-seo-recommended-ranking__item--rank-' . $rank;
            if ($rank <= 3) {
                $class .= ' kashiwazaki-seo-recommended-ranking__item--top';
            }
            $out .= '<li class="' . esc_attr($class) . '">';
            // 見出しの行（順位・名称）。型によって帯や大きな数字になる
            $out .= '<div class="kashiwazaki-seo-recommended-ranking__head">';
            $out .= '<div class="kashiwazaki-seo-recommended-ranking__rank">';
            if ($rank <= 3) {
                // 王冠の印（型によって表示する。飾りなので読み上げない）
                $out .= '<span class="kashiwazaki-seo-recommended-ranking__crown" aria-hidden="true">' . self::CROWN_SVG . '</span>';
            }
            $out .= '<span class="kashiwazaki-seo-recommended-ranking__rank-inner">';
            $out .= '<span class="kashiwazaki-seo-recommended-ranking__rank-number">' . esc_html((string) $rank) . '</span>';
            $out .= '<span class="kashiwazaki-seo-recommended-ranking__rank-suffix">' . esc_html__('位', 'kashiwazaki-seo-recommended-ranking') . '</span>';
            $out .= '</span></div>';
            $out .= '<p class="kashiwazaki-seo-recommended-ranking__name">';
            if ($item['url'] !== '') {
                $out .= '<a class="kashiwazaki-seo-recommended-ranking__name-link"' . self::link_attrs($item['url'], $data) . '>' . self::text($item['name']) . '</a>';
            } else {
                $out .= self::text($item['name']);
            }
            $out .= '</p>';
            $out .= '</div>';
            // 本文（説明・ボタン）。どちらも無ければ出さない
            $body = '';
            if (trim($item['description']) !== '') {
                $body .= '<p class="kashiwazaki-seo-recommended-ranking__desc">' . self::multiline($item['description']) . '</p>';
            }
            if ($item['url'] !== '' && $button !== '') {
                /* translators: 1: button label, 2: item name */
                $label = sprintf(__('%1$s：%2$s', 'kashiwazaki-seo-recommended-ranking'), $button, $item['name']);
                $body .= '<p class="kashiwazaki-seo-recommended-ranking__action">';
                $body .= '<a class="kashiwazaki-seo-recommended-ranking__button"' . self::link_attrs($item['url'], $data) . ' aria-label="' . self::attr($label) . '">';
                $body .= '<span class="kashiwazaki-seo-recommended-ranking__button-label">' . self::text($button) . '</span>';
                $body .= '<span class="kashiwazaki-seo-recommended-ranking__button-arrow" aria-hidden="true"></span>';
                $body .= '</a></p>';
            }
            if ($body !== '') {
                $out .= '<div class="kashiwazaki-seo-recommended-ranking__body">' . $body . '</div>';
            }
            $out .= '</li>' . "\n";
        }
        $out .= '</ol>' . "\n";
        $out .= '</div></div>' . "\n";
        return $out;
    }
}
