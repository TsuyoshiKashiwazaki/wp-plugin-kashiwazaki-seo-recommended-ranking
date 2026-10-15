<?php
/**
 * ランキング（投稿タイプ）の登録・データの読み書き・一覧・複製
 *
 * @package Kashiwazaki_SEO_Recommended_Ranking
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ランキング管理クラス
 */
class Kashiwazaki_SEO_Recommended_Ranking_Rankings {

    /**
     * 投稿タイプ（WordPress の制限で 20 文字以内）
     */
    public const POST_TYPE = 'kashiwazaki_ranking';

    /**
     * ランキングの内容を入れるメタ
     */
    public const META_DATA = '_kashiwazaki_seo_recommended_ranking_data';

    /**
     * 各ページで選んだランキングの ID
     */
    public const META_POST_RANKING = '_kashiwazaki_seo_recommended_ranking_ranking_id';

    /**
     * 各ページで選んだ表示位置
     */
    public const META_POST_POSITION = '_kashiwazaki_seo_recommended_ranking_position';

    /**
     * 順位の最小数・最大数
     */
    public const MIN_ITEMS = 3;
    public const MAX_ITEMS = 10;

    /**
     * 入力の長さの上限（文字数）
     */
    public const MAX_NAME        = 200;
    public const MAX_ITEM_DESC   = 1000;
    public const MAX_HEADING     = 200;
    public const MAX_DESCRIPTION = 2000;
    public const MAX_BUTTON      = 40;
    public const MAX_URL         = 2048;

    /**
     * 見出しのタグ
     */
    public const HEADING_TAGS = ['h2', 'h3', 'h4', 'p', 'none'];

    /**
     * 外部リンクの rel
     */
    public const RELS = ['', 'nofollow', 'sponsored', 'sponsored nofollow'];

    /**
     * 構造化データ：おすすめする対象の種類
     */
    public const ITEM_TYPES = ['Thing', 'Product', 'Service'];

    /**
     * 構造化データ：まとめの形
     */
    public const WRAPPERS = ['itemlist', 'guide'];

    /**
     * 表示位置
     */
    public const POSITIONS = ['below', 'above', 'manual', 'none'];

    /**
     * デザインの型（表示名と説明は Kashiwazaki_SEO_Recommended_Ranking_Settings::design_choices()）
     */
    public const DESIGNS = ['standard', 'crown', 'minimal', 'table', 'podium'];

    /**
     * 全体設定
     *
     * @var Kashiwazaki_SEO_Recommended_Ranking_Settings
     */
    private Kashiwazaki_SEO_Recommended_Ranking_Settings $settings;

    /**
     * このリクエストで保存時に判定した結果（投稿 ID => 状態）
     *
     * @var array<int,array>
     */
    private array $save_state = [];

    /**
     * 使用数のキャッシュ（一覧の表示中だけ）
     *
     * @var array<int,int>|null
     */
    private ?array $usage_counts = null;

    /**
     * 使用状況の元データ（リクエスト中のキャッシュ）
     *
     * @var array|null
     */
    private ?array $usage_rows = null;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_Recommended_Ranking_Settings $settings 全体設定
     */
    public function __construct(Kashiwazaki_SEO_Recommended_Ranking_Settings $settings) {
        $this->settings = $settings;

        add_action('init', [$this, 'register_post_type']);
        add_filter('wp_insert_post_data', [$this, 'guard_publish'], 10, 2);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
        add_filter('redirect_post_location', [$this, 'redirect_with_notice'], 10, 2);
        add_action('before_delete_post', [$this, 'cleanup_references'], 10, 1);
        add_action('save_post_' . self::POST_TYPE, [$this, 'purge_pages'], 99, 1);
        add_action('trashed_post', [$this, 'purge_pages'], 10, 1);
        add_action('untrashed_post', [$this, 'purge_pages'], 10, 1);
        add_action('admin_post_kashiwazaki_seo_recommended_ranking_duplicate', [$this, 'handle_duplicate']);
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'column_content'], 10, 2);
        add_filter('post_updated_messages', [$this, 'updated_messages']);
        add_filter('bulk_post_updated_messages', [$this, 'bulk_messages'], 10, 2);
        add_filter('enter_title_here', [$this, 'title_placeholder'], 10, 2);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('removable_query_args', [$this, 'removable_query_args']);
    }

    /**
     * 通知を出したあと、再読み込みでまた出ないよう URL から外す引数
     *
     * @param array $args 引数
     * @return array
     */
    public function removable_query_args($args) {
        $args   = is_array($args) ? $args : [];
        $args[] = 'kashiwazaki_seo_recommended_ranking_notice';
        return $args;
    }

    /**
     * 投稿タイプを登録（init）
     */
    public function register_post_type(): void {
        $labels = [
            // 一覧の画面の見出し（設定の画面と同じく、プラグイン名をフル表記で出す）
            'name'                  => __('Kashiwazaki SEO Recommended Ranking', 'kashiwazaki-seo-recommended-ranking'),
            'singular_name'         => __('ランキング', 'kashiwazaki-seo-recommended-ranking'),
            'menu_name'             => __('Kashiwazaki SEO Recommended Ranking', 'kashiwazaki-seo-recommended-ranking'),
            'name_admin_bar'        => __('ランキング', 'kashiwazaki-seo-recommended-ranking'),
            'all_items'             => __('ランキング一覧', 'kashiwazaki-seo-recommended-ranking'),
            'add_new'               => __('新規作成', 'kashiwazaki-seo-recommended-ranking'),
            'add_new_item'          => __('ランキングを新規作成', 'kashiwazaki-seo-recommended-ranking'),
            'edit_item'             => __('ランキングを編集', 'kashiwazaki-seo-recommended-ranking'),
            'new_item'              => __('新しいランキング', 'kashiwazaki-seo-recommended-ranking'),
            'view_item'             => __('ランキングを表示', 'kashiwazaki-seo-recommended-ranking'),
            'search_items'          => __('ランキングを検索', 'kashiwazaki-seo-recommended-ranking'),
            'not_found'             => __('ランキングがありません', 'kashiwazaki-seo-recommended-ranking'),
            'not_found_in_trash'    => __('ゴミ箱にランキングはありません', 'kashiwazaki-seo-recommended-ranking'),
            'filter_items_list'     => __('ランキングの絞り込み', 'kashiwazaki-seo-recommended-ranking'),
            'items_list_navigation' => __('ランキング一覧のページ送り', 'kashiwazaki-seo-recommended-ranking'),
            'items_list'            => __('ランキング一覧', 'kashiwazaki-seo-recommended-ranking'),
        ];
        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'description'         => __('Kashiwazaki SEO Recommended Ranking のおすすめランキング', 'kashiwazaki-seo-recommended-ranking'),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 81,
            'menu_icon'           => 'dashicons-awards',
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'supports'            => ['title', 'author'],
            'rewrite'             => false,
            'query_var'           => false,
            'has_archive'         => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'can_export'          => true,
            'delete_with_user'    => false,
        ]);
    }

    /**
     * ランキングの既定値
     *
     * @return array
     */
    public static function default_data(): array {
        return [
            'heading'          => '',
            'heading_tag'      => 'h2',
            'description'      => '',
            'items'            => [],
            'button_label'     => '',
            'design'           => '', // 空は「全体の設定に従う」
            'new_tab'          => false,
            'rel'              => '',
            'schema_enabled'   => true,
            'schema_item_type' => 'Thing',
            'schema_wrapper'   => 'itemlist',
        ];
    }

    /**
     * ランキングの投稿を取得（種類が違う・存在しないときは null）
     *
     * @param int $ranking_id ランキング ID
     * @return WP_Post|null
     */
    public function get_post(int $ranking_id): ?WP_Post {
        if ($ranking_id <= 0) {
            return null;
        }
        $post = get_post($ranking_id);
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE) {
            return null;
        }
        return $post;
    }

    /**
     * 公開中のランキングか（公開ページに表示してよいか）
     *
     * @param int $ranking_id ランキング ID
     * @return bool
     */
    public function is_displayable(int $ranking_id): bool {
        $post = $this->get_post($ranking_id);
        return $post !== null && $post->post_status === 'publish' && $post->post_password === '';
    }

    /**
     * ランキングの内容を取得（既定値で補い、型を揃える）
     *
     * @param int $ranking_id ランキング ID
     * @return array
     */
    public function get_data(int $ranking_id): array {
        $saved = get_post_meta($ranking_id, self::META_DATA, true);
        return self::normalize(is_array($saved) ? $saved : []);
    }

    /**
     * 保存済みの値（すでに検証済み）を、既定値で補って型を揃える。
     * データベースが直接書き換えられた場合などでも表示が壊れないようにする。
     *
     * @param array $data 保存値
     * @return array
     */
    public static function normalize(array $data): array {
        $d   = self::default_data();
        $out = [];
        $out['heading']          = is_string($data['heading'] ?? null) ? $data['heading'] : $d['heading'];
        $out['heading_tag']      = self::pick($data, 'heading_tag', self::HEADING_TAGS, $d['heading_tag']);
        $out['description']      = is_string($data['description'] ?? null) ? $data['description'] : $d['description'];
        $out['button_label']     = is_string($data['button_label'] ?? null) ? $data['button_label'] : $d['button_label'];
        $out['design']           = self::pick($data, 'design', array_merge([''], self::DESIGNS), $d['design']);
        $out['new_tab']          = !empty($data['new_tab']);
        $out['rel']              = self::pick($data, 'rel', self::RELS, $d['rel']);
        $out['schema_enabled']   = array_key_exists('schema_enabled', $data) ? !empty($data['schema_enabled']) : $d['schema_enabled'];
        $out['schema_item_type'] = self::pick($data, 'schema_item_type', self::ITEM_TYPES, $d['schema_item_type']);
        $out['schema_wrapper']   = self::pick($data, 'schema_wrapper', self::WRAPPERS, $d['schema_wrapper']);

        $items = [];
        if (is_array($data['items'] ?? null)) {
            foreach ($data['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'name'        => is_string($item['name'] ?? null) ? $item['name'] : '',
                    'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
                    'url'         => is_string($item['url'] ?? null) ? $item['url'] : '',
                ];
                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
            }
        }
        $out['items'] = $items;
        return $out;
    }

    /**
     * 決まった選択肢の中の値だけを受け付ける（無い・違うときは既定値）
     *
     * @param array  $source  元の配列
     * @param string $key     キー
     * @param array  $allowed 選択肢
     * @param string $default 既定値
     * @return string
     */
    private static function pick(array $source, string $key, array $allowed, string $default): string {
        $value = $source[$key] ?? null;
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * 名称が入っている順位だけを返す（公開ページの表示用）
     *
     * @param array $data ランキングの内容
     * @return array
     */
    public static function visible_items(array $data): array {
        $items = [];
        foreach ($data['items'] as $item) {
            if (trim($item['name']) !== '') {
                $items[] = $item;
            }
        }
        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * 文字数を上限で切る
     *
     * @param string $text 文字列
     * @param int    $max  上限
     * @return string
     */
    public static function cut(string $text, int $max): string {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * テキストを整形する（タグを除き、不正な文字コード・制御文字を取り除く）。
     * sanitize_text_field は「%20」のような「% と 16 進数 2 桁」を消すため（「10%20代」が「10代」になる）、
     * それを除いた同じ手順で整形する。
     *
     * @param string $text      入力
     * @param bool   $multiline 改行を残すか
     * @return string
     */
    public static function clean_text(string $text, bool $multiline = false): string {
        $text = wp_check_invalid_utf8($text);
        if (strpos($text, '<') !== false) {
            $text = wp_pre_kses_less_than($text);
            $text = wp_strip_all_tags($text, false);
            $text = str_replace("<\n", "&lt;\n", $text);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // 改行・タブ以外の制御文字を取り除く
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if ($multiline) {
            $lines = array_map(static function (string $line): string {
                return trim((string) preg_replace('/[\t ]+/u', ' ', $line));
            }, explode("\n", $text));
            $text = implode("\n", $lines);
            // 3行以上の空行は1行にまとめる
            $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        } else {
            $text = (string) preg_replace('/[\r\n\t ]+/u', ' ', $text);
        }
        return trim($text);
    }

    /**
     * URL を検証する。http・https の絶対 URL だけを受け付ける。
     * 「/」で始まるサイト内のパスは、サイトのドメインを付けた絶対 URL にする。
     *
     * @param string $url 入力
     * @return string 受け付けない形なら空文字
     */
    public static function sanitize_url(string $url): string {
        $url = trim(wp_strip_all_tags($url));
        if ($url === '') {
            return '';
        }
        $url = self::cut($url, self::MAX_URL);
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (strpos($url, '/') === 0) {
            $home  = wp_parse_url(home_url());
            $base  = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '');
            $base .= isset($home['port']) ? ':' . $home['port'] : '';
            $url   = $base . $url;
        }
        $clean = esc_url_raw($url, ['http', 'https']);
        if ($clean === '' || !preg_match('#^https?://#i', $clean)) {
            return '';
        }
        $host = wp_parse_url($clean, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        return $clean;
    }

    /**
     * 編集画面から送られた値を検証して、保存する形にする
     *
     * @param mixed $raw 入力（wp_unslash 済み）
     * @return array{data: array, invalid_rows: int, named: int}
     */
    public static function sanitize_input($raw): array {
        $raw  = is_array($raw) ? $raw : [];
        $d    = self::default_data();
        $data = [];

        $data['heading']      = self::cut(self::clean_text(is_scalar($raw['heading'] ?? null) ? (string) $raw['heading'] : ''), self::MAX_HEADING);
        $data['heading_tag']  = self::pick($raw, 'heading_tag', self::HEADING_TAGS, $d['heading_tag']);
        $data['description']  = self::cut(self::clean_text(is_scalar($raw['description'] ?? null) ? (string) $raw['description'] : '', true), self::MAX_DESCRIPTION);
        $data['button_label'] = self::cut(self::clean_text(is_scalar($raw['button_label'] ?? null) ? (string) $raw['button_label'] : ''), self::MAX_BUTTON);
        $data['design']       = self::pick($raw, 'design', array_merge([''], self::DESIGNS), $d['design']);
        $data['new_tab']      = !empty($raw['new_tab']);
        $data['rel']          = self::pick($raw, 'rel', self::RELS, $d['rel']);
        $data['schema_enabled']   = !empty($raw['schema_enabled']);
        $data['schema_item_type'] = self::pick($raw, 'schema_item_type', self::ITEM_TYPES, $d['schema_item_type']);
        $data['schema_wrapper']   = self::pick($raw, 'schema_wrapper', self::WRAPPERS, $d['schema_wrapper']);

        $items        = [];
        $invalid_rows = 0;
        $named        = 0;
        if (is_array($raw['items'] ?? null)) {
            foreach ($raw['items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $typed_url = is_scalar($row['url'] ?? null) ? trim((string) $row['url']) : '';
                $name = self::cut(self::clean_text(is_scalar($row['name'] ?? null) ? (string) $row['name'] : ''), self::MAX_NAME);
                $desc = self::cut(self::clean_text(is_scalar($row['description'] ?? null) ? (string) $row['description'] : '', true), self::MAX_ITEM_DESC);
                $url  = self::sanitize_url($typed_url);
                // すべて空の行は捨てる
                if ($name === '' && $desc === '' && $typed_url === '') {
                    continue;
                }
                if ($name === '') {
                    $invalid_rows++;
                } else {
                    $named++;
                }
                $items[] = ['name' => $name, 'description' => $desc, 'url' => $url];
                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
            }
        }
        $data['items'] = $items;

        return ['data' => $data, 'invalid_rows' => $invalid_rows, 'named' => $named];
    }

    /**
     * 公開できる内容か（名称のある順位が 3 つ以上あり、名称の空いた順位が無い）
     *
     * @param int $named        名称のある順位の数
     * @param int $invalid_rows 名称が空の順位の数
     * @return bool
     */
    public static function is_publishable(int $named, int $invalid_rows): bool {
        return $named >= self::MIN_ITEMS && $invalid_rows === 0;
    }

    /**
     * 編集画面から送られた値を取り出す（nonce と権限を確かめたときだけ）
     *
     * @param int $post_id 投稿 ID（新規のときは 0）
     * @return array|null
     */
    private function submitted_input(int $post_id): ?array {
        if (!isset($_POST['kashiwazaki_seo_recommended_ranking_ranking_nonce'], $_POST['kashiwazaki_seo_recommended_ranking'])) {
            return null;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['kashiwazaki_seo_recommended_ranking_ranking_nonce']));
        if (!wp_verify_nonce($nonce, 'kashiwazaki_seo_recommended_ranking_save_ranking')) {
            return null;
        }
        if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
            return null;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_input で検証する
        return self::sanitize_input(wp_unslash($_POST['kashiwazaki_seo_recommended_ranking']));
    }

    /**
     * 公開の前に、順位の数を確かめる（wp_insert_post_data）。
     * - まだ公開していないランキング：3位までそろっていなければ下書きのまま保存する。
     * - 公開中のランキング：公開は続け、順位の変更だけを保存しない（設置したページから消えないように）。
     *
     * @param array $data    保存する投稿の値
     * @param array $postarr 元の値
     * @return array
     */
    public function guard_publish($data, $postarr) {
        if (!is_array($data) || ($data['post_type'] ?? '') !== self::POST_TYPE) {
            return $data;
        }
        $status = (string) ($data['post_status'] ?? '');
        if (!in_array($status, ['publish', 'future'], true)) {
            return $data;
        }
        // 確かめるのは管理画面からの保存（編集画面・クイック編集・一括編集）だけ。
        // サイトの移行（インポート）や WP-CLI は、投稿を作ってからメタを入れるため、ここで止めると下書きに落ちてしまう
        if (!is_admin() || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return $data;
        }
        $post_id   = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        $submitted = $this->submitted_input($post_id);
        if ($submitted !== null) {
            $ok = self::is_publishable($submitted['named'], $submitted['invalid_rows']);
        } else {
            // 一覧のクイック編集など、内容を送らない保存は保存済みの内容で判定する
            $saved = $post_id > 0 ? $this->get_data($post_id) : self::default_data();
            $named = count(self::visible_items($saved));
            $ok    = self::is_publishable($named, count($saved['items']) - $named);
        }
        if ($ok) {
            return $data;
        }
        $prev = $post_id > 0 ? get_post_status($post_id) : false;
        if ($submitted !== null && in_array($prev, ['publish', 'future'], true)) {
            // 公開中：公開は続け、順位は前の内容のままにする
            $this->save_state[$post_id] = ['keep_items' => true];
            return $data;
        }
        $data['post_status'] = 'draft';
        if ($post_id > 0) {
            $this->save_state[$post_id] = ['forced_draft' => true];
        }
        return $data;
    }

    /**
     * ランキングの内容を保存（save_post_{post_type}）
     *
     * @param int     $post_id 投稿 ID
     * @param WP_Post $post    投稿
     */
    public function save_meta($post_id, $post): void {
        $post_id = (int) $post_id;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $submitted = $this->submitted_input($post_id);
        if ($submitted === null) {
            return;
        }
        $data = $submitted['data'];
        if (!empty($this->save_state[$post_id]['keep_items'])) {
            $data['items'] = $this->get_data($post_id)['items'];
        }
        // update_post_meta は値の「\」を外すため、wp_slash してから渡す
        update_post_meta($post_id, self::META_DATA, wp_slash($data));
        $this->usage_counts = null;
        $this->usage_rows   = null;
    }

    /**
     * 保存後の画面に、公開できなかった理由を出す
     *
     * @param string $location 戻り先
     * @param int    $post_id  投稿 ID
     * @return string
     */
    public function redirect_with_notice($location, $post_id) {
        $post_id = (int) $post_id;
        if (!isset($this->save_state[$post_id]) || !is_string($location)) {
            return $location;
        }
        $state = $this->save_state[$post_id];
        if (!empty($state['forced_draft'])) {
            // 「公開しました」ではなく「下書きを保存しました」を出す
            $location = add_query_arg(['message' => 10, 'kashiwazaki_seo_recommended_ranking_notice' => 'draft'], $location);
        } elseif (!empty($state['keep_items'])) {
            $location = add_query_arg(['kashiwazaki_seo_recommended_ranking_notice' => 'kept'], $location);
        }
        return $location;
    }

    /**
     * 管理画面の通知
     */
    public function admin_notices(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示だけ
        $notice = isset($_GET['kashiwazaki_seo_recommended_ranking_notice']) ? sanitize_key(wp_unslash($_GET['kashiwazaki_seo_recommended_ranking_notice'])) : '';
        // phpcs:enable
        $messages = [
            'draft'      => ['warning', sprintf(
                /* translators: %d: minimum number of ranks */
                __('公開するには、%d位までの名称を入力してください（名称が空の順位があるときも公開できません）。下書きとして保存しました。', 'kashiwazaki-seo-recommended-ranking'),
                self::MIN_ITEMS
            )],
            'kept'       => ['error', sprintf(
                /* translators: %d: minimum number of ranks */
                __('公開中のランキングは、%d位以上の名称が必要です（名称が空の順位も保存できません）。順位の変更は保存せず、前の内容のままにしました。そのほかの項目は保存しました。', 'kashiwazaki-seo-recommended-ranking'),
                self::MIN_ITEMS
            )],
            'duplicated' => ['success', __('ランキングを複製しました。複製は下書きです。内容を確かめてから公開してください。', 'kashiwazaki-seo-recommended-ranking')],
        ];
        if (!isset($messages[$notice])) {
            return;
        }
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($messages[$notice][0]),
            esc_html($messages[$notice][1])
        );
    }

    /**
     * ランキングを使っているページのキャッシュを消す。
     * clean_post_cache は WordPress のオブジェクトキャッシュを消し、多くのページキャッシュのプラグインも
     * この合図でそのページのキャッシュを消す（ランキングの更新を、設置したページにすぐ反映させるため）。
     *
     * @param int $post_id ランキング ID
     */
    public function purge_pages($post_id): void {
        $post_id = (int) $post_id;
        if (get_post_type($post_id) !== self::POST_TYPE || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $this->usage_rows   = null;
        $this->usage_counts = null;
        foreach ($this->get_usage($post_id) as $u) {
            clean_post_cache($u['post_id']);
        }
    }

    /**
     * 完全に削除するランキングを選んでいたページから、その選択を外す
     *
     * @param int $post_id 削除する投稿 ID
     */
    public function cleanup_references($post_id): void {
        $post = get_post((int) $post_id);
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            self::META_POST_RANKING,
            (string) $post->ID
        ));
        foreach ((array) $ids as $id) {
            delete_post_meta((int) $id, self::META_POST_RANKING);
            delete_post_meta((int) $id, self::META_POST_POSITION);
        }
        // ショートコードで使っていたページも含めて、キャッシュを消す
        $this->purge_pages($post->ID);
    }

    /**
     * 一覧の行に「複製」を追加
     *
     * @param array   $actions 操作
     * @param WP_Post $post    投稿
     * @return array
     */
    public function row_actions($actions, $post) {
        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE || !is_array($actions)) {
            return $actions;
        }
        if ($post->post_status === 'trash' || !$this->can_duplicate($post)) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=kashiwazaki_seo_recommended_ranking_duplicate&post=' . $post->ID),
            'kashiwazaki_seo_recommended_ranking_duplicate_' . $post->ID
        );
        $actions['kashiwazaki_seo_recommended_ranking_duplicate'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            /* translators: %s: ranking title */
            esc_attr(sprintf(__('「%s」を複製', 'kashiwazaki-seo-recommended-ranking'), get_the_title($post))),
            esc_html__('複製', 'kashiwazaki-seo-recommended-ranking')
        );
        return $actions;
    }

    /**
     * 複製してよいか（元を編集でき、新しく作れる）
     *
     * @param WP_Post $post 元のランキング
     * @return bool
     */
    private function can_duplicate(WP_Post $post): bool {
        $type = get_post_type_object(self::POST_TYPE);
        return $type !== null
            && current_user_can('edit_post', $post->ID)
            && current_user_can($type->cap->create_posts);
    }

    /**
     * 複製（admin-post.php）
     */
    public function handle_duplicate(): void {
        $source_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        check_admin_referer('kashiwazaki_seo_recommended_ranking_duplicate_' . $source_id);
        $source = $this->get_post($source_id);
        if ($source === null || $source->post_status === 'trash') {
            wp_die(esc_html__('複製するランキングが見つかりません。', 'kashiwazaki-seo-recommended-ranking'), '', ['response' => 404, 'back_link' => true]);
        }
        if (!$this->can_duplicate($source)) {
            wp_die(esc_html__('このランキングを複製する権限がありません。', 'kashiwazaki-seo-recommended-ranking'), '', ['response' => 403, 'back_link' => true]);
        }
        $title = $source->post_title !== '' ? $source->post_title : __('（無題）', 'kashiwazaki-seo-recommended-ranking');
        $new_id = wp_insert_post(wp_slash([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'draft',
            /* translators: %s: original ranking title */
            'post_title'  => sprintf(__('%s のコピー', 'kashiwazaki-seo-recommended-ranking'), $title),
            'post_author' => get_current_user_id(),
        ]), true);
        if (is_wp_error($new_id) || !$new_id) {
            wp_die(esc_html__('複製できませんでした。', 'kashiwazaki-seo-recommended-ranking'), '', ['response' => 500, 'back_link' => true]);
        }
        update_post_meta((int) $new_id, self::META_DATA, wp_slash($this->get_data($source->ID)));
        wp_safe_redirect(add_query_arg(
            ['post' => (int) $new_id, 'action' => 'edit', 'kashiwazaki_seo_recommended_ranking_notice' => 'duplicated'],
            admin_url('post.php')
        ));
        exit;
    }

    /**
     * 一覧の列
     *
     * @param array $columns 列
     * @return array
     */
    public function columns($columns) {
        $columns = is_array($columns) ? $columns : [];
        $out     = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'title') {
                $out['kashiwazaki_seo_recommended_ranking_items']     = __('順位', 'kashiwazaki-seo-recommended-ranking');
                $out['kashiwazaki_seo_recommended_ranking_shortcode'] = __('ショートコード', 'kashiwazaki-seo-recommended-ranking');
                $out['kashiwazaki_seo_recommended_ranking_usage']     = __('使っているページ', 'kashiwazaki-seo-recommended-ranking');
            }
        }
        return $out;
    }

    /**
     * 一覧の列の中身
     *
     * @param string $column  列
     * @param int    $post_id 投稿 ID
     */
    public function column_content($column, $post_id): void {
        $post_id = (int) $post_id;
        if ($column === 'kashiwazaki_seo_recommended_ranking_items') {
            // 中身（順位の数・見出し）は、編集できる人か、公開ページに表示されるもの（公開かつパスワード無し）だけ
            if (!current_user_can('edit_post', $post_id) && !$this->is_displayable($post_id)) {
                echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('表示する権限がありません', 'kashiwazaki-seo-recommended-ranking') . '</span>';
                return;
            }
            $data  = $this->get_data($post_id);
            $count = count(self::visible_items($data));
            /* translators: %d: number of ranks */
            echo esc_html(sprintf(__('%d位まで', 'kashiwazaki-seo-recommended-ranking'), $count));
            if ($data['heading'] !== '') {
                echo '<br><span class="description">' . esc_html($data['heading']) . '</span>';
            }
        } elseif ($column === 'kashiwazaki_seo_recommended_ranking_shortcode') {
            $code = sprintf('[%s id="%d"]', Kashiwazaki_SEO_Recommended_Ranking_Frontend::SHORTCODE, $post_id);
            printf(
                '<input type="text" class="kashiwazaki-seo-recommended-ranking-shortcode-field kashiwazaki-seo-recommended-ranking-select-on-focus" readonly value="%s" aria-label="%s">',
                esc_attr($code),
                esc_attr__('ショートコード', 'kashiwazaki-seo-recommended-ranking')
            );
        } elseif ($column === 'kashiwazaki_seo_recommended_ranking_usage') {
            $counts = $this->get_usage_counts();
            $n      = $counts[$post_id] ?? 0;
            $edit   = get_edit_post_link($post_id, 'raw');
            /* translators: %d: number of pages */
            $label = sprintf(_n('%d件', '%d件', $n, 'kashiwazaki-seo-recommended-ranking'), $n);
            if ($n > 0 && $edit) {
                printf('<a href="%s">%s</a>', esc_url($edit . '#kashiwazaki-seo-recommended-ranking-usage'), esc_html($label));
            } elseif ($n > 0) {
                echo esc_html($label);
            } else {
                echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('なし', 'kashiwazaki-seo-recommended-ranking') . '</span>';
            }
        }
    }

    /**
     * 使用状況を調べる対象の状態
     *
     * @return string[]
     */
    private static function usage_statuses(): array {
        return ['publish', 'future', 'draft', 'pending', 'private'];
    }

    /**
     * ランキングごとの、使っているページの数（選択とショートコードの合計。同じページは 1 件）
     *
     * @return array<int,int>
     */
    public function get_usage_counts(): array {
        if ($this->usage_counts !== null) {
            return $this->usage_counts;
        }
        $map = [];
        foreach ($this->collect_usage() as $row) {
            $map[$row['ranking_id']][$row['post_id']] = true;
        }
        $counts = [];
        foreach ($map as $ranking_id => $posts) {
            $counts[(int) $ranking_id] = count($posts);
        }
        $this->usage_counts = $counts;
        return $counts;
    }

    /**
     * 1つのランキングを使っているページ
     *
     * @param int $ranking_id ランキング ID
     * @return array<int,array{post_id:int,via:string[],position:string}>
     */
    public function get_usage(int $ranking_id): array {
        $out = [];
        foreach ($this->collect_usage() as $row) {
            if ($row['ranking_id'] !== $ranking_id) {
                continue;
            }
            $pid = $row['post_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['post_id' => $pid, 'via' => [], 'position' => ''];
            }
            $out[$pid]['via'][] = $row['via'];
            if ($row['via'] === 'select') {
                $out[$pid]['position'] = $row['position'];
            }
        }
        return array_values($out);
    }

    /**
     * 使用状況を集める（選択したページと、ショートコードで ID を書いたページ）
     *
     * @return array<int,array{ranking_id:int,post_id:int,via:string,position:string}>
     */
    private function collect_usage(): array {
        if ($this->usage_rows !== null) {
            return $this->usage_rows;
        }
        global $wpdb;
        $rows     = [];
        $statuses = self::usage_statuses();
        $in       = implode(',', array_fill(0, count($statuses), '%s'));

        // 1. 編集画面のボックスで選んだページ
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
        $selected = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS ranking_id, pos.meta_value AS position
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             LEFT JOIN {$wpdb->postmeta} pos ON pos.post_id = pm.post_id AND pos.meta_key = %s
             WHERE pm.meta_key = %s AND p.post_status IN ($in) AND p.post_type <> %s",
            array_merge([self::META_POST_POSITION, self::META_POST_RANKING], $statuses, [self::POST_TYPE])
        ), ARRAY_A);
        foreach ((array) $selected as $r) {
            $rid = (int) $r['ranking_id'];
            if ($rid <= 0) {
                continue;
            }
            $rows[] = [
                'ranking_id' => $rid,
                'post_id'    => (int) $r['post_id'],
                'via'        => 'select',
                'position'   => in_array($r['position'], self::POSITIONS, true) ? $r['position'] : 'below',
            ];
        }

        // 2. 本文に id を書いたショートコード
        $like = '%' . $wpdb->esc_like('[' . Kashiwazaki_SEO_Recommended_Ranking_Frontend::SHORTCODE) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_content LIKE %s AND post_status IN ($in) AND post_type NOT IN ('revision', %s)",
            array_merge([$like], $statuses, [self::POST_TYPE])
        ), ARRAY_A);
        foreach ((array) $posts as $p) {
            foreach (Kashiwazaki_SEO_Recommended_Ranking_Frontend::shortcode_ids_in((string) $p['post_content']) as $rid) {
                $rows[] = [
                    'ranking_id' => $rid,
                    'post_id'    => (int) $p['ID'],
                    'via'        => 'shortcode',
                    'position'   => '',
                ];
            }
        }
        $this->usage_rows = $rows;
        return $rows;
    }

    /**
     * 保存後のメッセージ（「投稿を更新しました」を「ランキングを更新しました」に）
     *
     * @param array $messages メッセージ
     * @return array
     */
    public function updated_messages($messages) {
        if (!is_array($messages)) {
            return $messages;
        }
        $m = __('ランキングを更新しました。', 'kashiwazaki-seo-recommended-ranking');
        $messages[self::POST_TYPE] = [
            0  => '',
            1  => $m,
            2  => __('カスタムフィールドを更新しました。', 'kashiwazaki-seo-recommended-ranking'),
            3  => __('カスタムフィールドを削除しました。', 'kashiwazaki-seo-recommended-ranking'),
            4  => $m,
            5  => __('リビジョンから復元しました。', 'kashiwazaki-seo-recommended-ranking'),
            6  => __('ランキングを公開しました。設置したページに表示されます。', 'kashiwazaki-seo-recommended-ranking'),
            7  => __('ランキングを保存しました。', 'kashiwazaki-seo-recommended-ranking'),
            8  => __('ランキングをレビュー待ちにしました。', 'kashiwazaki-seo-recommended-ranking'),
            9  => __('ランキングの公開を予約しました。', 'kashiwazaki-seo-recommended-ranking'),
            10 => __('ランキングの下書きを保存しました。', 'kashiwazaki-seo-recommended-ranking'),
        ];
        return $messages;
    }

    /**
     * 一括操作のメッセージ
     *
     * @param array $messages メッセージ
     * @param array $counts   件数
     * @return array
     */
    public function bulk_messages($messages, $counts) {
        if (!is_array($messages) || !is_array($counts)) {
            return $messages;
        }
        $messages[self::POST_TYPE] = [
            /* translators: %s: count */
            'updated'   => sprintf(__('%s件のランキングを更新しました。', 'kashiwazaki-seo-recommended-ranking'), number_format_i18n((int) ($counts['updated'] ?? 0))),
            /* translators: %s: count */
            'locked'    => sprintf(__('%s件のランキングは、ほかの人が編集中のため更新しませんでした。', 'kashiwazaki-seo-recommended-ranking'), number_format_i18n((int) ($counts['locked'] ?? 0))),
            /* translators: %s: count */
            'deleted'   => sprintf(__('%s件のランキングを完全に削除しました。', 'kashiwazaki-seo-recommended-ranking'), number_format_i18n((int) ($counts['deleted'] ?? 0))),
            /* translators: %s: count */
            'trashed'   => sprintf(__('%s件のランキングをゴミ箱へ移動しました。', 'kashiwazaki-seo-recommended-ranking'), number_format_i18n((int) ($counts['trashed'] ?? 0))),
            /* translators: %s: count */
            'untrashed' => sprintf(__('%s件のランキングをゴミ箱から復元しました。', 'kashiwazaki-seo-recommended-ranking'), number_format_i18n((int) ($counts['untrashed'] ?? 0))),
        ];
        return $messages;
    }

    /**
     * タイトル欄の案内
     *
     * @param string  $text 文言
     * @param WP_Post $post 投稿
     * @return string
     */
    public function title_placeholder($text, $post) {
        if ($post instanceof WP_Post && $post->post_type === self::POST_TYPE) {
            return __('管理用の名前（例：SEOツールのおすすめランキング）', 'kashiwazaki-seo-recommended-ranking');
        }
        return $text;
    }

    /**
     * ページで選ばれたランキングと表示位置
     *
     * @param int $post_id 投稿 ID
     * @return array{ranking_id:int,position:string}
     */
    public function get_post_selection(int $post_id): array {
        $rid = (int) get_post_meta($post_id, self::META_POST_RANKING, true);
        $pos = (string) get_post_meta($post_id, self::META_POST_POSITION, true);
        if (!in_array($pos, self::POSITIONS, true)) {
            $pos = 'below';
        }
        return ['ranking_id' => $rid > 0 ? $rid : 0, 'position' => $pos];
    }
}
