/**
 * JavaScript para produtos variáveis - DW Parcelas e Pix Customizadas
 * 
 * Este arquivo gerencia a atualização dinâmica dos preços PIX quando o cliente
 * seleciona diferentes variações de um produto variável no WooCommerce.
 * 
 * @package DW_Parcelas_Pix_WooCommerce
 * @since 0.2.0
 */

(function($) {
    'use strict';

    /**
     * Classe VariablePixPrice
     * 
     * Gerencia a exibição dinâmica de preços PIX para produtos variáveis.
     * Atualiza automaticamente o preço PIX quando uma variação é selecionada.
     */
    var VariablePixPrice = {
        
        /**
         * Configurações da classe
         * 
         * @property {Object} pixPrices - Mapa de preços PIX por ID de variação
         * @property {Object} regularPrices - Mapa de preços regulares por ID de variação
         * @property {jQuery} container - Container DOM para o preço PIX
         * @property {number} updateInterval - Intervalo em ms para atualização periódica
         */
        config: {
            pixPrices: {},
            regularPrices: {},
            container: null,
            updateInterval: 1000
        },

        /**
         * Inicializa o sistema de preços PIX variáveis
         * 
         * @param {Object} pixPrices - Mapa de preços PIX indexado por ID de variação
         * @param {Object} regularPrices - Mapa de preços regulares indexado por ID de variação
         */
        init: function(pixPrices, regularPrices) {
            this.config.pixPrices = pixPrices || {};
            this.config.regularPrices = regularPrices || {};
            
            this.setupContainer();
            this.bindEvents();
            this.startPeriodicUpdate();
            
            // Atualiza inicialmente
            setTimeout(this.updatePixPrice.bind(this), 500);
        },

        /**
         * Configura o container DOM para exibir o preço PIX
         * 
         * Cria o container se não existir e armazena referência.
         */
        setupContainer: function() {
            var $container = $('.dw-pix-variation-price');
            
            if ($container.length === 0) {
                // Nunca reutiliza .dw-pix-price-info para evitar sobrescrever
                // o bloco principal e gerar renderização duplicada.
                var $target = $('.woocommerce-variation-price');
                if ($target.length === 0) {
                    $target = $('.single_variation_wrap');
                }
                if ($target.length === 0) {
                    $target = $('.variations_form');
                }
                if ($target.length === 0) {
                    $target = $('.price').last();
                }

                $target.after('<div class="dw-pix-variation-price" style="display: none;"></div>');
                $container = $('.dw-pix-variation-price').first();
            }
            
            this.config.container = $container;
        },

        /**
         * Vincula eventos do WooCommerce e do tema
         * 
         * Monitora mudanças nas variações para atualizar o preço PIX.
         */
        bindEvents: function() {
            var self = this;
            
            // Eventos do WooCommerce
            $('form.variations_form').on('woocommerce_variation_has_changed', function() {
                setTimeout(self.updatePixPrice.bind(self), 100);
            });
            
            $('form.variations_form').on('woocommerce_variation_price_updated', function() {
                setTimeout(self.updatePixPrice.bind(self), 100);
            });
            
            $('form.variations_form').on('found_variation', function(event, variation) {
                setTimeout(self.updatePixPrice.bind(self), 100);
            });
            
            // Eventos genéricos para diferentes temas
            $('form.variations_form').on('change', 'select, input, .variation-select', function() {
                setTimeout(self.updatePixPrice.bind(self), 200);
            });
            
            // Eventos específicos para alguns temas
            $(document).on('change', '.variation-select, .variation-selector', function() {
                setTimeout(self.updatePixPrice.bind(self), 200);
            });
        },

        /**
         * Inicia atualização periódica do preço PIX
         * 
         * Verifica periodicamente se há variação selecionada e atualiza.
         */
        startPeriodicUpdate: function() {
            var self = this;
            
            setInterval(function() {
                if ($('input[name="variation_id"]').val() || $('.variation-select').val()) {
                    self.updatePixPrice();
                }
            }, this.config.updateInterval);
        },

        /**
         * Atualiza o preço PIX baseado na variação selecionada
         * 
         * Calcula o desconto e exibe ou oculta o preço PIX conforme necessário.
         */
        updatePixPrice: function() {
            var selectedVariation = this.getSelectedVariation();
            
            if (!selectedVariation || !this.config.pixPrices[selectedVariation]) {
                this.hidePixPrice();
                return;
            }
            
            var pixPrice = this.config.pixPrices[selectedVariation];
            var regularPrice = this.getRegularPrice(selectedVariation);
            
            if (regularPrice > 0 && pixPrice < regularPrice) {
                this.showPixPrice(pixPrice, regularPrice);
            } else {
                this.hidePixPrice();
            }
        },

        /**
         * Obtém o ID da variação atualmente selecionada
         * 
         * @return {string|null} ID da variação ou null se nenhuma selecionada
         */
        getSelectedVariation: function() {
            var variationId = $('input[name="variation_id"]').val();
            
            if (!variationId) {
                // Tenta outros seletores
                variationId = $('.variation-select').val();
            }
            
            if (!variationId) {
                // Tenta pegar do atributo data
                variationId = $('.variation-selector:checked').attr('data-variation-id');
            }
            
            return variationId;
        },

        /**
         * Obtém o preço regular da variação
         * 
         * @param {string} variationId - ID da variação
         * @return {number} Preço regular da variação
         */
        getRegularPrice: function(variationId) {
            var regularPrice = this.config.regularPrices[variationId] || 0;
            
            if (regularPrice <= 0) {
                // Tenta pegar do elemento da página
                var $priceElement = $('.woocommerce-Price-amount').first();
                if ($priceElement.length) {
                    var priceText = $priceElement.text();
                    regularPrice = parseFloat(priceText.replace(/[^\d,]/g, '').replace(',', '.'));
                }
            }
            
            return regularPrice;
        },

        /**
         * Exibe o preço PIX com desconto calculado
         * 
         * @param {number} pixPrice - Preço com desconto PIX
         * @param {number} regularPrice - Preço regular do produto
         */
        showPixPrice: function(pixPrice, regularPrice) {
            var discountAmount = regularPrice - pixPrice;
            var discountPercent = Math.round((discountAmount / regularPrice) * 100);
            
            // Obtém configurações de design
            var settings = this.getDesignSettings();
            
            // Obtém HTML do ícone
            var iconHtml = this.getIconHtml(settings);
            
            var pixHtml = '<div class="dw-pix-price-info" style="' + this.generateContainerStyles(settings) + '">';
            pixHtml += '<p class="dw-pix-price-text" style="' + this.generateTextStyles(settings) + '">';
            pixHtml += '<span class="pix-icon">' + iconHtml + '</span> ' + settings.custom_text + ' ';
            pixHtml += '<span class="dw-pix-price-amount" style="' + this.generatePriceStyles(settings) + '">R$ ' + pixPrice.toFixed(2).replace('.', ',') + '</span>';
            pixHtml += '<span class="dw-pix-discount-percent" style="' + this.generateDiscountStyles(settings) + '">(' + discountPercent + '% ' + settings.discount_text + ')</span>';
            pixHtml += '</p>';
            pixHtml += '</div>';
            
            this.config.container.html(pixHtml);
            this.config.container.show();
        },

        /**
         * Obtém as configurações de design do elemento data attribute
         * 
         * @return {Object} Configurações de design ou valores padrão
         */
        getDesignSettings: function() {
            // Tenta obter do elemento data ou usa padrões
            var $settingsElement = $('[data-dw-pix-settings]');
            if ($settingsElement.length) {
                try {
                    return JSON.parse($settingsElement.attr('data-dw-pix-settings'));
                } catch (e) {
                    console.log('Erro ao parsear configurações PIX:', e);
                }
            }
            
            // Configurações padrão
            var defaultIconUrl = '';
            var $settingsElement = $('[data-dw-pix-settings]');
            if ($settingsElement.length) {
                try {
                    var parsedSettings = JSON.parse($settingsElement.attr('data-dw-pix-settings'));
                    defaultIconUrl = parsedSettings.pix_icon_custom || '';
                } catch (e) {
                    console.log('Erro ao parsear configurações PIX:', e);
                }
            }
            
            return {
                background_color: '#e8f5e9',
                border_color: '#4caf50',
                text_color: '#2e7d32',
                price_color: '#1b5e20',
                discount_text_color: '#666',
                pix_icon_custom: defaultIconUrl,
                custom_text: 'Pagando com PIX:',
                border_style: 'solid',
                font_size: '16',
                discount_text: 'de desconto'
            };
        },

        /**
         * Obtém o HTML do ícone PIX
         * 
         * @param {Object} settings - Configurações contendo URL do ícone
         * @return {string} HTML do ícone ou string vazia
         */
        getIconHtml: function(settings) {
            // Sempre usa ícone de imagem (personalizado ou padrão)
            var iconUrl = settings.pix_icon_custom || '';
            if (iconUrl) {
                return '<img src="' + iconUrl + '" alt="PIX" style="width: 20px; height: 20px; vertical-align: middle; display: inline-block;" />';
            }
            
            return '';
        },

        /**
         * Gera estilos CSS para o container
         * 
         * @param {Object} settings - Configurações de design
         * @return {string} String CSS inline
         */
        generateContainerStyles: function(settings) {
            return 'background-color: ' + settings.background_color + '; ' +
                   'border-left: 4px ' + settings.border_style + ' ' + settings.border_color + ';';
        },

        /**
         * Gera estilos CSS para o texto
         * 
         * @param {Object} settings - Configurações de design
         * @return {string} String CSS inline
         */
        generateTextStyles: function(settings) {
            return 'color: ' + settings.text_color + '; ' +
                   'font-size: ' + settings.font_size + 'px;';
        },

        /**
         * Gera estilos CSS para o preço
         * 
         * @param {Object} settings - Configurações de design
         * @return {string} String CSS inline
         */
        generatePriceStyles: function(settings) {
            var fontSize = settings.font_size || '16';
            return 'color: ' + settings.price_color + '; font-size: ' + fontSize + 'px;';
        },

        /**
         * Gera estilos CSS para o texto de desconto
         * 
         * @param {Object} settings - Configurações de design
         * @return {string} String CSS inline
         */
        generateDiscountStyles: function(settings) {
            var discountColor = settings.discount_text_color || '#666';
            return 'color: ' + discountColor + ';';
        },

        /**
         * Oculta o preço PIX do container
         */
        hidePixPrice: function() {
            this.config.container.hide();
        }
    };

    // Expõe a classe globalmente para acesso externo
    window.DWVariablePixPrice = VariablePixPrice;

})(jQuery);
