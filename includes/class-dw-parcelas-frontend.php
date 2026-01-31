<?php
/**
 * Classe para exibição de parcelas no frontend
 *
 * @package DW_Parcelas_Pix_WooCommerce
 */

// Evita acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe DW_Parcelas_Frontend
 */
class DW_Parcelas_Frontend {

    /**
     * Instância da classe core de parcelas
     *
     * @var DW_Parcelas_Installments_Core
     */
    private $core;

    /**
     * Construtor
     */
    public function __construct() {
        $this->core = new DW_Parcelas_Installments_Core();
        $this->init_hooks();
    }

    /**
     * Inicializa os hooks
     */
    private function init_hooks() {
        // Verifica se parcelas estão habilitadas primeiro
        if (!DW_Parcelas_Installments_Core::is_enabled()) {
            return;
        }
        
        $settings = $this->get_installments_settings();
        $display_locations = isset($settings['display_locations']) ? $settings['display_locations'] : array('product' => '1');
        
        // Verifica se deve exibir na página do produto
        $show_in_product = true; // Padrão: sempre exibir
        if (isset($display_locations['product'])) {
            $show_in_product = ($display_locations['product'] === '1' || $display_locations['product'] === 1 || $display_locations['product'] === true);
        }
        
        if ($show_in_product) {
            $product_position = isset($settings['product_position']) ? $settings['product_position'] : 'before_add_to_cart';
            
            // Define prioridades baseadas na posição escolhida
            $priorities = $this->get_hook_priorities($product_position);
            
            // Resumo das parcelas
            add_action('woocommerce_single_product_summary', array($this, 'display_installments_summary'), $priorities['summary']);
            
            // Tabela das parcelas (sempre depois do resumo e do PIX)
            // Prioridade ajustada: summary + 2 para ficar após PIX (que é summary + 1)
            add_action('woocommerce_single_product_summary', array($this, 'display_installments_table'), $priorities['summary'] + 2);
            
            // Hooks adicionais para garantir posicionamento antes do botão (compatibilidade com Elementor)
            // Usa as mesmas prioridades calculadas para manter ordem relativa
            add_action('woocommerce_before_add_to_cart_form', array($this, 'display_installments_summary'), $priorities['summary']);
            add_action('woocommerce_before_add_to_cart_form', array($this, 'display_installments_table'), $priorities['summary'] + 2);
            
            // Hook antes do botão especificamente (usa prioridades calculadas)
            add_action('woocommerce_before_add_to_cart_button', array($this, 'display_installments_summary'), $priorities['summary']);
            add_action('woocommerce_before_add_to_cart_button', array($this, 'display_installments_table'), $priorities['summary'] + 2);
        }
        
        // Para produtos variáveis, adiciona JavaScript para atualizar parcelas
        add_action('woocommerce_single_product_summary', array($this, 'add_variation_installments_script'), 25);
        
        // Hook adicional para produtos variáveis
        add_action('woocommerce_single_variation', array($this, 'display_variation_installments'), 19);
        
        // Galeria de produtos (antes do PIX)
        if (isset($display_locations['gallery']) && ($display_locations['gallery'] === '1' || $display_locations['gallery'] === 1)) {
            add_action('woocommerce_after_shop_loop_item_title', array($this, 'display_installments_in_gallery'), 15);
        }
        
        // Carrinho
        if (isset($display_locations['cart']) && ($display_locations['cart'] === '1' || $display_locations['cart'] === 1)) {
            add_action('woocommerce_after_cart_item_name', array($this, 'display_installments_in_cart'), 10, 2);
        }
        
        // Checkout
        if (isset($display_locations['checkout']) && ($display_locations['checkout'] === '1' || $display_locations['checkout'] === 1)) {
            add_action('woocommerce_after_checkout_item_name', array($this, 'display_installments_in_checkout'), 10, 2);
        }
        
        // Adiciona estilos CSS e scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Adiciona CSS personalizado para a tabela (prioridade 999 = por último)
        add_action('wp_head', array($this, 'inject_table_custom_css'), 999);
        
        // Adiciona CSS inline também no footer como fallback (prioridade máxima)
        add_action('wp_footer', array($this, 'inject_table_custom_css'), 999);
    }

    /**
     * Exibe o resumo das parcelas na página do produto
     */
    public function display_installments_summary() {
        global $product;
        
        // Evita duplicação - verifica se já foi exibido nesta requisição
        static $displayed = false;
        if ($displayed) {
            return;
        }
        
        if (!$product || !DW_Parcelas_Installments_Core::is_enabled()) {
            return;
        }
        
        $price = DW_Parcelas_Installments_Core::get_product_price($product);
        
        if ($price <= 0) {
            return;
        }
        
        // Se é produto variável, não exibe aqui (será exibido via JavaScript)
        if ($product->is_type('variable')) {
            return;
        }
        
        $displayed = true;
        $this->render_installments_summary_only($price, $product->get_id());
    }

    /**
     * Exibe apenas a tabela das parcelas (depois do resumo)
     */
    public function display_installments_table() {
        global $product;
        
        // Evita duplicação - verifica se já foi exibido nesta requisição
        static $table_displayed = false;
        if ($table_displayed) {
            return;
        }
        
        if (!$product || !DW_Parcelas_Installments_Core::is_enabled()) {
            return;
        }
        
        $settings = $this->get_installments_settings();
        
        // Verifica se deve exibir a tabela
        if (!isset($settings['show_table']) || $settings['show_table'] === '0') {
            return;
        }
        
        $price = DW_Parcelas_Installments_Core::get_product_price($product);
        
        if ($price <= 0) {
            return;
        }
        
        // Se é produto variável, não exibe aqui (será exibido via JavaScript)
        if ($product->is_type('variable')) {
            return;
        }
        
        $table_displayed = true;
        $this->render_installments_table_only($price, $product->get_id());
    }

    /**
     * Renderiza apenas o resumo das parcelas
     *
     * @param float $price Preço do produto
     * @param int $product_id ID do produto
     */
    private function render_installments_summary_only($price, $product_id = 0) {
        $best = DW_Parcelas_Installments_Core::get_best_installment($price, $product_id);
        
        if (!$best) {
            return;
        }
        
        $design_settings = $this->get_installments_design_settings();
        
        echo '<div class="dw-parcelas-container dw-parcelas-summary-container">';
        $this->render_best_installment_summary($best, $price, $design_settings, 'product');
        echo '</div>';
    }

    /**
     * Renderiza apenas a tabela das parcelas
     *
     * @param float $price Preço do produto
     * @param int $product_id ID do produto
     */
    private function render_installments_table_only($price, $product_id = 0) {
        $installments = DW_Parcelas_Installments_Core::calculate_installments($price, $product_id);
        
        if (empty($installments)) {
            return;
        }
        
        $settings = $this->get_installments_settings();
        $design_settings = $this->get_installments_design_settings();
        $table_display_type = isset($settings['table_display_type']) ? $settings['table_display_type'] : 'accordion';
        
        // Wrapper externo para a tabela
        echo '<div class="dw-parcelas-table-container-wrapper">';
        $this->render_installments_table($installments, $table_display_type, $design_settings, $price);
        echo '</div>';
    }

    /**
     * Renderiza o resumo da melhor condição
     *
     * @param array $best Melhor parcela
     * @param float $price Preço total
     * @param array $design_settings Configurações de design
     * @param string $location Localização (product, gallery, cart, checkout)
     */
    private function render_best_installment_summary($best, $price, $design_settings = array(), $location = 'product') {
        $settings = $this->get_installments_settings();
        
        $price_formatted = wc_price($price);
        $installment_value = wc_price($best['valor']);
        $installment_number = $best['numero'];
        
        // Textos por localização ou globais
        $location_texts = isset($settings['location_texts'][$location]) ? $settings['location_texts'][$location] : array();
        
        // Texto antes das parcelas
        $text_before = '';
        if (!empty($location_texts['text_before'])) {
            $text_before = esc_html($location_texts['text_before']) . ' ';
        } elseif (!empty($settings['text_before_installments'])) {
            $text_before = esc_html($settings['text_before_installments']) . ' ';
        }
        
        // Texto após as parcelas
        $text_after = '';
        if (!empty($location_texts['text_after'])) {
            $text_after = ' ' . esc_html($location_texts['text_after']);
        } elseif (!empty($settings['text_after_installments'])) {
            $text_after = ' ' . esc_html($settings['text_after_installments']);
        } elseif (!$best['has_interest']) {
            // Se não tem texto personalizado e não tem juros, usa "sem juros"
            $text_after = ' ' . __('sem juros', 'dw-parcelas-customizadas-woo');
        }
        
        // Ícone do cartão (se habilitado)
        $icon_html = '';
        $icon_position = isset($design_settings['credit_card_icon_position']) ? $design_settings['credit_card_icon_position'] : 'before';
        
        $is_gallery = ($location === 'gallery');
        
        // Verifica se deve exibir o ícone
        $show_icon = false;
        if ($is_gallery) {
            // Na galeria, verifica se está habilitado para galeria
            $show_icon = isset($design_settings['show_credit_card_icon_gallery']) && $design_settings['show_credit_card_icon_gallery'] === '1';
        } else {
            // Na página do produto, verifica se está habilitado
            $show_icon = isset($design_settings['show_credit_card_icon']) && $design_settings['show_credit_card_icon'] === '1';
        }
        
        if ($show_icon && $icon_position !== 'none') {
            $icon_html = '<span class="dw-parcelas-icon">' . $this->get_credit_card_icon($design_settings, $is_gallery) . '</span>';
        }
        
        // Verifica se está usando Elementor
        $using_elementor = isset($design_settings['using_elementor']) && $design_settings['using_elementor'] === true;
        
        // Monta estilos inline APENAS se NÃO estiver usando Elementor
        $container_style_attr = '';
        $text_style_attr = '';
        
        if (!$using_elementor) {
            // Gera estilos inline baseados nas configurações
            $styles = $this->generate_summary_styles($design_settings);
            
            // Gera CSS a partir dos campos visuais (usa localização correta)
            $generated_css = $this->generate_visual_css($design_settings, $location);
            
            $container_style = $styles['container'];
            if (!empty($generated_css)) {
                $container_style .= ' ' . $generated_css;
            }
            
            $container_style_attr = ' style="' . esc_attr($container_style) . '"';
            $text_style_attr = ' style="' . esc_attr($styles['text']) . '"';
        }
        
        // Classe diferente para galeria
        $container_class = ($location === 'gallery') ? 'dw-parcelas-summary-gallery' : 'dw-parcelas-summary';
        
        echo '<div class="' . esc_attr($container_class) . '"' . $container_style_attr . '>';
        
        // Posiciona ícone antes ou depois do texto
        if ($icon_position === 'before') {
            echo $icon_html;
        }
        
        echo '<span class="dw-parcelas-text"' . $text_style_attr . '>';
        echo esc_html($text_before);
        printf(
            __('até %dx de %s%s', 'dw-parcelas-customizadas-woo'),
            $installment_number,
            '<span class="dw-installment-value">' . $installment_value . '</span>',
            $text_after
        );
        echo '</span>';
        
        if ($icon_position === 'after') {
            echo $icon_html;
        }
        
        echo '</div>';
    }

    /**
     * Renderiza a tabela de parcelas (incluindo PIX se disponível)
     *
     * @param array $installments Array de parcelas
     * @param string $display_type Tipo de exibição (accordion, popup, open)
     * @param array $design_settings Configurações de design
     * @param float $product_price Preço do produto para calcular PIX
     */
    private function render_installments_table($installments, $display_type = 'accordion', $design_settings = array(), $product_price = 0) {
        $table_class = 'dw-parcelas-table';
        $table_id = 'dw-parcelas-table-' . uniqid();
        $wrapper_class = 'dw-parcelas-table-wrapper';
        $wrapper_id = 'dw-parcelas-wrapper-' . uniqid();
        
        // Adiciona classe baseado no tipo de exibição
        $wrapper_class .= ' dw-parcelas-display-' . esc_attr($display_type);
        
        if ($display_type === 'open') {
            // Tabela sempre aberta
            $table_class .= ' dw-parcelas-table-visible';
        } else {
            // Accordion ou Popup - começa fechada
            $table_class .= ' dw-parcelas-table-hidden';
        }
        
        echo '<div id="' . esc_attr($wrapper_id) . '" class="' . esc_attr($wrapper_class) . '" data-display-type="' . esc_attr($display_type) . '">';
        
        // Botão para abrir (accordion ou popup)
        if ($display_type !== 'open') {
            $button_text = __('Ver tabela de preço', 'dw-parcelas-customizadas-woo');
            echo '<button type="button" class="dw-parcelas-toggle-btn dw-parcelas-btn-' . esc_attr($display_type) . '" data-target="' . esc_attr($table_id) . '" data-wrapper="' . esc_attr($wrapper_id) . '">';
            echo esc_html($button_text);
            echo '</button>';
        }
        
        // Para popup, cria estrutura diferente
        if ($display_type === 'popup') {
            // Cria um container escondido com o conteúdo do popup
            // Remove classe hidden da tabela para popup
            $table_class = str_replace('dw-parcelas-table-hidden', '', $table_class);
            echo '<div class="dw-parcelas-popup-content-hidden" style="display:none;">';
            echo '<table id="' . esc_attr($table_id) . '" class="' . esc_attr(trim($table_class)) . '" style="display:table; width:100%;">';
        } else {
            // Para accordion ou aberto, usa container normal
            $container_style = ($display_type === 'open') ? 'display:block;' : 'display:none;';
            echo '<div class="dw-parcelas-table-container" style="' . esc_attr($container_style) . '">';
            $table_style = ($display_type === 'open') ? 'display:table; width:100%;' : '';
            echo '<table id="' . esc_attr($table_id) . '" class="' . esc_attr($table_class) . '" style="' . esc_attr($table_style) . '">';
        }
        
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Forma de Pagamento', 'dw-parcelas-customizadas-woo') . '</th>';
        echo '<th>' . __('Total', 'dw-parcelas-customizadas-woo') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        // Adiciona linha do PIX se disponível
        if ($product_price > 0) {
            // Verifica se tem preço PIX configurado
            global $product;
            if ($product) {
                $pix_core = new DW_Pix_Core();
                $current_price = floatval($product->get_price());
                $pix_price = $pix_core->get_pix_price($product->get_id(), true, $current_price);
                
                if ($pix_price > 0 && $pix_price < $current_price) {
                    $discount = $pix_core->calculate_pix_discount($current_price, $pix_price);
                    if ($discount['percentage'] > 0) {
                        echo '<tr class="dw-parcelas-pix-row">';
                        echo '<td>';
                        echo '<strong>PIX</strong> <span class="dw-parcelas-label dw-parcelas-pix-discount">(' . number_format($discount['percentage'], 0) . '% ' . __('de desconto', 'dw-parcelas-customizadas-woo') . ')</span>';
                        echo '</td>';
                        echo '<td>';
                        echo '<strong class="dw-pix-price-highlight">' . wc_price($pix_price) . '</strong>';
                        echo '</td>';
                        echo '</tr>';
                    }
                }
            }
        }
        
        foreach ($installments as $installment) {
            $row_class = $installment['has_interest'] ? '' : 'dw-parcelas-no-interest';
            echo '<tr class="' . esc_attr($row_class) . '">';
            
            // Coluna de parcelas
            echo '<td>';
            echo esc_html($installment['numero']) . 'x de ' . wc_price($installment['valor']);
            if (!$installment['has_interest']) {
                echo ' <span class="dw-parcelas-label">' . __('sem juros', 'dw-parcelas-customizadas-woo') . '</span>';
            }
            echo '</td>';
            
            // Coluna de total
            echo '<td>';
            echo '<strong>' . wc_price($installment['total']) . '</strong>';
            echo '</td>';
            
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        // Botão fechar para popup
        if ($display_type === 'popup') {
            echo '<button type="button" class="dw-parcelas-popup-close" data-target="' . esc_attr($wrapper_id) . '">' . __('Fechar', 'dw-parcelas-customizadas-woo') . '</button>';
            echo '</div>'; // .dw-parcelas-popup-content-hidden
        } else {
            echo '</div>'; // .dw-parcelas-table-container
        }
        
        echo '</div>'; // .dw-parcelas-table-wrapper
    }

    /**
     * Adiciona script para produtos variáveis
     */
    public function add_variation_installments_script() {
        global $product;
        
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        
        if (!DW_Parcelas_Installments_Core::is_enabled()) {
            return;
        }
        
        // Obtém preços das variações
        $variation_prices = array();
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation) {
            $variation_id = $variation['variation_id'];
            $variation_obj = wc_get_product($variation_id);
            
            if ($variation_obj) {
                $price = DW_Parcelas_Installments_Core::get_product_price($variation_obj);
                if ($price > 0) {
                    $variation_prices[$variation_id] = $price;
                }
            }
        }
        
        if (empty($variation_prices)) {
            return;
        }
        
        $settings = $this->get_installments_settings();
        $design_settings = $this->get_installments_design_settings();
        
        // Localiza strings para JavaScript
        $strings = array(
            'showText' => __('Ver tabela de preço', 'dw-parcelas-customizadas-woo'),
            'hideText' => __('Ocultar tabela', 'dw-parcelas-customizadas-woo'),
            'installmentsLabel' => __('Forma de Pagamento', 'dw-parcelas-customizadas-woo'),
            'totalLabel' => __('Total', 'dw-parcelas-customizadas-woo'),
            'withoutInterest' => __('sem juros', 'dw-parcelas-customizadas-woo'),
            'closeText' => __('Fechar', 'dw-parcelas-customizadas-woo')
        );
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Localiza dados para JavaScript
            window.dwParcelasData = {
                variationPrices: <?php echo json_encode($variation_prices); ?>,
                settings: <?php echo json_encode($settings); ?>,
                designSettings: <?php echo json_encode($design_settings); ?>,
                strings: <?php echo json_encode($strings); ?>
            };
            
            // Inicializa o sistema de parcelas para produtos variáveis
            if (typeof window.DWVariableInstallments !== 'undefined') {
                window.DWVariableInstallments.init();
            }
        });
        </script>
        <?php
    }

    /**
     * Exibe parcelas para variações
     */
    public function display_variation_installments() {
        global $product;
        
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        
        if (!DW_Parcelas_Installments_Core::is_enabled()) {
            return;
        }
        
        // Cria um container para as parcelas que será atualizado via JavaScript
        echo '<div class="dw-parcelas-variation-container" style="display: none;"></div>';
    }

    /**
     * Obtém configurações de parcelas
     *
     * @return array
     */
    private function get_installments_settings() {
        return DW_Parcelas_Installments_Core::get_settings();
    }

    /**
     * Obtém configurações de design das parcelas
     *
     * @return array
     */
    private function get_installments_design_settings() {
        if (class_exists('DW_Pix_Settings')) {
            return DW_Pix_Settings::get_installments_design_settings();
        }
        
        return array(
            'background_color' => '#f5f5f5',
            'border_color' => '#2c3e50',
            'text_color' => '#333333',
            'price_color' => '#2c3e50',
            'border_style' => 'solid',
            'font_size' => '16'
        );
    }

    /**
     * Retorna as prioridades dos hooks baseadas na posição escolhida
     * 
     * ORDEM CORRETA: Resumo → PIX → Tabela
     * - Resumo: prioridade N
     * - PIX: prioridade N+1 (definido em class-dw-pix-frontend.php)
     * - Tabela: prioridade N+2 (para ficar sempre após o PIX)
     *
     * @param string $position Posição escolhida
     * @return array Array com prioridade para summary (tabela usa summary+2)
     */
    private function get_hook_priorities($position) {
        // Prioridades padrão do WooCommerce:
        // 5 - título
        // 10 - rating
        // 20 - preço
        // 30 - excerpt
        // 35 - resumo parcelas
        // 36 - PIX
        // 37 - tabela parcelas
        // 40 - add to cart (botão comprar)
        // 50 - meta
        
        // IMPORTANTE: Resumo, PIX e Tabela sempre devem aparecer ANTES do botão (prioridade < 40)
        
        switch ($position) {
            case 'before_price':
                return array('summary' => 15);
            
            case 'after_price':
                return array('summary' => 25);
            
            case 'before_add_to_cart':
                // Antes do botão (padrão recomendado)
                return array('summary' => 35);
            
            case 'after_add_to_cart':
            case 'before_meta':
            case 'after_meta':
                // Sempre força para antes do botão
                return array('summary' => 35);
            
            default:
                // Padrão: antes do botão
                return array('summary' => 35);
        }
    }

    /**
     * Exibe parcelas na galeria de produtos
     */
    public function display_installments_in_gallery() {
        global $product;
        
        if (!$product || !DW_Parcelas_Installments_Core::is_enabled()) {
            return;
        }
        
        $price = DW_Parcelas_Installments_Core::get_product_price($product);
        
        if ($price <= 0) {
            return;
        }
        
        $best = DW_Parcelas_Installments_Core::get_best_installment($price, $product->get_id());
        
        if (!$best) {
            return;
        }
        
        $settings = $this->get_installments_settings();
        $design_settings = $this->get_installments_design_settings();
        
        // Permite que o Elementor modifique as configurações
        $design_settings = apply_filters('dw_installments_gallery_settings', $design_settings, $product);
        
        // Verifica se o Elementor desabilitou a exibição
        if (isset($design_settings['dw_show_installments']) && $design_settings['dw_show_installments'] === 'no') {
            return;
        }
        
        // Adiciona classes adicionais do Elementor se disponíveis
        $extra_classes = isset($design_settings['dw_installments_elementor_class']) ? ' ' . esc_attr($design_settings['dw_installments_elementor_class']) : '';
        // Sempre aplica estilos de "geral" como base; quando usa Elementor, o CSS dinâmico
        // do widget sobrescreve apenas o que for alterado no design (com !important).
        $generated_css = $this->generate_visual_css($design_settings, 'gallery');
        $wrapper_style = !empty($generated_css) ? ' style="' . esc_attr($generated_css) . '"' : '';
        
        echo '<div class="dw-parcelas-gallery-wrapper dw-installments-info dw-installments-info-gallery' . $extra_classes . '"' . $wrapper_style . '>';
        $this->render_best_installment_summary($best, $price, $design_settings, 'gallery');
        echo '</div>';
    }

    /**
     * Exibe parcelas no carrinho
     */
    public function display_installments_in_cart($cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        $price = floatval($product->get_price());
        
        if ($price <= 0) {
            return;
        }
        
        $best = DW_Parcelas_Installments_Core::get_best_installment($price, $product->get_id());
        
        if (!$best) {
            return;
        }
        
        $design_settings = $this->get_installments_design_settings();
        $this->render_best_installment_summary($best, $price, $design_settings, 'cart');
    }

    /**
     * Exibe parcelas no checkout
     */
    public function display_installments_in_checkout($cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        $price = floatval($product->get_price());
        
        if ($price <= 0) {
            return;
        }
        
        $best = DW_Parcelas_Installments_Core::get_best_installment($price, $product->get_id());
        
        if (!$best) {
            return;
        }
        
        $design_settings = $this->get_installments_design_settings();
        $this->render_best_installment_summary($best, $price, $design_settings, 'checkout');
    }

    /**
     * Gera estilos inline para o resumo baseado nas configurações
     *
     * @param array $settings Configurações de design
     * @return array
     */
    private function generate_summary_styles($settings) {
        $bg_color = isset($settings['background_color']) ? $settings['background_color'] : '#f5f5f5';
        $allow_transparent = isset($settings['allow_transparent_background']) && $settings['allow_transparent_background'] === '1';
        
        // Se permitir transparente e a cor for vazia ou "transparent", usa transparente
        if ($allow_transparent && (empty($bg_color) || strtolower($bg_color) === 'transparent')) {
            $bg_color = 'transparent';
        }
        
        $border_style = isset($settings['border_style']) ? $settings['border_style'] : 'solid';
        $border_color = isset($settings['border_color']) ? $settings['border_color'] : '#2c3e50';
        
        // Se border_style for 'none', não adiciona borda
        $border_css = '';
        if ($border_style !== 'none') {
            $border_css = sprintf('border-left: 4px %s %s;', $border_style, $border_color);
        }
        
        return array(
            'container' => sprintf(
                'background-color: %s; %s padding: 15px; border-radius: 8px; margin-bottom: 10px;',
                $bg_color,
                $border_css
            ),
            'text' => sprintf(
                'color: %s; font-size: %spx;',
                isset($settings['text_color']) ? $settings['text_color'] : '#333333',
                isset($settings['font_size']) ? $settings['font_size'] : '16'
            )
        );
    }

    /**
     * Retorna o ícone de cartão de crédito (SVG ou imagem)
     *
     * @param array $design_settings Configurações de design
     * @return string
     */
    private function get_credit_card_icon($design_settings = array(), $is_gallery = false) {
        $default_icon_url = DW_PARCELAS_PLUGIN_URL . 'assets/images/credit-card.svg';
        
        // Se é galeria, usa ícone da galeria se disponível, senão usa o do produto, senão usa padrão
        if ($is_gallery) {
            $icon_url = !empty($design_settings['credit_card_icon_custom_gallery']) ? $design_settings['credit_card_icon_custom_gallery'] : (!empty($design_settings['credit_card_icon_custom']) ? $design_settings['credit_card_icon_custom'] : $default_icon_url);
        } else {
            // Página do produto
            $icon_url = isset($design_settings['credit_card_icon_custom']) ? $design_settings['credit_card_icon_custom'] : $default_icon_url;
        }
        
        if (empty($icon_url)) {
            $icon_url = $default_icon_url;
        }
        
        // Verifica se é SVG ou imagem
        $extension = strtolower(pathinfo($icon_url, PATHINFO_EXTENSION));
        
        if ($extension === 'svg') {
            // Se for SVG, tenta ler o arquivo
            $icon_path = str_replace(DW_PARCELAS_PLUGIN_URL, DW_PARCELAS_PLUGIN_DIR, $icon_url);
            
            if (file_exists($icon_path)) {
                $svg_content = file_get_contents($icon_path);
                if ($svg_content) {
                    return $svg_content;
                }
            }
            
            // Fallback: ícone SVG inline
            return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M20 4H4C2.89 4 2.01 4.89 2.01 6L2 18C2 19.11 2.89 20 4 20H20C21.11 20 22 19.11 22 18V6C22 4.89 21.11 4 20 4ZM20 18H4V12H20V18ZM20 8H4V6H20V8Z" fill="currentColor"/>
            </svg>';
        } else {
            // Se for imagem (PNG, JPG, etc), retorna tag img
            return '<img src="' . esc_url($icon_url) . '" alt="Cartão de Crédito" class="dw-parcelas-credit-card-icon-image" style="width: 20px; height: 20px; vertical-align: middle; display: inline-block;" />';
        }
    }

    /**
     * Gera CSS a partir dos campos visuais
     * 
     * @param array $design_settings Configurações
     * @param string $location Localização: 'product', 'gallery', 'cart', 'checkout'
     */
    private function generate_visual_css($design_settings, $location = 'product') {
        if (!is_array($design_settings)) {
            return '';
        }
        
        $css_parts = array();
        
        // Determina qual campo usar baseado na localização
        $margin_key = ($location === 'gallery') ? 'installments_margin_gallery' : 'installments_margin_product';
        $padding_key = ($location === 'gallery') ? 'installments_padding_gallery' : 'installments_padding_product';
        
        // Margin - só gera se houver valores configurados e diferentes de 0 (permite negativos)
        if (isset($design_settings[$margin_key]) && is_array($design_settings[$margin_key])) {
            $margin = $design_settings[$margin_key];
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
        if (isset($design_settings[$padding_key]) && is_array($design_settings[$padding_key])) {
            $padding = $design_settings[$padding_key];
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
        if (isset($design_settings['installments_border_radius']) && is_array($design_settings['installments_border_radius'])) {
            $border_radius = $design_settings['installments_border_radius'];
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
     * Enfileira estilos CSS e scripts
     */
    public function enqueue_assets() {
        // Carrega CSS em páginas de produto
        if (is_product()) {
            wp_enqueue_style(
                'dw-parcelas-frontend',
                DW_PARCELAS_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                DW_PARCELAS_VERSION
            );
            
            wp_enqueue_script(
                'dw-parcelas-frontend',
                DW_PARCELAS_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery'),
                DW_PARCELAS_VERSION,
                true
            );
        }
    }
    
    /**
     * Injeta CSS personalizado para a tabela de parcelas
     * 
     * Aplica as cores configuradas no painel administrativo à tabela no frontend.
     * Usa alta especificidade para sobrescrever estilos de temas.
     */
    public function inject_table_custom_css() {
        // Obtém as configurações de design da tabela
        $table_settings = DW_Pix_Settings::get_table_design_settings();
        
        // Inicia o CSS personalizado com alta especificidade
        $css = '<style id="dw-parcelas-table-custom-css">';
        
        // Fundo da tabela - múltiplos seletores para máxima especificidade
        $css .= 'table.dw-parcelas-table, .dw-parcelas-table-wrapper table.dw-parcelas-table { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_background_color']) . ' !important; }';
        
        // Cabeçalho da tabela
        $css .= 'table.dw-parcelas-table thead, .dw-parcelas-table-wrapper table.dw-parcelas-table thead { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_header_background_color']) . ' !important; }';
        
        $css .= 'table.dw-parcelas-table thead th, .dw-parcelas-table-wrapper table.dw-parcelas-table thead th, ';
        $css .= 'table.dw-parcelas-table th, .dw-parcelas-table-wrapper table.dw-parcelas-table th { ';
        $css .= 'color: ' . esc_attr($table_settings['table_header_text_color']) . ' !important; ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_header_background_color']) . ' !important; ';
        $css .= 'border-bottom-color: ' . esc_attr($table_settings['table_border_color']) . ' !important; ';
        $css .= 'padding: ' . esc_attr($table_settings['table_cell_padding']) . ' !important; }';
        
        // Células da tabela
        $css .= 'table.dw-parcelas-table tbody td, .dw-parcelas-table-wrapper table.dw-parcelas-table tbody td, ';
        $css .= 'table.dw-parcelas-table td, .dw-parcelas-table-wrapper table.dw-parcelas-table td { ';
        $css .= 'color: ' . esc_attr($table_settings['table_cell_text_color']) . ' !important; ';
        $css .= 'border-bottom-color: ' . esc_attr($table_settings['table_border_color']) . ' !important; ';
        $css .= 'padding: ' . esc_attr($table_settings['table_cell_padding']) . ' !important; ';
        $css .= 'background-color: transparent !important; }';
        
        // Linhas da tabela (reset de fundo)
        $css .= 'table.dw-parcelas-table tbody tr, .dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_background_color']) . ' !important; }';
        
        // Linhas pares (zebrado)
        $css .= 'table.dw-parcelas-table tbody tr:nth-child(even), ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr:nth-child(even) { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_row_even_color']) . ' !important; }';
        
        $css .= 'table.dw-parcelas-table tbody tr:nth-child(even) td, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr:nth-child(even) td { ';
        $css .= 'background-color: transparent !important; }';
        
        // Hover - com múltiplas variações
        $css .= 'table.dw-parcelas-table tbody tr:hover, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr:hover, ';
        $css .= 'table.dw-parcelas-table tbody tr:hover td, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr:hover td { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_row_hover_color']) . ' !important; }';
        
        // Linha PIX - máxima especificidade
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr.dw-parcelas-pix-row, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row:nth-child(odd), ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row:nth-child(even) { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_pix_row_color']) . ' !important; ';
        $css .= 'color: ' . esc_attr($table_settings['table_pix_text_color']) . ' !important; }';
        
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row td, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr.dw-parcelas-pix-row td, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row td strong, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row td span { ';
        $css .= 'color: ' . esc_attr($table_settings['table_pix_text_color']) . ' !important; ';
        $css .= 'background-color: transparent !important; }';
        
        // Hover da linha PIX
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row:hover, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr.dw-parcelas-pix-row:hover, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-pix-row:hover td { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_row_hover_color']) . ' !important; }';
        
        // Linhas sem juros - máxima especificidade
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr.dw-parcelas-no-interest, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest:nth-child(odd), ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest:nth-child(even) { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_no_interest_row_color']) . ' !important; ';
        $css .= 'color: ' . esc_attr($table_settings['table_no_interest_text_color']) . ' !important; }';
        
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest td, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr.dw-parcelas-no-interest td, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest td strong, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest td span, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest .dw-parcelas-label { ';
        $css .= 'color: ' . esc_attr($table_settings['table_no_interest_text_color']) . ' !important; ';
        $css .= 'background-color: transparent !important; }';
        
        // Hover das linhas sem juros
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest:hover, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr.dw-parcelas-no-interest:hover, ';
        $css .= 'table.dw-parcelas-table tbody tr.dw-parcelas-no-interest:hover td { ';
        $css .= 'background-color: ' . esc_attr($table_settings['table_row_hover_color']) . ' !important; }';
        
        // Remove background de elementos filhos que podem herdar estilos
        $css .= 'table.dw-parcelas-table tbody tr td *, ';
        $css .= '.dw-parcelas-table-wrapper table.dw-parcelas-table tbody tr td * { ';
        $css .= 'background-color: transparent !important; }';
        
        $css .= '</style>';
        
        // Imprime o CSS no head
        echo $css;
    }
}

