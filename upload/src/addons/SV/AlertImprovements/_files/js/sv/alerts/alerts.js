/*
 * This file is part of a XenForo add-on.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

var SV = window.SV || {};
SV.AlertImprovements = SV.AlertImprovements || {};

(function($, window, document, _undefined) {
    "use strict";

    SV.AlertImprovements.AlertToggler = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertTogglerClick',
        eventType: 'click',

        options: {
            inListSelector: '.contentRow-figure--selector',
            successMessageFlashTimeOut: 3000
        },

        processing: null,

        init: function()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click: function(e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            let self = this,
                $target = this.$target,
                $alert = $target.closest('.js-alert'),
                inList = $alert.find(this.options.inListSelector).length > 0;

            XF.ajax('POST', $target.attr('href'), {
                inlist: inList ? 1 : 0
            }, $.proxy(this, 'handleMarkReadAjax')).always(function ()
            {
                self.processing = false;
            });
        },

        /**
         * @param {Object} data
         */
        handleMarkReadAjax: function(data)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            let $target = this.$target,
                $alert = $target.closest('.js-alert'),
                wasUnread = $alert.hasClass('is-unread');

            $alert.removeClass('is-read');
            if (wasUnread)
            {
                $alert.addClass('is-recently-read').removeClass('is-unread');
            }
            else
            {
                $alert.removeClass('is-recently-read').addClass('is-unread');
            }

            if (data.html)
            {
                var id = $alert.data('alert_id');
                if (id) {
                    var $replacementHtml = data.html ? $(data.html) : $('<div/>');
                    var $replacementAlert = $replacementHtml.find("[data-alert-id='" + id + "']");
                    if ($replacementAlert.length) {
                        if (wasNotInList) {
                            $replacementAlert.find(this.options.inListSelector).remove()
                        }
                        XF.setupHtmlInsert($replacementAlert.children(), function ($html, data, onComplete) {
                            $alert.empty();
                            $alert.append($html);
                        });
                    }
                }
            }
        }
    });

    SV.AlertImprovements.AlertsList = XF.Element.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertListMarkReadClick',
        eventType: 'click',

        options: {
            successMessageFlashTimeOut: 3000,
            inListSelector: '.contentRow-figure--selector',
            alertItemSelector: '< .block | .js-alert'
        },

        processing: null,

        init: function()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click: function(e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            let self = this,
                $target = this.$target,
                alertIdLookup = {},
                alertIds = [],
                $alerts = XF.findRelativeIf(this.options.alertItemSelector, this.$target);

            $alerts.each(function(){
                var $alert = $(this),
                    alertId = $alert.data('alert_id');
                if (alertId && !(alertId in alertIdLookup)) {
                    alertIdLookup[alertId] = 1
                    alertIds.push(alertId);
                }
            });

            XF.ajax('POST', $target.attr('href'), {
                alert_ids: alertIds
            }, $.proxy(this, 'handleMarkAllReadAjax')).always(function ()
            {
                self.processing = false;
            });
        },

        /**
         * @param {Object} data
         */
        handleMarkAllReadAjax: function(data)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            var $replacementHtml = data.html ? $(data.html) : $('<div/>');

            XF.findRelativeIf(this.options.alertItemSelector, this.$target).each(function () {
                let $alert = $(this),
                    wasUnread = $alert.hasClass('is-unread'),
                    wasNotInList = !!$alert.find(this.options.inListSelector).length;

                if (wasUnread)
                {
                    $alert.removeClass('is-unread').addClass('is-recently-read');
                }
                else
                {
                    $alert.removeClass('is-read').removeClass('is-recently-read').addClass('is-unread');
                }

                var id = $alert.data('alert_id');
                if (id) {
                    var $replacementAlert = $replacementHtml.find("[data-alert-id='" + id + "']");
                    if ($replacementAlert.length) {
                        if (wasNotInList) {
                            $replacementAlert.find(this.options.inListSelector).remove()
                        }
                        XF.setupHtmlInsert($replacementAlert.children(), function ($html, data, onComplete) {
                            $alert.empty();
                            $alert.append($html);
                        });
                    }
                }
            });
        }
    });

    //XF.Click.register('sv-mark-alerts-read', 'SV.AlertImprovements.AlertListMarkRead');
    XF.Click.register('mark-alert-unread', 'SV.AlertImprovements.AlertToggler');
} (jQuery, window, document));
