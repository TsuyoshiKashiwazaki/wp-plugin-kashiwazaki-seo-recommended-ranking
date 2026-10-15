/**
 * Kashiwazaki SEO Recommended Ranking：管理画面の共通の動き
 * ショートコードの欄（読み取り専用）を選んだら、全体を選択してコピーしやすくする
 */
(function () {
    'use strict';

    function selectAll(e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('kashiwazaki-seo-recommended-ranking-select-on-focus') && typeof el.select === 'function') {
            el.select();
        }
    }

    document.addEventListener('focusin', selectAll);
    document.addEventListener('click', selectAll);
})();
