# Kashiwazaki SEO Recommended Ranking

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)
![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)

投稿・固定ページ・カスタム投稿に、おすすめランキングを挿入できる WordPress プラグインです。ランキングを記事とは別に一元管理し、本文の上・下への自動挿入と、ショートコードによる手動挿入に対応します。ランキングを更新すると、設置したすべてのページに反映されます。

- Plugin URI: https://www.tsuyoshikashiwazaki.jp
- Author: 柏崎剛 (Tsuyoshi Kashiwazaki) — https://www.tsuyoshikashiwazaki.jp/profile/
- Version: 1.0.0
- License: GPL v2 or later

## 主な機能

| 機能 | 説明 |
| --- | --- |
| ランキングの一元管理 | 管理画面でランキングごとに作成・編集・複製・削除できます。記事とは別に管理し、複数の記事で使い回せます。 |
| 見出しと概要説明 | 見出し（h2・h3・h4・太字の文字・表示しない）と、見出しの下の説明文を設定できます。 |
| 3位〜10位 | 1位〜3位から、最大1位〜10位まで作れます。各順位に名称・簡単な説明・URL を入力します。 |
| 順位の編集 | 順位の追加・削除と、ドラッグや矢印のボタンでの並べ替えに対応します。 |
| プレビュー | 入力中の内容を、PC・スマホの幅で確認できます。構造化データ（JSON-LD）も確認できます。 |
| 表示するランキングの選択 | 投稿・固定ページ・カスタム投稿の編集画面で、表示するランキングと表示位置（本文の上・本文の下・手動・表示しない）を選べます。 |
| ショートコード | 手動のときは `[kashiwazaki_seo_recommended_ranking]` を本文の好きな位置に書きます。`[kashiwazaki_seo_recommended_ranking id="123"]` で、別のランキングも表示できます。 |
| リンク | ボタンの文言、新しいタブで開く、外部リンクの rel（nofollow・sponsored）を設定できます。 |
| デザイン | 5 つの型から選べます：スタンダード（カード）・王冠（1位〜3位に王冠と金・銀・銅の帯）・シンプル（大きな数字と罫線）・比較表（1 行に 1 つ）・上位3つを大きく。設定画面で既定の型を選び、ランキングごとに変えることもできます（設定画面の見本で、その場で見比べられます）。置いた場所の幅に合わせてスマホでも見やすい形に変わり、暗い色のテーマでも読めます。1位〜3位の金・銀・銅、メインの色の変更、ボタンの文字の色の自動調整（白か黒）。 |
| 構造化データ | ItemList・ListItem・Recommendation を基本に、おすすめする対象の種類（指定しない・商品・サービス）と、Guide でのまとめを選べます。同じページに同じランキングを2回表示しても、出力は1回です。 |

## インストール

1. このリポジトリのフォルダ `wp-plugin-kashiwazaki-seo-recommended-ranking` を `wp-content/plugins/` に配置します。
2. 管理画面の「プラグイン」から「Kashiwazaki SEO Recommended Ranking」を有効化します。
3. 左メニューに「Kashiwazaki SEO Recommended Ranking」が 1 項目追加されます。開いた画面の上のタブ（ランキング一覧・設定）で行き来でき、見出しの横の「ランキングを新規作成」から新しいランキングを作れます（「設定」のタブは管理者にだけ表示されます）。

## 使い方

1. 「Kashiwazaki SEO Recommended Ranking」の「ランキングを新規作成」で、管理用の名前・見出し・概要説明・各順位を入力して公開します（公開には3位までの名称が必要です）。
2. 投稿・固定ページ・カスタム投稿の編集画面の「Kashiwazaki SEO Recommended Ranking」のボックスで、表示するランキングと表示位置を選びます。
3. 「手動」を選んだときは、本文の好きな位置に `[kashiwazaki_seo_recommended_ranking]` を書きます。

## 表示のルール

- 公開中のランキングだけを表示します（下書き・非公開・パスワード保護・ゴミ箱のランキングは表示しません）。
- 本文の上・下への自動の表示は、その投稿の個別ページの本文だけに入ります（一覧ページ・抜粋・フィード・ウィジェットには入りません）。
- 複数ページに分けた投稿では、「本文の上」は最初のページ、「本文の下」は最後のページにだけ表示します。
- 同じランキングのショートコードが本文にあるときは、ショートコードの位置にだけ表示します。
- パスワード保護された投稿では、パスワードを入力するまで表示しません。

## 構造化データ

```text
ItemList（ランキング全体：name・description・numberOfItems・itemListOrder）
 └ ListItem（position）
    └ Recommendation（name・reviewBody・author）
       └ itemReviewed（Thing / Product / Service：name・url）
Guide（まとめの形で「Guide」を選んだとき：mainEntity → ItemList、hasPart → 各 Recommendation）
```

ItemList・Recommendation は、Google のリッチリザルトの対象ではありません。「商品（Product）」を選んでも、価格や評価を出力しないため商品のリッチリザルトの対象にはなりません。

## 動作環境

- WordPress 6.0 以上
- PHP 7.4 以上

## ライセンス

GPL v2 or later
