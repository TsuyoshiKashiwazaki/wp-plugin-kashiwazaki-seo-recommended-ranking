<?php
/**
 * 構造化データ（JSON-LD）の組み立てと出力
 *
 * 基本の形：ItemList（ランキング全体）→ ListItem（各順位）→ Recommendation（おすすめ）→ itemReviewed（おすすめする対象）
 * ランキングが記事の主題のときは、Guide（ガイド）で ItemList と各 Recommendation をまとめる。
 * 出典：https://schema.org/Recommendation ・ https://schema.org/Guide ・ https://schema.org/ItemList
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 構造化データクラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Schema {

    /**
     * 全体設定
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Settings
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Settings $settings;

    /**
     * このページで出力するデータ（ランキング ID => JSON-LD の配列）
     *
     * @var array<int,array>
     */
    private array $queue = [];

    /**
     * 出力済みのランキング ID
     *
     * @var array<int,bool>
     */
    private array $printed = [];

    /**
     * フッターでの出力を終えたか
     *
     * @var bool
     */
    private bool $footer_done = false;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_Recommended_Ranking_Settings $settings 全体設定
     */
    public function __construct(Kashiwazaki_SEO_Recommended_Ranking_Settings $settings) {
        $this->settings = $settings;
        add_action('wp_footer', [$this, 'print_queue'], 20);
    }

    /**
     * HTML5 の数値文字参照の置き換え表（0x80〜0x9F）。出典: HTML Standard 13.2.5.84 Numeric character reference end state
     */
    private const C1_REPLACEMENTS = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
        0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018,
        0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC,
        0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /**
     * JSON-LD に入れる文字列を、公開の HTML の表示と同じ文字にする。
     * 公開の HTML は esc_html（文字参照を二重に符号化しない）で出すので、保存値や本体の値（タイトル・表示名・サイト名）の中の
     * 「&lt;」「&amp;」はブラウザで「<」「&」と表示される。esc_html の出力を、ブラウザと同じ HTML5 の文字参照の規則で 1 回だけ解く
     * （解いた結果をもう一度解かない。数値文字参照は HTML5 の置き換え表で解く。PHP の html_entity_decode は 0x80〜0x9F を表のとおりに解かない）。
     *
     * @param string $s 保存値・本体の値
     * @return string
     */
    public static function plain(string $s): string {
        return self::decode_html5(esc_html($s));
    }

    /**
     * HTML の文字列（esc_html・esc_url の出力）を、ブラウザと同じ HTML5 の文字参照の規則で 1 回だけ解く
     *
     * @param string $html HTML の文字列
     * @return string
     */
    private static function decode_html5(string $html): string {
        return (string) preg_replace_callback(
            '/&(#[xX][0-9a-fA-F]+|#[0-9]+|[A-Za-z][A-Za-z0-9]*);/',
            static function (array $m): string {
                $ref = $m[1];
                if ($ref[0] !== '#') {
                    // 名前の文字参照（esc_html が残すのは本体の一覧にある名前だけ）
                    return html_entity_decode('&' . $ref . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $hex    = ($ref[1] === 'x' || $ref[1] === 'X');
                $digits = ltrim(substr($ref, $hex ? 2 : 1), '0');
                if ($digits === '') {
                    $code = 0;
                } elseif (strlen($digits) > ($hex ? 6 : 7)) {
                    // 正しい符号位置の最大（10FFFF・1114111）より桁が多いものは範囲外（32 ビットの PHP で int に収まらない値を作らない）
                    $code = 0x110000;
                } else {
                    $code = $hex ? (int) hexdec($digits) : (int) $digits;
                }
                if ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    $code = 0xFFFD;
                } elseif (isset(self::C1_REPLACEMENTS[$code])) {
                    $code = self::C1_REPLACEMENTS[$code];
                }
                return self::utf8_chr($code);
            },
            $html
        );
    }

    /**
     * 符号位置（1〜0x10FFFF、代用対を除く）を UTF-8 の 1 文字にする。
     * mb_chr は mbstring（WordPress の必須の拡張ではない）の関数なので使わない。出典: RFC 3629 3 節
     *
     * @param int $code 符号位置
     * @return string
     */
    private static function utf8_chr(int $code): string {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    /**
     * JSON-LD の url を、リンク（href）でブラウザが開く URL と同じにする（esc_url の出力が href の属性値で、ブラウザは属性値の中の文字参照を解く）
     *
     * @param string $url 保存値
     * @return string 空なら出さない
     */
    public static function plain_url(string $url): string {
        $escaped = esc_url($url);
        return $escaped === '' ? '' : self::decode_html5($escaped);
    }

    /**
     * このランキングの構造化データを出力するか
     *
     * @param array $data ランキングの内容
     * @return bool
     */
    public function is_enabled(array $data): bool {
        return (bool) $this->settings->value('schema_enabled') && !empty($data['schema_enabled']);
    }

    /**
     * 今表示しているページの URL（@id の土台）
     *
     * @return string
     */
    public function current_page_url(): string {
        if (is_singular()) {
            $url = get_permalink(get_queried_object_id());
            return is_string($url) ? $url : '';
        }
        // 一覧ページなど：サイトのドメイン＋リクエストのパス（Host ヘッダーは使わない。クエリは付けない）
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $home = wp_parse_url(home_url());
        if (!is_array($home) || empty($home['host'])) {
            return '';
        }
        $base = ($home['scheme'] ?? 'https') . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        return esc_url_raw($base . ($path !== '' ? $path : '/'));
    }

    /**
     * おすすめしている人（author）
     *
     * @return array|null
     */
    public function author_node(): ?array {
        $mode = (string) $this->settings->value('schema_author');
        if ($mode === 'none') {
            return null;
        }
        if ($mode === 'post_author' && is_singular()) {
            $post = get_queried_object();
            if ($post instanceof WP_Post && (int) $post->post_author > 0) {
                $user = get_userdata((int) $post->post_author);
                if ($user) {
                    $node = ['@type' => 'Person', 'name' => self::plain((string) $user->display_name)];
                    $url  = get_author_posts_url($user->ID);
                    if (is_string($url) && $url !== '') {
                        $node['url'] = $url;
                    }
                    return $node;
                }
            }
        }
        $name = self::plain(wp_strip_all_tags(get_bloginfo('name')));
        if ($name === '') {
            return null;
        }
        return ['@type' => 'Organization', 'name' => $name, 'url' => home_url('/')];
    }

    /**
     * JSON-LD を組み立てる
     *
     * @param array      $data       ランキングの内容（normalize 済み）
     * @param int        $ranking_id ランキング ID
     * @param string     $title      ランキングの管理用の名前（見出しが空のときに使う）
     * @param string     $page_url   ページの URL
     * @param array|null $author     author
     * @return array|null 順位が無いときは null
     */
    public function build(array $data, int $ranking_id, string $title, string $page_url, ?array $author): ?array {
        $items = Kashiwazaki_SEO_Recommended_Ranking_Rankings::visible_items($data);
        if (empty($items)) {
            return null;
        }
        $name   = self::plain($data['heading'] !== '' ? $data['heading'] : $title);
        $desc   = self::plain($data['description']);
        // @id はページの中で一意な識別子（HTML の id と一致させる必要はない）
        $base   = $page_url !== '' ? $page_url . '#kashiwazaki-seo-recommended-ranking-' . $ranking_id : '';
        $type   = in_array($data['schema_item_type'], Kashiwazaki_SEO_Recommended_Ranking_Rankings::ITEM_TYPES, true) ? $data['schema_item_type'] : 'Thing';

        $elements = [];
        $item_ids = [];
        foreach ($items as $i => $item) {
            $position = $i + 1;
            $item_name = self::plain($item['name']);
            $reviewed  = ['@type' => $type, 'name' => $item_name];
            $item_url  = $item['url'] !== '' ? self::plain_url($item['url']) : '';
            if ($item_url !== '') {
                $reviewed['url'] = $item_url;
            }
            $rec = ['@type' => 'Recommendation'];
            if ($base !== '') {
                $rec['@id']  = $base . '-item-' . $position;
                $item_ids[] = ['@id' => $rec['@id']];
            }
            $rec['name']     = $item_name;
            $rec['position'] = $position;
            if ($item['description'] !== '') {
                $rec['reviewBody'] = self::plain($item['description']);
            }
            $rec['itemReviewed'] = $reviewed;
            if ($author !== null) {
                $rec['author'] = $author;
            }
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'item'     => $rec,
            ];
        }

        $list = ['@type' => 'ItemList'];
        if ($base !== '') {
            $list['@id'] = $base . '-list';
        }
        if ($name !== '') {
            $list['name'] = $name;
        }
        if ($desc !== '') {
            $list['description'] = $desc;
        }
        $list['numberOfItems']   = count($elements);
        $list['itemListOrder']   = 'https://schema.org/ItemListOrderAscending';
        $list['itemListElement'] = $elements;

        $graph = [$list];
        if ($data['schema_wrapper'] === 'guide') {
            $guide = ['@type' => 'Guide'];
            if ($base !== '') {
                $guide['@id'] = $base . '-guide';
            }
            if ($name !== '') {
                $guide['name'] = $name;
            }
            if ($desc !== '') {
                $guide['description'] = $desc;
            }
            if ($author !== null) {
                $guide['author'] = $author;
            }
            if ($base !== '') {
                $guide['mainEntity'] = ['@id' => $list['@id']];
                $guide['hasPart']    = $item_ids;
            } else {
                $guide['mainEntity'] = $list;
                $graph               = [];
            }
            $graph[] = $guide;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
    }

    /**
     * JSON にする（</script> で抜け出せないよう、< > & をエスケープする）
     *
     * @param array $json  JSON-LD
     * @param bool  $pretty 整形するか
     * @return string
     */
    public static function encode(array $json, bool $pretty = false): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $out = wp_json_encode($json, $flags);
        return is_string($out) ? $out : '';
    }

    /**
     * script タグにする
     *
     * @param array $json JSON-LD
     * @return string
     */
    public static function script_tag(array $json): string {
        $encoded = self::encode($json);
        if ($encoded === '') {
            return '';
        }
        return '<script type="application/ld+json" class="kashiwazaki-seo-recommended-ranking-schema">' . $encoded . '</script>' . "\n";
    }

    /**
     * 表示したランキングの構造化データを登録する（同じランキングは 1 回だけ）。
     * フッターをすでに出力し終えたあとに表示したときは、その場で出す script を返す。
     *
     * @param int   $ranking_id ランキング ID
     * @param array $json       JSON-LD
     * @return string その場で出す script（ふだんは空）
     */
    public function add(int $ranking_id, array $json): string {
        if (isset($this->queue[$ranking_id]) || isset($this->printed[$ranking_id])) {
            return '';
        }
        if ($this->footer_done || (did_action('wp_footer') && !doing_action('wp_footer'))) {
            $this->printed[$ranking_id] = true;
            return self::script_tag($json);
        }
        $this->queue[$ranking_id] = $json;
        return '';
    }

    /**
     * フッターで出力する
     */
    public function print_queue(): void {
        foreach ($this->queue as $ranking_id => $json) {
            if (isset($this->printed[$ranking_id])) {
                continue;
            }
            $this->printed[$ranking_id] = true;
            // script_tag の中身は encode で < > & をエスケープ済み
            echo self::script_tag($json); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        $this->queue       = [];
        $this->footer_done = true;
    }
}
