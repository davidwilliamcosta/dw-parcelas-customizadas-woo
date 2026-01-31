<?php
/**
 * Classe para funcionalidades do frontend
 *
 * @package DW_Parcelas_Pix_WooCommerce
 */

// Evita acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe DW_Pix_Frontend
 */
class DW_Pix_Frontend {

    /**
     * Instância da classe core
     *
     * @var DW_Pix_Core
     */
    private $core;

    /**
     * Construtor
     */
    public function __construct() {
        $this->core = new DW_Pix_Core();
        $this->init_hooks();
    }

    /**
     * Inicializa os hooks
     */
    private function init_hooks() {
        // Obtém a prioridade do PIX baseada nas configurações
        $pix_priority = $this->get_pix_priority();
        
        // Exibe o preço PIX na página do produto (ÚNICO HOOK PRINCIPAL)
        add_action('woocommerce_single_product_summary', array($this, 'display_pix_price'), $pix_priority);
        
        // Hooks adicionais para garantir posicionamento antes do botão (compatibilidade com Elementor)
        // Usa a mesma prioridade calculada para manter ordem relativa às parcelas
        add_action('woocommerce_before_add_to_cart_form', array($this, 'display_pix_price'), $pix_priority);
        
        // Hook antes do botão especificamente (usa prioridade calculada)
        add_action('woocommerce_before_add_to_cart_button', array($this, 'display_pix_price'), $pix_priority);
        
        // Shortcode para posicionamento manual no Elementor ou outros page builders
        add_shortcode('dw_pix_price', array($this, 'pix_price_shortcode'));
        
        // Para produtos variáveis, adiciona JavaScript para atualizar preço PIX
        add_action('woocommerce_single_product_summary', array($this, 'add_variation_pix_script'), 25);
        
        // Hook adicional para produtos variáveis
        add_action('woocommerce_single_variation', array($this, 'display_variation_pix_price'), 20);
        
        // Exibe preço PIX na galeria de produtos (depois das parcelas)
        add_action('woocommerce_after_shop_loop_item_title', array($this, 'display_pix_price_in_gallery'), 20);
        
        // Hooks específicos do Woodmart
        add_action('woodmart_after_shop_loop_item_title', array($this, 'display_pix_price_in_gallery'), 20);
        add_action('woodmart_product_loop_after_price', array($this, 'display_pix_price_in_gallery'), 20);
        add_action('xts_after_shop_loop_item_title', array($this, 'display_pix_price_in_gallery'), 20);
        add_filter('woodmart_product_loop_after_price', array($this, 'display_pix_price_in_gallery'), 20);
        
        // Adiciona aviso no carrinho
        add_action('woocommerce_before_cart', array($this, 'show_pix_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'show_pix_notice'));
        
        // Exibe desconto no nome do produto no checkout
        add_filter('woocommerce_cart_item_name', array($this, 'add_discount_info_to_cart_item'), 10, 3);
        
        // Adiciona estilos CSS e scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Endpoint AJAX para obter preço PIX na grade do Elementor
        add_action('wp_ajax_dw_get_pix_price_for_grid', array($this, 'ajax_get_pix_price_for_grid'));
        add_action('wp_ajax_nopriv_dw_get_pix_price_for_grid', array($this, 'ajax_get_pix_price_for_grid'));
    }

    /**
     * Shortcode para exibir preço PIX manualmente
     * Uso: [dw_pix_price] ou [dw_pix_price product_id="123"]
     * Ideal para Elementor e outros page builders
     *
     * @return string
     */
    public function pix_price_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product_id' => get_the_ID()
        ), $atts);
        
        $product = wc_get_product($atts['product_id']);
        
        if (!$product) {
            return '';
        }
        
        ob_start();
        
        $current_price = floatval($product->get_price());
        
        if ($current_price <= 0) {
            return '';
        }
        
        $pix_price = $this->core->get_pix_price($product->get_id(), true, $current_price);
        
        if ($pix_price > 0 && $pix_price < $current_price) {
            $discount = $this->core->calculate_pix_discount($current_price, $pix_price);
            
            if ($discount['amount'] > 0 && $discount['percentage'] > 0) {
                $this->render_pix_price_display($pix_price, $discount);
            }
        }
        
        return ob_get_clean();
    }

    /**
     * Obtém a prioridade do PIX baseada nas configurações
     * Calcula dinamicamente baseado na posição das parcelas
     *
     * @return int
     */
    private function get_pix_priority() {
        $global_settings = $this->get_global_settings();
        $pix_position = isset($global_settings['pix_position']) ? $global_settings['pix_position'] : 'after_installments';
        
        // Obtém a posição das parcelas para calcular prioridade relativa
        $installments_settings = get_option('dw_pix_installments_settings', array());
        $parcelas_position = isset($installments_settings['product_position']) ? $installments_settings['product_position'] : 'before_add_to_cart';
        
        // Calcula prioridades das parcelas
        $parcelas_priorities = $this->get_parcelas_priorities($parcelas_position);
        $parcelas_summary_priority = $parcelas_priorities['summary'];
        $parcelas_table_priority = $parcelas_priorities['table'];
        
        // Nova lógica: PIX sempre entre resumo e tabela
        // Ordem correta: Resumo → PIX → Tabela
        // PIX fica entre o resumo (summary) e a tabela (table)
        return $parcelas_summary_priority + 1; // Entre resumo e tabela
    }

    /**
     * Obtém as prioridades das parcelas baseado na posição configurada
     * (Replica a lógica de class-dw-parcelas-frontend.php)
     *
     * @param string $position Posição das parcelas
     * @return array Array com 'summary' e 'table'
     */
    private function get_parcelas_priorities($position) {
        switch ($position) {
            case 'before_price':
                return array('summary' => 15, 'table' => 16);
            
            case 'after_price':
                return array('summary' => 25, 'table' => 26);
            
            case 'before_add_to_cart':
                return array('summary' => 35, 'table' => 36);
            
            case 'after_add_to_cart':
                // Ajustado para antes do botão
                return array('summary' => 35, 'table' => 36);
            
            case 'before_meta':
                // Ajustado para antes do botão
                return array('summary' => 35, 'table' => 36);
            
            case 'after_meta':
                // Ajustado para antes do botão
                return array('summary' => 35, 'table' => 36);
            
            default:
                // Padrão: antes do botão
                return array('summary' => 35, 'table' => 36);
        }
    }

    /**
     * Exibe o preço PIX na página do produto
     */
    public function display_pix_price() {
        global $product;
        
        // Evita duplicação - verifica se já foi exibido nesta requisição
        static $pix_displayed = false;
        if ($pix_displayed) {
            return;
        }
        
        if (!$product) {
            return;
        }
        
        $current_price = floatval($product->get_price());
        
        if ($current_price <= 0) {
            return;
        }
        
        $pix_price = $this->core->get_pix_price($product->get_id(), true, $current_price);
        
        if ($pix_price > 0 && $pix_price < $current_price) {
            $discount = $this->core->calculate_pix_discount($current_price, $pix_price);
            
            if ($discount['amount'] > 0 && $discount['percentage'] > 0) {
                $pix_displayed = true;
                $this->render_pix_price_display($pix_price, $discount);
            }
        }
    }

    /**
     * Exibe o preço PIX na galeria de produtos
     */
    public function display_pix_price_in_gallery() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        // Verifica se deve exibir na galeria
        $global_settings = $this->get_global_settings();
        if (empty($global_settings['show_in_gallery']) || $global_settings['show_in_gallery'] != '1') {
            return;
        }
        
        $current_price = floatval($product->get_price());
        
        if ($current_price <= 0) {
            return;
        }
        
        $pix_price = $this->core->get_pix_price($product->get_id(), true, $current_price);
        
        if ($pix_price > 0 && $pix_price < $current_price) {
            $discount = $this->core->calculate_pix_discount($current_price, $pix_price);
            
            if ($discount['amount'] > 0 && $discount['percentage'] > 0) {
                $settings = $this->get_design_settings();
                
                // Permite que o Elementor modifique as configurações
                $settings = apply_filters('dw_pix_gallery_settings', $settings, $product);
                
                // Verifica se o Elementor desabilitou a exibição
                if (isset($settings['dw_pix_show_discount']) && $settings['dw_pix_show_discount'] === 'no') {
                    return;
                }
                
                // Adiciona classes adicionais do Elementor se disponíveis
                $extra_classes = isset($settings['dw_pix_elementor_class']) ? ' ' . esc_attr($settings['dw_pix_elementor_class']) : '';
                $using_elementor = isset($settings['using_elementor']) && $settings['using_elementor'] === true;
                
                // Sempre aplica estilos de "geral" como base em .dw-pix-price-info (background, borda,
                // padding, margin, etc.); quando usa Elementor, o CSS dinâmico do widget sobrescreve
                // apenas o que for alterado no design do PIX (com !important).
                $inline_styles = $this->generate_inline_styles($settings);
                $generated_css = $this->generate_visual_css($settings, 'gallery');
                $font_size = isset($settings['font_size']) ? $settings['font_size'] : '12';
                $base_styles = $inline_styles['container'] . ' font-size: ' . esc_attr($font_size) . 'px;';
                if (!empty($settings['price_color'])) {
                    $base_styles .= ' color: ' . esc_attr($settings['price_color']) . ';';
                }
                $combined_style = $base_styles;
                if (!empty($generated_css)) {
                    $combined_style .= ' ' . $generated_css;
                }
                if (empty($generated_css) || strpos($generated_css, 'margin') === false) {
                    $combined_style .= ' margin-top: 5px;';
                }
                $custom_style = ' style="' . esc_attr($combined_style) . '"';
                $price_color = isset($settings['price_color']) ? $settings['price_color'] : '';
                $price_style = $price_color ? ' style="color: ' . esc_attr($price_color) . '; font-size: ' . esc_attr($font_size) . 'px;"' : '';
                $text_style = '';
                if (!empty($settings['text_color'])) {
                    $text_style = ' style="color: ' . esc_attr($settings['text_color']) . ';"';
                }
                $discount_text_color = isset($settings['discount_text_color']) ? $settings['discount_text_color'] : '#666';
                $discount_style = ' style="color: ' . esc_attr($discount_text_color) . ';"';
                
                // Verifica se deve exibir o ícone na galeria
                $show_icon = isset($settings['show_pix_icon_gallery']) ? ($settings['show_pix_icon_gallery'] === '1' || $settings['show_pix_icon_gallery'] === 1) : true;
                $icon_html = $show_icon ? $this->get_pix_icon_html($settings, true) . ' ' : '';
                
                echo '<div class="dw-pix-price-info-gallery dw-pix-price-info' . $extra_classes . '"' . $custom_style . '>';
                echo $icon_html;
                echo '<span class="dw-pix-price-text"' . $text_style . '>' . esc_html($settings['custom_text']) . ' </span>';
                echo '<span class="dw-pix-price-amount"' . $price_style . '>' . wc_price($pix_price) . '</span>';
                echo ' <span class="dw-pix-discount-percent"' . $discount_style . '>(' . number_format($discount['percentage'], 0) . '% ' . esc_html($settings['discount_text']) . ')</span>';
                echo '</div>';
            }
        }
    }

    /**
     * Obtém configurações globais
     */
    private function get_global_settings() {
        if (class_exists('DW_Pix_Settings')) {
            return DW_Pix_Settings::get_global_settings();
        }
        
        return array(
            'global_discount' => '',
            'show_in_gallery' => '0'
        );
    }

    /**
     * Renderiza a exibição do preço PIX
     *
     * @param float $pix_price Preço PIX
     * @param array $discount Dados do desconto
     */
    private function render_pix_price_display($pix_price, $discount) {
        $discount_percent = number_format($discount['percentage'], 0);
        $settings = $this->get_design_settings();
        
        // Aplica estilos inline baseados nas configurações
        $styles = $this->generate_inline_styles($settings);
        
        // Gera CSS a partir dos campos visuais (página do produto)
        $generated_css = $this->generate_visual_css($settings, 'product');
        
        $container_style = $styles['container'];
        if (!empty($generated_css)) {
            $container_style .= ' ' . $generated_css;
        }
        
        echo '<div class="dw-pix-price-info" style="' . esc_attr($container_style) . '">';
        echo '<p class="dw-pix-price-text" style="' . esc_attr($styles['text']) . '">';
        echo '<span class="pix-icon">' . $this->get_pix_icon_html($settings) . '</span> ' . esc_html($settings['custom_text']) . ' ';
        echo '<span class="dw-pix-price-amount" style="' . esc_attr($styles['price']) . '">' . wc_price($pix_price) . '</span>';
        echo '<span class="dw-pix-discount-percent" style="' . esc_attr($styles['discount']) . '">(' . esc_html($discount_percent) . '% ' . esc_html($settings['discount_text']) . ')</span>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Exibe aviso no carrinho/checkout
     */
    public function show_pix_notice() {
        if ($this->core->cart_has_pix_products()) {
            $message = __('🎉 Produtos com desconto especial para pagamento via PIX! Selecione PIX no checkout para aproveitar.', 'dw-price-to-pix');
            wc_print_notice($message, 'notice');
        }
    }

    /**
     * Adiciona informação de desconto no nome do produto no checkout
     *
     * @param string $product_name Nome do produto
     * @param array $cart_item Item do carrinho
     * @param string $cart_item_key Chave do item do carrinho
     * @return string
     */
    public function add_discount_info_to_cart_item($product_name, $cart_item, $cart_item_key) {
        // Só exibe no checkout
        if (!is_checkout()) {
            return $product_name;
        }
        
        // Verifica se a forma de pagamento é PIX
        $chosen_payment_method = WC()->session->get('chosen_payment_method');
        
        if (!$this->core->is_pix_payment($chosen_payment_method)) {
            return $product_name;
        }
        
        $pix_price = $this->core->get_pix_price_for_cart_item($cart_item);
        
        if ($pix_price > 0) {
            $selling_price = $this->core->get_selling_price_for_cart_item($cart_item);
            
            if ($selling_price > 0 && $pix_price < $selling_price) {
                $discount = $this->core->calculate_pix_discount($selling_price, $pix_price);
                
                // Validações rigorosas antes de exibir
                if ($discount['amount'] > 0 && $discount['percentage'] > 0 && $discount['percentage'] < 100) {
                    $discount_percent = number_format($discount['percentage'], 0);
                    $product_name .= '<br><small class="dw-pix-cart-discount">';
                    $product_name .= '🎉 ' . esc_html__('Desconto PIX:', 'dw-price-to-pix') . ' ' . wc_price($discount['amount']);
                    $product_name .= ' (' . esc_html($discount_percent) . '% OFF)';
                    $product_name .= '</small>';
                }
            }
        }
        
        return $product_name;
    }

    /**
     * Obtém o preço regular correto para um item do carrinho
     *
     * @param array $cart_item Item do carrinho
     * @return float
     */
    private function get_regular_price_for_cart_item($cart_item) {
        // Se é uma variação, obtém o preço regular da variação diretamente do meta
        if (isset($cart_item['variation_id']) && $cart_item['variation_id'] > 0) {
            $variation_regular_price = get_post_meta($cart_item['variation_id'], '_regular_price', true);
            
            if (!empty($variation_regular_price) && is_numeric($variation_regular_price)) {
                $regular_price = floatval($variation_regular_price);
                if ($regular_price > 0) {
                    return $regular_price;
                }
            }
            
            // Se não tem preço regular na variação, tenta o preço normal
            $variation_price = get_post_meta($cart_item['variation_id'], '_price', true);
            if (!empty($variation_price) && is_numeric($variation_price)) {
                $price = floatval($variation_price);
                if ($price > 0) {
                    return $price;
                }
            }
        }
        
        // Para produtos simples, obtém do meta
        $product_id = $cart_item['product_id'];
        $product_regular_price = get_post_meta($product_id, '_regular_price', true);
        
        if (!empty($product_regular_price) && is_numeric($product_regular_price)) {
            $regular_price = floatval($product_regular_price);
            if ($regular_price > 0) {
                return $regular_price;
            }
        }
        
        // Fallback: tenta obter do objeto do produto (mas pode estar modificado)
        $product = $cart_item['data'];
        $regular_price = floatval($product->get_regular_price());
        
        return ($regular_price > 0) ? $regular_price : 0;
    }

    /**
     * Adiciona script para produtos variáveis
     */
    public function add_variation_pix_script() {
        global $product;
        
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        
        // Preços PIX e preço atual (de venda) por variação — base para desconto é o preço atual, não o cheio
        $variation_pix_prices = array();
        $variation_display_prices = array();
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation) {
            $variation_id = $variation['variation_id'];
            $pix_price = get_post_meta($variation_id, '_pix_price', true);
            $display_price = get_post_meta($variation_id, '_price', true);
            if (empty($display_price) || !is_numeric($display_price)) {
                $display_price = get_post_meta($variation_id, '_regular_price', true);
            }
            
            if (!empty($pix_price) && is_numeric($pix_price) && $pix_price > 0) {
                $variation_pix_prices[$variation_id] = floatval($pix_price);
            }
            
            if (!empty($display_price) && is_numeric($display_price)) {
                $variation_display_prices[$variation_id] = floatval($display_price);
            }
        }
        
        if (empty($variation_pix_prices)) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            if (typeof window.DWVariablePixPrice !== 'undefined') {
                window.DWVariablePixPrice.init(
                    <?php echo json_encode($variation_pix_prices); ?>,
                    <?php echo json_encode($variation_display_prices); ?>
                );
            }
        });
        </script>
        <?php
    }

    /**
     * Exibe preço PIX para variações
     */
    public function display_variation_pix_price() {
        global $product;
        
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        
        // Cria um container para o preço PIX que será atualizado via JavaScript
        $settings = $this->get_design_settings();
        echo '<div class="dw-pix-variation-price" style="display: none;" data-dw-pix-settings="' . esc_attr(json_encode($settings)) . '"></div>';
    }

    /**
     * Obtém configurações de design
     */
    private function get_design_settings() {
        if (class_exists('DW_Pix_Settings')) {
            return DW_Pix_Settings::get_design_settings();
        }
        
        // Fallback para configurações padrão
        $default_icon_url = DW_PIX_PLUGIN_URL . 'assets/images/pix-svgrepo-com.svg';
        
        return array(
            'background_color' => '#e8f5e9',
            'border_color' => '#4caf50',
            'text_color' => '#2e7d32',
            'price_color' => '#1b5e20',
            'discount_text_color' => '#666',
            'pix_icon_custom' => $default_icon_url,
            'custom_text' => 'Pagando com PIX:',
            'border_style' => 'solid',
            'font_size' => '16',
            'discount_text' => 'de desconto'
        );
    }

    /**
     * Obtém HTML do ícone PIX (sempre imagem)
     *
     * @param array $settings Configurações de design
     * @return string
     */
    private function get_pix_icon_html($settings, $is_gallery = false) {
        $default_icon_url = DW_PIX_PLUGIN_URL . 'assets/images/pix-svgrepo-com.svg';
        
        // Se é galeria, usa ícone da galeria se disponível, senão usa o do produto, senão usa padrão
        if ($is_gallery) {
            $icon_url = !empty($settings['pix_icon_custom_gallery']) ? $settings['pix_icon_custom_gallery'] : (!empty($settings['pix_icon_custom']) ? $settings['pix_icon_custom'] : $default_icon_url);
        } else {
            // Página do produto
            $icon_url = !empty($settings['pix_icon_custom']) ? $settings['pix_icon_custom'] : $default_icon_url;
        }
        
        // Garante que sempre tenha uma URL válida
        if (empty($icon_url)) {
            $icon_url = $default_icon_url;
        }
        
        return '<img src="' . esc_url($icon_url) . '" alt="PIX" class="dw-pix-icon-image" style="width: 20px; height: 20px; vertical-align: middle; display: inline-block;" />';
    }

    /**
     * Gera CSS a partir dos campos visuais
     * 
     * @param array $settings Configurações
     * @param string $location Localização: 'product' ou 'gallery'
     */
    private function generate_visual_css($settings, $location = 'product') {
        if (!is_array($settings)) {
            return '';
        }
        
        $css_parts = array();
        
        // Determina qual campo usar baseado na localização
        $margin_key = ($location === 'gallery') ? 'pix_margin_gallery' : 'pix_margin_product';
        $padding_key = ($location === 'gallery') ? 'pix_padding_gallery' : 'pix_padding_product';
        
        // Margin - só gera se houver valores configurados e diferentes de 0 (permite negativos)
        if (isset($settings[$margin_key]) && is_array($settings[$margin_key])) {
            $margin = $settings[$margin_key];
            // Verifica se pelo menos um valor é diferente de 0 ou vazio (pode ser negativo)
            $has_margin = false;
            foreach (array('top', 'right', 'bottom', 'left') as $side) {
                if (isset($margin[$side]) && $margin[$side] !== '' && floatval($margin[$side]) != 0) {
                    $has_margin = true;
                    break;
                }
            }
            if ($has_margin) {
                $margin_css = DW_Pix_Settings::generate_spacing_css($margin, 'margin');
                if (!empty($margin_css)) {
                    $css_parts[] = $margin_css;
                }
            }
        }
        
        // Padding - só gera se houver valores configurados e diferentes de 0
        if (isset($settings[$padding_key]) && is_array($settings[$padding_key])) {
            $padding = $settings[$padding_key];
            // Verifica se pelo menos um valor é diferente de 0 ou vazio
            $has_padding = false;
            foreach (array('top', 'right', 'bottom', 'left') as $side) {
                if (isset($padding[$side]) && floatval($padding[$side]) > 0) {
                    $has_padding = true;
                    break;
                }
            }
            if ($has_padding) {
                $padding_css = DW_Pix_Settings::generate_spacing_css($padding, 'padding');
                if (!empty($padding_css)) {
                    $css_parts[] = $padding_css;
                }
            }
        }
        
        // Border Radius - só gera se houver valor configurado e diferente de 0 (global)
        if (isset($settings['pix_border_radius']) && is_array($settings['pix_border_radius'])) {
            $border_radius = $settings['pix_border_radius'];
            if (isset($border_radius['value']) && floatval($border_radius['value']) > 0) {
                $border_radius_css = DW_Pix_Settings::generate_border_radius_css($border_radius);
                if (!empty($border_radius_css)) {
                    $css_parts[] = $border_radius_css;
                }
            }
        }
        
        return !empty($css_parts) ? implode(' ', $css_parts) : '';
    }

    /**
     * Gera estilos inline baseados nas configurações
     */
    private function generate_inline_styles($settings) {
        // Trata fundo transparente
        $bg_color = isset($settings['background_color']) ? $settings['background_color'] : '#e8f5e9';
        $allow_transparent = isset($settings['allow_transparent_background_pix']) && $settings['allow_transparent_background_pix'] === '1';
        
        // Se permite transparente e a cor está vazia ou é "transparent", usa transparente
        if ($allow_transparent && (empty($bg_color) || strtolower($bg_color) === 'transparent')) {
            $bg_color = 'transparent';
        }
        
        // Cor da borda (configurável)
        $border_color = isset($settings['border_color']) ? $settings['border_color'] : '#4caf50';
        $hide_border = isset($settings['hide_border']) && $settings['hide_border'] === '1';
        
        // Se deve remover a borda
        if ($hide_border) {
            $border_css = 'border-left: none;';
        } else {
            $border_css = sprintf('border-left: 4px %s %s;', $settings['border_style'], $border_color);
        }
        
        // Cor do texto de desconto
        $discount_text_color = isset($settings['discount_text_color']) ? $settings['discount_text_color'] : '#666';
        
        return array(
            'container' => sprintf(
                'background-color: %s; %s',
                $bg_color,
                $border_css
            ),
            'text' => sprintf(
                'color: %s; font-size: %spx;',
                $settings['text_color'],
                $settings['font_size']
            ),
            'price' => sprintf(
                'color: %s; font-size: %spx;',
                $settings['price_color'],
                isset($settings['font_size']) ? $settings['font_size'] : '16'
            ),
            'discount' => sprintf(
                'color: %s;',
                $discount_text_color
            )
        );
    }

    /**
     * Enfileira estilos CSS e scripts
     */
    public function enqueue_assets() {
        // Carrega CSS em todas as páginas WooCommerce (produto, loja, carrinho, checkout)
        if (is_woocommerce() || is_cart() || is_checkout() || is_product_category() || is_product_tag()) {
            wp_enqueue_style(
                'dw-pix-frontend',
                DW_PIX_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                DW_PIX_VERSION
            );
        }
        
        // JavaScript para produtos variáveis (apenas na página do produto)
        if (is_product()) {
            wp_enqueue_script(
                'dw-pix-variable-products',
                DW_PIX_PLUGIN_URL . 'assets/js/variable-products.js',
                array('jquery'),
                DW_PIX_VERSION,
                true
            );
        }
    }

    /**
     * Endpoint AJAX para obter preço PIX na grade do Elementor
     */
    public function ajax_get_pix_price_for_grid() {
        // Verifica nonce (mais permissivo para evitar bloqueios de firewall)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        // Validação de nonce mais permissiva
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'dw_pix_grid_nonce')) {
            // Se o nonce foi enviado mas é inválido, retorna erro
            wp_send_json_error(array('message' => __('Erro de segurança.', 'dw-price-to-pix')), 403);
            return;
        }

        // Obtém ID ou slug do produto
        $product_identifier = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : '';
        
        if (!$product_identifier) {
            wp_send_json_error(array('message' => __('ID do produto não fornecido.', 'dw-price-to-pix')), 400);
            return;
        }

        // Tenta obter produto por ID primeiro
        $product_id = intval($product_identifier);
        $product = null;
        
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
        }
        
        // Se não encontrou por ID, tenta por slug
        if (!$product && !is_numeric($product_identifier)) {
            // Busca produto por slug
            $args = array(
                'name' => $product_identifier,
                'post_type' => 'product',
                'post_status' => 'publish',
                'numberposts' => 1
            );
            $products = get_posts($args);
            
            if (!empty($products)) {
                $product = wc_get_product($products[0]->ID);
            }
        }
        
        if (!$product) {
            // Retorna sucesso vazio ao invés de erro (evita poluir console)
            wp_send_json_success(array('html' => ''));
            return;
        }

        $current_price = floatval($product->get_price());
        
        if ($current_price <= 0) {
            wp_send_json_success(array('html' => ''));
            return;
        }

        $pix_price = $this->core->get_pix_price($product->get_id(), true, $current_price);
        
        if (!$pix_price || $pix_price <= 0 || $pix_price >= $current_price) {
            wp_send_json_success(array('html' => ''));
            return;
        }

        $discount = $this->core->calculate_pix_discount($current_price, $pix_price);
        
        // Obtém configurações de design
        $settings = $this->get_design_settings();
        
        // Renderiza HTML do preço PIX
        $html = $this->render_pix_price_for_grid($pix_price, $discount, $settings);

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Renderiza preço PIX para grade de produtos
     *
     * @param float $pix_price Preço PIX
     * @param array $discount Dados do desconto
     * @param array $settings Configurações de design
     * @return string
     */
    private function render_pix_price_for_grid($pix_price, $discount, $settings) {
        $discount_percent = number_format($discount['percentage'], 0);
        
        // Estilos inline baseados nas configurações
        $styles = array();
        
        // Cor de fundo
        if (!empty($settings['background_color'])) {
            $styles[] = 'background-color: ' . esc_attr($settings['background_color']) . ';';
        }
        
        // Cor do texto
        $text_color = !empty($settings['text_color']) ? $settings['text_color'] : '#2e7d32';
        $styles[] = 'color: ' . esc_attr($text_color) . ';';
        
        // Padding
        if (!empty($settings['pix_padding_gallery'])) {
            $padding = $settings['pix_padding_gallery'];
            if (is_array($padding)) {
                $styles[] = sprintf(
                    'padding: %s %s %s %s;',
                    esc_attr($padding['top'] ?? '0'),
                    esc_attr($padding['right'] ?? '0'),
                    esc_attr($padding['bottom'] ?? '0'),
                    esc_attr($padding['left'] ?? '0')
                );
            }
        }
        
        // Margin
        if (!empty($settings['pix_margin_gallery'])) {
            $margin = $settings['pix_margin_gallery'];
            if (is_array($margin)) {
                $styles[] = sprintf(
                    'margin: %s %s %s %s;',
                    esc_attr($margin['top'] ?? '0'),
                    esc_attr($margin['right'] ?? '0'),
                    esc_attr($margin['bottom'] ?? '0'),
                    esc_attr($margin['left'] ?? '0')
                );
            }
        }
        
        // Border radius
        if (!empty($settings['pix_border_radius'])) {
            $border_radius = $settings['pix_border_radius'];
            if (is_array($border_radius)) {
                $styles[] = sprintf(
                    'border-radius: %s %s %s %s;',
                    esc_attr($border_radius['top'] ?? '0'),
                    esc_attr($border_radius['right'] ?? '0'),
                    esc_attr($border_radius['bottom'] ?? '0'),
                    esc_attr($border_radius['left'] ?? '0')
                );
            }
        }
        
        // Border
        if (!empty($settings['border_color']) && (!isset($settings['hide_border']) || $settings['hide_border'] !== '1')) {
            $border_style = !empty($settings['border_style']) ? $settings['border_style'] : 'solid';
            $styles[] = 'border-left: 4px ' . esc_attr($border_style) . ' ' . esc_attr($settings['border_color']) . ';';
        }
        
        $container_style = !empty($styles) ? ' style="' . implode(' ', $styles) . '"' : '';
        
        // Cor do preço
        $price_color = !empty($settings['price_color']) ? $settings['price_color'] : '#1b5e20';
        $price_style = 'color: ' . esc_attr($price_color) . ';';
        
        // Cor do desconto
        $discount_color = !empty($settings['discount_text_color']) ? $settings['discount_text_color'] : '#666';
        $discount_style = 'color: ' . esc_attr($discount_color) . ';';
        
        // Ícone PIX
        $icon_html = '';
        if (!empty($settings['pix_icon_custom_gallery']) || !empty($settings['pix_icon_custom'])) {
            $icon_url = !empty($settings['pix_icon_custom_gallery']) ? $settings['pix_icon_custom_gallery'] : $settings['pix_icon_custom'];
            $icon_html = '<img src="' . esc_url($icon_url) . '" alt="PIX" style="width: 20px; height: 20px; vertical-align: middle; display: inline-block; margin-right: 5px;" />';
        }
        
        // Texto personalizado
        $custom_text = !empty($settings['custom_text']) ? $settings['custom_text'] : 'Pagando com PIX:';
        $discount_text = !empty($settings['discount_text']) ? $settings['discount_text'] : 'de desconto';
        
        // Tamanho da fonte
        $font_size = !empty($settings['font_size']) ? $settings['font_size'] : '16';
        $text_style = 'font-size: ' . esc_attr($font_size) . 'px;';
        
        ob_start();
        ?>
        <div class="dw-pix-price-info dw-pix-price-info-gallery"<?php echo $container_style; ?>>
            <p class="dw-pix-price-text" style="<?php echo esc_attr($text_style . ' color: ' . $text_color); ?>">
                <?php if ($icon_html): ?>
                    <span class="pix-icon"><?php echo $icon_html; ?></span>
                <?php endif; ?>
                <?php echo esc_html($custom_text); ?> 
                <span class="dw-pix-price-amount" style="<?php echo esc_attr($price_style . ' font-size: ' . $font_size . 'px;'); ?>"><?php echo wc_price($pix_price); ?></span>
                <span class="dw-pix-discount-percent" style="<?php echo esc_attr($discount_style); ?>">(<?php echo esc_html($discount_percent); ?>% <?php echo esc_html($discount_text); ?>)</span>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}

