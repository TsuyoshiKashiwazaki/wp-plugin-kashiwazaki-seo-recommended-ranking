/**
 * Kashiwazaki SEO Recommended Ranking：設定画面
 * - メインの色のカラーピッカー
 * - デザインの型・メインの色・1位〜3位の色を変えたら、見本をその場で切り替える（保存はしない）
 * - 見本の PC・スマホの幅の切り替え
 */
(function ($) {
    'use strict';

    $(function () {
        var $sample = $('.kashiwazaki-seo-recommended-ranking-design-sample');
        var $box = $sample.find('.kashiwazaki-seo-recommended-ranking').first();
        var prefix = 'kashiwazaki-seo-recommended-ranking--design-';

        // 見本のリンクは押しても移動しない（Tab でも止まらない）
        $sample.find('.kashiwazaki-seo-recommended-ranking a').attr('tabindex', '-1').on('click', function (e) {
            e.preventDefault();
        });

        // 色の明るさから、上に載せる文字の色（白か黒）を決める（公開ページの Renderer::contrast_text と同じ計算）
        function contrastText(hex) {
            var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex || '');
            if (!m) {
                return '#ffffff';
            }
            var h = m[1].length === 3 ? m[1].replace(/(.)/g, '$1$1') : m[1];
            var c = [0, 2, 4].map(function (i) {
                var v = parseInt(h.substr(i, 2), 16) / 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            });
            var l = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
            return (1.05 / (l + 0.05)) >= ((l + 0.05) / 0.05) ? '#ffffff' : '#111111';
        }

        function setAccent(hex) {
            if (!$box.length || !/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex || '')) {
                return;
            }
            $box[0].style.setProperty('--kashiwazaki-seo-recommended-ranking-accent', hex);
            $box[0].style.setProperty('--kashiwazaki-seo-recommended-ranking-accent-text', contrastText(hex));
        }

        $('.kashiwazaki-seo-recommended-ranking-color').wpColorPicker({
            change: function (event, ui) {
                setAccent(ui.color.toString());
            },
            clear: function () {
                setAccent($(this).closest('.wp-picker-container').find('.kashiwazaki-seo-recommended-ranking-color').data('default-color'));
            }
        });

        $('.kashiwazaki-seo-recommended-ranking-designs').on('change', 'input[type="radio"]', function () {
            if (!$box.length) {
                return;
            }
            var value = this.value;
            $.each(($box.attr('class') || '').split(/\s+/), function (i, cls) {
                if (cls.indexOf(prefix) === 0) {
                    $box.removeClass(cls);
                }
            });
            $box.addClass(prefix + value);
        });

        $('input[name$="[medal_colors]"]').on('change', function () {
            $box.toggleClass('kashiwazaki-seo-recommended-ranking--medal', this.checked);
        });

        $sample.on('click', '.kashiwazaki-seo-recommended-ranking-preview__width', function () {
            var $btn = $(this);
            $sample.find('.kashiwazaki-seo-recommended-ranking-preview__width').removeClass('is-active').attr('aria-pressed', 'false');
            $btn.addClass('is-active').attr('aria-pressed', 'true');
            $sample.find('.kashiwazaki-seo-recommended-ranking-preview__frame').attr('data-width', $btn.data('width'));
        });
    });
})(jQuery);
