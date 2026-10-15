/**
 * Kashiwazaki SEO Recommended Ranking：投稿・固定ページ・カスタム投稿の編集画面のボックス
 * 選んだランキングの編集リンク・公開していないときの注意・手動のときのショートコードの案内
 */
(function () {
    'use strict';

    function init(box) {
        var select = box.querySelector('#kashiwazaki-seo-recommended-ranking-ranking');
        var edit = box.querySelector('.kashiwazaki-seo-recommended-ranking-edit-link');
        var warning = box.querySelector('.kashiwazaki-seo-recommended-ranking-post-warning');
        var help = box.querySelector('.kashiwazaki-seo-recommended-ranking-manual-help');
        var radios = box.querySelectorAll('input[name="kashiwazaki_seo_recommended_ranking_post[position]"]');
        if (!select) {
            return;
        }

        function update() {
            var opt = select.options[select.selectedIndex];
            var chosen = opt && opt.value !== '0';
            var href = chosen ? (opt.getAttribute('data-edit') || '') : '';
            if (edit) {
                edit.hidden = href === '';
                edit.setAttribute('href', href || '#');
            }
            if (warning) {
                warning.hidden = !(chosen && opt.getAttribute('data-hidden') === '1');
            }
            var position = '';
            Array.prototype.forEach.call(radios, function (r) {
                if (r.checked) {
                    position = r.value;
                }
                r.disabled = !chosen;
            });
            if (help) {
                help.hidden = !(chosen && position === 'manual');
            }
        }

        select.addEventListener('change', update);
        Array.prototype.forEach.call(radios, function (r) {
            r.addEventListener('change', update);
        });
        update();
    }

    function boot() {
        var box = document.querySelector('.kashiwazaki-seo-recommended-ranking-post-box');
        if (box) {
            init(box);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
