/**
 * Folix ChargeTemplate - Swatch Renderer Mixin
 *
 * Thin orchestrator: wires charge-template + dropdown + HTML builder modules.
 *
 * PC desktop: first attribute → overflow pills layout
 *   [Tag] [Pill1..5] [More ▼]
 *   - Tag group at far left (in flex flow, auto-clipped on overflow)
 *   - More button with searchable dropdown for overflow options
 * Mobile/tablet: unchanged scroll container
 */
define([
    'jquery',
    'underscore',
    'jquery-ui-modules/widget',
    'Folix_ChargeTemplate/js/charge-template-manager',
    'Folix_ChargeTemplate/js/swatch-option-html-builder'
], function ($, _, widget, chargeTemplate, htmlBuilder) {
    'use strict';

    var PILLS_MAX_VISIBLE = 5;
    var DESKTOP_MQ = '(min-width: 769px)';

    return function (targetWidget) {
        var chargeTemplateMixin = {
            options: {
                childProductTemplates: {},
                selectorProduct: '.product-form-container'
            },

            // ============================================================
            //  1. Init — count attrs, wire events, load template
            // ============================================================
            _init: function () {
                this._totalAttributes = Object.keys(this.options.jsonConfig.attributes || {}).length;
                this._renderIndex = 0;

                this._super();

                // Load charge template data from global config
                if (window.folixChargeTemplateConfig && typeof window.folixChargeTemplateConfig === 'object') {
                    this.options.childProductTemplates = $.extend(
                        {},
                        this.options.childProductTemplates || {},
                        window.folixChargeTemplateConfig
                    );
                }

                this._bindChangeEvent();
                this._bindPillsOverflowEvents();
                chargeTemplate.loadForCurrentSelection(this);
            },

            // ============================================================
            //  2. Event binding — swatch change → charge template
            // ============================================================
            _bindChangeEvent: function () {
                var self = this;
                this.element.on('change', '.' + this.options.classes.attributeInput, function () {
                    setTimeout(function () {
                        chargeTemplate.loadForCurrentSelection(self);
                    }, 100);
                });
            },

            // ============================================================
            //  2b. Overflow dropdown & tag group event binding
            // ============================================================
            _bindPillsOverflowEvents: function () {
                var self = this;

                // Toggle "More" dropdown
                this.element.on('click.pdpPillsOverflow', '.pdp-swatch-more__trigger', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var $outer = $(this).closest('.pdp-pills-outer');
                    var $panel = $outer.find('.pdp-swatch-more__panel');
                    if ($panel.is(':visible')) {
                        $panel.slideUp(200);
                        $outer.removeClass('pdp-pills-outer--open');
                    } else {
                        self.element.find('.pdp-swatch-more__panel').slideUp(200);
                        self.element.find('.pdp-pills-outer--open').removeClass('pdp-pills-outer--open');
                        $panel.slideDown(200);
                        $outer.addClass('pdp-pills-outer--open');
                        $panel.find('.pdp-swatch-more__search-input').focus();
                    }
                });

                // Search filter in overflow dropdown
                this.element.on('input.pdpPillsOverflow keyup.pdpPillsOverflow', '.pdp-swatch-more__search-input', function () {
                    var val = $.trim($(this).val()).toLowerCase();
                    var $list = $(this).closest('.pdp-pills-outer').find('.pdp-swatch-more__list');
                    $list.find('.pdp-swatch-pill').each(function () {
                        var text = ($(this).attr('data-option-label') || '').toLowerCase();
                        $(this)[val === '' || text.indexOf(val) !== -1 ? 'show' : 'hide']();
                    });
                });

                // Overflow pill click → close dropdown
                this.element.on('click.pdpPillsOverflow', '.pdp-swatch-more__list .pdp-swatch-pill', function () {
                    var $outer = $(this).closest('.pdp-pills-outer');
                    setTimeout(function () {
                        $outer.find('.pdp-swatch-more__panel').slideUp(200);
                        $outer.removeClass('pdp-pills-outer--open');
                    }, 100);
                });

                // Click outside → close all "More" dropdowns
                $(document).on('click.pdpPillsOverflowClose', function (e) {
                    if (!$(e.target).closest('.pdp-swatch-more-wrapper,.pdp-swatch-more__panel').length) {
                        self.element.find('.pdp-swatch-more__panel').slideUp(200);
                        self.element.find('.pdp-pills-outer--open').removeClass('pdp-pills-outer--open');
                    }
                });

                // Tag × → deselect corresponding overflow pill (restore default state)
                this.element.on('click.pdpPillsOverflow', '.pdp-tag-group__close', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var $tag = $(this).closest('.pdp-tag-group');
                    var optionId = $tag.data('option-id');
                    if (optionId) {
                        var $pill = self.element.find('.pdp-swatch-more__list .pdp-swatch-pill[data-option-id="' + optionId + '"]');
                        if ($pill.length) {
                            $pill.trigger('click');
                        }
                    }
                });

                // Tag label click → also deselect
                this.element.on('click.pdpPillsOverflow', '.pdp-tag-group__label', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).siblings('.pdp-tag-group__close').trigger('click');
                });
            },

            // ============================================================
            //  3. Swatch rendering — delegates to htmlBuilder
            // ============================================================
            _RenderSwatchOptions: function (config, controlId) {
                var self = this,
                    optionConfig = this.options.jsonSwatchConfig[config.id],
                    optionClass = this.options.classes.optionClass,
                    sizeConfig = this.options.jsonSwatchImageSizeConfig,
                    moreLimit = parseInt(this.options.numberToShow, 10),
                    moreClass = this.options.classes.moreButton,
                    moreText = this.options.moreButtonText,
                    countAttributes = 0,
                    html = '';

                if (!this.options.jsonSwatchConfig.hasOwnProperty(config.id)) {
                    return '';
                }

                var isFirstAttribute = (self._renderIndex === 0) && (self._totalAttributes > 1);
                self._renderIndex++;

                // ================================================
                // PC Desktop: first attribute → overflow pills + tag + more dropdown
                // ================================================
                if (isFirstAttribute && window.matchMedia(DESKTOP_MQ).matches) {
                    var visiblePills = '';

                    $.each(config.options, function (index) {
                        if (!optionConfig.hasOwnProperty(this.id)) { return; }

                        var attr = htmlBuilder.buildOptionAttr(this, optionConfig, sizeConfig, controlId, index),
                            pillHtml = htmlBuilder.buildPill(optionClass, attr, this.label);

                        visiblePills += pillHtml;
                        countAttributes++;
                    });

                    html = '<div class="pdp-pills-outer">' +
                        '<div class="pdp-pills-row">' +
                            '<div class="pdp-tag-group" style="display:none">' +
                                '<span class="pdp-tag-group__label"></span>' +
                                '<span class="pdp-tag-group__close">&times;</span>' +
                            '</div>' +
                            '<div class="pdp-pills-row__visible">' + visiblePills + '</div>' +
                        '</div>' +
                        '</div>';

                    return html;
                }

                // ================================================
                // Mobile/tablet & non-first attributes: original logic
                // ================================================
                $.each(config.options, function (index) {
                    if (!optionConfig.hasOwnProperty(this.id)) { return; }

                    if (moreLimit === countAttributes++) {
                        html += '<a href="#" class="' + moreClass + '"><span>' + moreText + '</span></a>';
                    }

                    var attr = htmlBuilder.buildOptionAttr(this, optionConfig, sizeConfig, controlId, index),
                        type = parseInt(optionConfig[this.id].type, 10);

                    if (type === 0) {
                        if (isFirstAttribute) {
                            html += htmlBuilder.buildPill(optionClass, attr, this.label);
                        } else {
                            html += htmlBuilder.buildProductCard(this, optionClass, attr, self.options.jsonConfig);
                        }
                    } else if (type === 1) {
                        var value = optionConfig[this.id].value || '';
                        html += '<div class="' + optionClass + ' color" ' + attr +
                            ' style="background: ' + value +
                            ' no-repeat center; background-size: initial;"></div>';
                    } else if (type === 2) {
                        var imgValue = optionConfig[this.id].value || '',
                            sw = _.has(sizeConfig, 'swatchImage') ? sizeConfig.swatchImage.width : 30,
                            sh = _.has(sizeConfig, 'swatchImage') ? sizeConfig.swatchImage.height : 20;
                        html += '<div class="' + optionClass + ' image" ' + attr +
                            ' style="background: url(' + imgValue +
                            ') no-repeat center; background-size: initial;width:' +
                            sw + 'px; height:' + sh + 'px"></div>';
                    } else if (type === 3) {
                        html += '<div class="' + optionClass + '" ' + attr + '></div>';
                    } else {
                        html += '<div class="' + optionClass + '" ' + attr + '>' +
                            htmlBuilder.escapeHtml(this.label) + '</div>';
                    }
                });

                if (isFirstAttribute) {
                    html = '<div class="pdp-swatch-scroll-wrap">' +
                        '<div class="pdp-swatch-scroll-list">' + html + '</div>' +
                        '</div>';
                }

                return html;
            },

            // ============================================================
            //  4. Click — sync tag group after selection
            // ============================================================
            _OnClick: function ($this, $widget) {
                this._super($this, $widget);
                $widget._updateSkuDisplay();
                setTimeout(function () {
                    $widget._syncTagGroup();
                }, 50);
            },

            _updateSkuDisplay: function () {
                var simpleProductId = chargeTemplate.getSimpleProductId(this),
                    $skuValue = $('#product_addtocart_form .product.attribute.sku .value');

                if (!$skuValue.length) { return; }
                if (simpleProductId && this.options.jsonConfig.sku && this.options.jsonConfig.sku[simpleProductId]) {
                    $skuValue.text(this.options.jsonConfig.sku[simpleProductId]);
                }
            },

            /**
             * Sync tag group: show when selected option is in overflow dropdown.
             */
            _syncTagGroup: function () {
                var $outer = this.element.find('.pdp-pills-outer');
                if (!$outer.length) { return; }

                var $tag = $outer.find('.pdp-tag-group');
                var $selected = $outer.find('.pdp-swatch-more__list .pdp-swatch-pill.selected');

                if ($selected.length) {
                    var label = $selected.attr('data-option-label') || '';
                    $tag.find('.pdp-tag-group__label').text(label);
                    $tag.data('option-id', $selected.data('option-id'));
                    $tag.css('display', 'inline-flex');
                } else {
                    $tag.hide();
                    $tag.data('option-id', '');
                }
            },

            // ============================================================
            //  5. Rebuild — reset render index
            // ============================================================
            _Rebuild: function () {
                this._renderIndex = 0;
                this._super();
            }
        };

        $.widget('mage.swatchRenderer', targetWidget, chargeTemplateMixin);
        return $.mage.swatchRenderer;
    };
});
