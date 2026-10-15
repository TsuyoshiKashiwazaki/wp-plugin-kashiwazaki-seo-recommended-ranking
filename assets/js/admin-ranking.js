/**
 * Kashiwazaki SEO Recommended Ranking：ランキングの編集画面
 * 順位の追加・削除・並べ替え、入力の確認、プレビュー、ショートコードのコピー
 */
(function ($) {
    'use strict';

    var cfg = window.kashiwazakiSeoRecommendedRanking || {};
    var i18n = cfg.i18n || {};
    var MIN = parseInt(cfg.minItems, 10) || 3;
    var MAX = parseInt(cfg.maxItems, 10) || 10;

    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(str || '').replace(/%(\d+\$)?d/g, function (m, pos) {
            if (pos) {
                return String(args[parseInt(pos, 10) - 1]);
            }
            return String(args[i++]);
        });
    }

    $(function () {
        var $items = $('.kashiwazaki-seo-recommended-ranking-items');
        if (!$items.length) {
            return;
        }
        var $form = $('#post');
        var $template = $('#kashiwazaki-seo-recommended-ranking-item-template');
        var $add = $('.kashiwazaki-seo-recommended-ranking-add');
        var $count = $('.kashiwazaki-seo-recommended-ranking-count');
        var $validation = $('.kashiwazaki-seo-recommended-ranking-validation');

        function rows() {
            return $items.children('.kashiwazaki-seo-recommended-ranking-item');
        }

        // 番号（name・id・for・順位の表示）を、上から振り直す
        function reindex() {
            rows().each(function (i) {
                var $row = $(this);
                $row.attr('data-index', i);
                $row.find('.kashiwazaki-seo-recommended-ranking-item__rank').text(fmt(i18n.rank || '%d位', i + 1));
                $row.find('[data-field]').each(function () {
                    var field = $(this).attr('data-field');
                    var id = 'kashiwazaki-seo-recommended-ranking-item-' + i + '-' + field;
                    var oldId = $(this).attr('id');
                    $(this).attr('name', 'kashiwazaki_seo_recommended_ranking[items][' + i + '][' + field + ']');
                    $row.find('label[for="' + oldId + '"]').attr('for', id);
                    $(this).attr('id', id);
                });
            });
            var n = rows().length;
            $add.prop('disabled', n >= MAX);
            rows().find('.kashiwazaki-seo-recommended-ranking-item__remove').prop('disabled', n <= MIN);
            rows().first().find('.kashiwazaki-seo-recommended-ranking-item__up').prop('disabled', true);
            rows().not(':first').find('.kashiwazaki-seo-recommended-ranking-item__up').prop('disabled', false);
            rows().last().find('.kashiwazaki-seo-recommended-ranking-item__down').prop('disabled', true);
            rows().not(':last').find('.kashiwazaki-seo-recommended-ranking-item__down').prop('disabled', false);
            $count.text(fmt(i18n.count || '%1$d / %2$d', n, MAX));
        }

        function isAcceptableUrl(v) {
            v = $.trim(v);
            if (v === '') {
                return true;
            }
            if (/^\/(?!\/)/.test(v) || /^\/\//.test(v)) {
                return true;
            }
            if (/^https?:\/\/[^\s/?#]+/i.test(v)) {
                return true;
            }
            // 「example.com/…」のように方式を省いた形は、保存時に http:// を補う
            return /^[a-z0-9-]+(\.[a-z0-9-]+)+([/?#].*)?$/i.test(v) && v.indexOf(':') === -1;
        }

        function checkUrl($input) {
            var ok = isAcceptableUrl($input.val());
            var $err = $input.closest('p').find('.kashiwazaki-seo-recommended-ranking-item__url-error');
            if (ok) {
                $err.prop('hidden', true).text('');
                $input.removeAttr('aria-invalid');
            } else {
                $err.prop('hidden', false).text(i18n.badUrl || '');
                $input.attr('aria-invalid', 'true');
            }
            return ok;
        }

        // 公開できる状態か（名称のある順位が MIN 以上、名称の空いた入力済みの行が無い）
        function validate() {
            var named = 0;
            var emptyName = 0;
            rows().each(function () {
                var $r = $(this);
                var name = $.trim($r.find('[data-field="name"]').val() || '');
                var desc = $.trim($r.find('[data-field="description"]').val() || '');
                var url = $.trim($r.find('[data-field="url"]').val() || '');
                if (name !== '') {
                    named++;
                } else if (desc !== '' || url !== '') {
                    emptyName++;
                }
            });
            var msg = '';
            if (emptyName > 0) {
                msg = i18n.emptyName || '';
            } else if (named < MIN) {
                msg = fmt(i18n.needMin || '', MIN);
            }
            return { ok: msg === '', message: msg };
        }

        function showValidation(result) {
            if (result.ok) {
                $validation.prop('hidden', true).find('p').text('');
            } else {
                $validation.prop('hidden', false).find('p').text(result.message);
            }
        }

        function addRow() {
            if (rows().length >= MAX) {
                return;
            }
            var html = $template.html().replace(/__INDEX__/g, String(rows().length));
            var $row = $($.parseHTML($.trim(html)));
            $items.append($row);
            reindex();
            $row.find('[data-field="name"]').trigger('focus');
            schedulePreview();
        }

        $add.on('click', addRow);

        $items.on('click', '.kashiwazaki-seo-recommended-ranking-item__remove', function () {
            if (rows().length <= MIN) {
                window.alert(fmt(i18n.minReached || '', MIN));
                return;
            }
            var $row = $(this).closest('.kashiwazaki-seo-recommended-ranking-item');
            var $next = $row.next('.kashiwazaki-seo-recommended-ranking-item').length ? $row.next() : $row.prev();
            $row.remove();
            reindex();
            $next.find('[data-field="name"]').trigger('focus');
            showValidation(validate());
            schedulePreview();
        });

        $items.on('click', '.kashiwazaki-seo-recommended-ranking-item__up, .kashiwazaki-seo-recommended-ranking-item__down', function () {
            var $btn = $(this);
            var $row = $btn.closest('.kashiwazaki-seo-recommended-ranking-item');
            var up = $btn.hasClass('kashiwazaki-seo-recommended-ranking-item__up');
            if (up && $row.prev('.kashiwazaki-seo-recommended-ranking-item').length) {
                $row.insertBefore($row.prev());
            } else if (!up && $row.next('.kashiwazaki-seo-recommended-ranking-item').length) {
                $row.insertAfter($row.next());
            }
            reindex();
            // 押したボタンにフォーカスを戻す（端に着いて押せなくなったら、もう一方のボタンへ）
            var $same = $row.find(up ? '.kashiwazaki-seo-recommended-ranking-item__up' : '.kashiwazaki-seo-recommended-ranking-item__down');
            var $other = $row.find(up ? '.kashiwazaki-seo-recommended-ranking-item__down' : '.kashiwazaki-seo-recommended-ranking-item__up');
            ($same.prop('disabled') ? $other : $same).trigger('focus');
            schedulePreview();
        });

        if ($.fn.sortable) {
            $items.sortable({
                items: '> .kashiwazaki-seo-recommended-ranking-item',
                handle: '.kashiwazaki-seo-recommended-ranking-item__handle',
                axis: 'y',
                placeholder: 'kashiwazaki-seo-recommended-ranking-item-placeholder',
                forcePlaceholderSize: true,
                update: function () {
                    reindex();
                    schedulePreview();
                }
            });
        }

        $items.on('blur change', '.kashiwazaki-seo-recommended-ranking-item__url', function () {
            checkUrl($(this));
        });

        // ---- プレビュー ----
        var $canvas = $('.kashiwazaki-seo-recommended-ranking-preview__canvas');
        var $frame = $('.kashiwazaki-seo-recommended-ranking-preview__frame');
        var $status = $('.kashiwazaki-seo-recommended-ranking-preview__status');
        var $json = $('.kashiwazaki-seo-recommended-ranking-preview__json-body');
        var timer = null;
        var seq = 0;

        function collect() {
            var data = { action: 'kashiwazaki_seo_recommended_ranking_preview', nonce: cfg.nonce, post_id: $('#post_ID').val() || 0, title: $('#title').val() || '' };
            $form.find('[name^="kashiwazaki_seo_recommended_ranking["]').each(function () {
                var $el = $(this);
                if (($el.is(':checkbox') || $el.is(':radio')) && !$el.is(':checked')) {
                    return;
                }
                data[$el.attr('name')] = $el.val();
            });
            return data;
        }

        function preview() {
            var mine = ++seq;
            $status.text('…');
            $.post(cfg.ajaxUrl, collect()).done(function (res) {
                if (mine !== seq) {
                    return;
                }
                if (!res || !res.success) {
                    $status.text(i18n.previewError || '');
                    return;
                }
                $status.text('');
                if (res.data.html) {
                    // サーバーでエスケープ済みの HTML
                    $canvas.html(res.data.html);
                    // プレビューの中のリンクは開かない
                    $canvas.find('a').attr('tabindex', '-1').on('click', function (e) { e.preventDefault(); });
                } else {
                    $canvas.empty().append($('<p class="description"></p>').text(i18n.previewEmpty || ''));
                }
                $json.text(res.data.json || '');
            }).fail(function () {
                if (mine === seq) {
                    $status.text(i18n.previewError || '');
                }
            });
        }

        function schedulePreview() {
            window.clearTimeout(timer);
            timer = window.setTimeout(preview, 400);
        }

        $form.on('input change', '[name^="kashiwazaki_seo_recommended_ranking["], #title', function () {
            schedulePreview();
            if ($(this).closest('.kashiwazaki-seo-recommended-ranking-items').length && !$validation.prop('hidden')) {
                showValidation(validate());
            }
        });

        $('.kashiwazaki-seo-recommended-ranking-preview__width').on('click', function () {
            var w = $(this).attr('data-width');
            $frame.attr('data-width', w);
            $('.kashiwazaki-seo-recommended-ranking-preview__width').removeClass('is-active').attr('aria-pressed', 'false');
            $(this).addClass('is-active').attr('aria-pressed', 'true');
        });

        // ---- 公開・更新の前に確認（下書きの保存は止めない） ----
        // URL の形が違うものは保存されない（空になる）ことを、その場で知らせる
        $('#publish').on('click', function (e) {
            var result = validate();
            rows().find('.kashiwazaki-seo-recommended-ranking-item__url').each(function () {
                checkUrl($(this));
            });
            showValidation(result);
            if (!result.ok) {
                e.preventDefault();
                e.stopImmediatePropagation();
                var top = $items.offset() ? $items.offset().top - 80 : 0;
                $('html, body').animate({ scrollTop: top }, 200);
                $validation.attr('tabindex', '-1').trigger('focus');
            }
        });

        // ---- ショートコードのコピー ----
        $(document).on('click', '.kashiwazaki-seo-recommended-ranking-copy__button', function () {
            var $btn = $(this);
            var $input = $('#' + $btn.attr('data-target'));
            var text = $input.val();
            var done = function () {
                var old = $btn.data('label') || $btn.text();
                $btn.data('label', old).text(i18n.copied || 'OK');
                window.setTimeout(function () { $btn.text(old); }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () {
                    $input.trigger('focus').trigger('select');
                });
            } else {
                $input.trigger('focus').trigger('select');
                try {
                    if (document.execCommand('copy')) {
                        done();
                    }
                } catch (err) {
                    // 選択した状態のままにして、手でコピーしてもらう
                }
            }
        });

        reindex();
        preview();
    });
})(jQuery);
