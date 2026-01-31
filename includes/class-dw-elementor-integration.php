<?php
/**
 * Integração com Elementor para grade de produtos
 *
 * @package DW_Parcelas_Pix_WooCommerce
 */

// Evita acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe DW_Elementor_Integration
 */
class DW_Elementor_Integration {

    /**
     * Cache de widgets processados
     */
    private static $processed_widgets = array();

    /**
     * ID do widget atual sendo processado
     */
    private static $current_widget_id = null;
    
    /**
     * Configurações do widget atual
     */
    private static $current_widget_settings = array();

    /**
     * Construtor
     */
    public function __construct() {
        add_action('elementor/widgets/register', array($this, 'register_widgets'));
        add_action('elementor/elements/categories_registered', array($this, 'add_widget_categories'));
        add_action('elementor/controls/register', array($this, 'register_controls'));
        
        // Adiciona controles aos widgets de produtos do Elementor
        // Usa after_section_end para adicionar NOVA seção após uma seção existente terminar
        add_action('elementor/element/after_section_end', array($this, 'add_pix_controls_to_products_grid'), 10, 3);
        
        // Hook antes de renderizar o widget para capturar configurações
        add_action('elementor/frontend/widget/before_render', array($this, 'before_render_widget'));
        
        // Filtros para modificar configurações na galeria
        add_filter('dw_pix_gallery_settings', array($this, 'apply_elementor_pix_settings'), 10, 2);
        add_filter('dw_installments_gallery_settings', array($this, 'apply_elementor_installments_settings'), 10, 2);
        
        // Adiciona CSS dinâmico baseado nas configurações do Elementor
        add_action('wp_head', array($this, 'add_elementor_css'), 999);
    }

    /**
     * Verifica se o Elementor está ativo
     */
    private function is_elementor_active() {
        return did_action('elementor/loaded');
    }

    /**
     * Registra widgets customizados
     */
    public function register_widgets($widgets_manager) {
        if (!$this->is_elementor_active()) {
            return;
        }

        // Pode adicionar widgets customizados aqui no futuro
    }

    /**
     * Adiciona categorias de widgets
     */
    public function add_widget_categories($elements_manager) {
        if (!$this->is_elementor_active()) {
            return;
        }

        $elements_manager->add_category(
            'dw-parcelas-pix',
            array(
                'title' => __('DW Parcelas e PIX', 'dw-parcelas-customizadas-woo'),
                'icon' => 'fa fa-shopping-cart',
            )
        );
    }

    /**
     * Registra controles customizados
     */
    public function register_controls($controls_manager) {
        if (!$this->is_elementor_active()) {
            return;
        }

        // Pode adicionar controles customizados aqui no futuro
    }

    /**
     * Adiciona controles de PIX aos widgets de produtos do Elementor
     * Hook: after_section_end - executa APÓS uma seção terminar
     */
    public function add_pix_controls_to_products_grid($element, $section_id, $args) {
        if (!$this->is_elementor_active()) {
            return;
        }

        // Obtém informações do widget
        $widget_name = $element->get_name();
        $widget_class = get_class($element);
        
        // Verifica se é widget de produtos PRIMEIRO
        $is_product_widget = $this->is_product_widget($widget_name, $widget_class);
        
        if (!$is_product_widget) {
            return;
        }

        // Define em qual seção devemos adicionar nossa nova seção (após ela terminar).
        // Inclui seções do Elementor Pro "Arquivo de Produtos" e outros widgets de produtos.
        $trigger_sections = array(
            'extra_style_section',     // Woodmart - última seção de estilo
            'section_style',           // Elementor padrão
            'section_content',         // Elementor Pro - aba Conteúdo
            'section_layout',          // Elementor - Layout
            'section_design',           // Elementor - Design
            'section_products',        // Elementor Pro WooCommerce
            'section_box_style',       // Estilo da caixa
            'section_archive',         // Arquivo
        );

        // Widgets de arquivo: aceita qualquer seção para garantir que a nossa apareça
        $is_archive_widget = in_array($widget_name, array('woocommerce-archive-products', 'archive-products', 'wc-archive-products'), true);

        if (!$is_archive_widget && !in_array($section_id, $trigger_sections)) {
            return;
        }

        // Obtém ID único do elemento para cache
        $element_id = $element->get_id();
        $cache_key = 'dw_pix_' . $element_id;
        
        // Verifica se já foi processado (evita processamento duplicado)
        if (isset(self::$processed_widgets[$cache_key])) {
            return;
        }

        // Verifica se a seção já foi adicionada (evita duplicação)
        $controls = $element->get_controls();
        if (isset($controls['dw_pix_price_section'])) {
            self::$processed_widgets[$cache_key] = true;
            return;
        }

        // Adiciona seção de PIX (agora é seguro porque estamos APÓS o end_controls_section)
        $this->add_pix_style_section($element);
        
        // Marca como processado
        self::$processed_widgets[$cache_key] = true;
    }


    /**
     * Verifica se é um widget de produtos
     */
    private function is_product_widget($widget_name, $widget_class = '') {
        // Lista específica de widgets de produtos (inclui Arquivo de Produtos do Elementor Pro)
        $product_widgets = array(
            'woocommerce-products',
            'woocommerce-archive-products',  // Elementor Pro - Arquivo de Produtos
            'wc-archive-products',
            'products',
            'archive-products',
            'xts_products',
            'woodmart_products',
            'wd_products',         // Woodmart principal
        );
        
        // Verifica se está na lista específica
        if (in_array($widget_name, $product_widgets)) {
            return true;
        }
        
        // Verifica se é widget do Woodmart pela classe (XTS\Elementor\Products)
        if ($widget_class && strpos($widget_class, 'XTS\Elementor\Products') === 0) {
            return true;
        }
        
        // Verifica padrões específicos de produtos (não categorias)
        if (stripos($widget_name, 'products') !== false && 
            stripos($widget_name, 'categories') === false &&
            stripos($widget_name, 'category') === false) {
            return true;
        }
        
        return false;
    }

    /**
     * Adiciona seção de estilos PIX ao widget
     */
    private function add_pix_style_section($element) {
        // ========================================
        // SEÇÃO 1: PARCELAS
        // ========================================
        $element->start_controls_section(
            'dw_installments_section',
            array(
                'label' => __('Parcelas (Cartão de Crédito)', 'dw-parcelas-customizadas-woo'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        // Controle para mostrar/ocultar parcelas
        $element->add_control(
            'dw_show_installments',
            array(
                'label' => __('Mostrar Parcelas', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'label_on' => __('Mostrar', 'dw-parcelas-customizadas-woo'),
                'label_off' => __('Ocultar', 'dw-parcelas-customizadas-woo'),
                'return_value' => 'yes',
                'default' => 'yes',
            )
        );

        // Separador: Texto Geral
        $element->add_control(
            'dw_installments_text_heading',
            array(
                'label' => __('Texto Geral ("até X de")', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Tipografia do texto geral
        $element->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'dw_installments_text_typography',
                'label' => __('Tipografia do Texto', 'dw-parcelas-customizadas-woo'),
                'selector' => '{{WRAPPER}} .dw-installments-info .dw-parcelas-text',
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Cor do texto geral
        $element->add_control(
            'dw_installments_text_color',
            array(
                'label' => __('Cor do Texto', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-installments-info .dw-parcelas-text' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .dw-installments-info' => 'color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Separador: Valor da Parcela
        $element->add_control(
            'dw_installments_value_heading',
            array(
                'label' => __('Valor da Parcela (Preço)', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Tipografia do valor
        $element->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'dw_installments_value_typography',
                'label' => __('Tipografia do Valor', 'dw-parcelas-customizadas-woo'),
                'selector' => '{{WRAPPER}} .dw-installments-info .dw-installment-value',
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Cor do valor
        $element->add_control(
            'dw_installments_value_color',
            array(
                'label' => __('Cor do Valor', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-installments-info .dw-installment-value' => 'color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Separador: Container
        $element->add_control(
            'dw_installments_container_heading',
            array(
                'label' => __('Container / Fundo / Espaçamento', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Cor de fundo
        $element->add_control(
            'dw_installments_background_color',
            array(
                'label' => __('Cor de Fundo', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-installments-info' => 'background-color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Margem externa
        $element->add_responsive_control(
            'dw_installments_margin',
            array(
                'label' => __('Margem Externa', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'selectors' => array(
                    '{{WRAPPER}} .dw-installments-info' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ),
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Margem interna (padding)
        $element->add_responsive_control(
            'dw_installments_padding',
            array(
                'label' => __('Margem Interna (Padding)', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'selectors' => array(
                    '{{WRAPPER}} .dw-installments-info' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ),
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        // Raio da borda (border-radius)
        $element->add_responsive_control(
            'dw_installments_border_radius',
            array(
                'label' => __('Raio da Borda (Arredondamento)', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'selectors' => array(
                    '{{WRAPPER}} .dw-installments-info' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ),
                'condition' => array(
                    'dw_show_installments' => 'yes',
                ),
            )
        );

        $element->end_controls_section();

        // ========================================
        // SEÇÃO 2: PREÇO PIX
        // ========================================
        $element->start_controls_section(
            'dw_pix_price_section',
            array(
                'label' => __('Preço com Desconto no PIX', 'dw-parcelas-customizadas-woo'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            )
        );

        // Controle para mostrar/ocultar desconto PIX
        $element->add_control(
            'dw_pix_show_discount',
            array(
                'label' => __('Mostrar Desconto PIX', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'label_on' => __('Mostrar', 'dw-parcelas-customizadas-woo'),
                'label_off' => __('Ocultar', 'dw-parcelas-customizadas-woo'),
                'return_value' => 'yes',
                'default' => 'yes',
            )
        );

        // Separador: Desconto
        $element->add_control(
            'dw_pix_discount_heading',
            array(
                'label' => __('Porcentagem de Desconto', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Tipografia do desconto
        $element->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'dw_pix_discount_typography',
                'label' => __('Tipografia do Desconto', 'dw-parcelas-customizadas-woo'),
                'selector' => '{{WRAPPER}} .dw-pix-price-info .dw-pix-discount-percent',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Cor do desconto
        $element->add_control(
            'dw_pix_discount_text_color',
            array(
                'label' => __('Cor do Desconto', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info .dw-pix-discount-percent' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery .dw-pix-discount-percent' => 'color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Separador: Texto Principal
        $element->add_control(
            'dw_pix_text_heading',
            array(
                'label' => __('Texto Principal ("Pagando com PIX")', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Tipografia do texto principal
        $element->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'dw_pix_main_text_typography',
                'label' => __('Tipografia do Texto', 'dw-parcelas-customizadas-woo'),
                'selector' => '{{WRAPPER}} .dw-pix-price-info .dw-pix-price-text, {{WRAPPER}} .dw-pix-price-info-gallery .dw-pix-price-text',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Cor do texto principal
        $element->add_control(
            'dw_pix_text_color',
            array(
                'label' => __('Cor do Texto', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info .dw-pix-price-text' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery .dw-pix-price-text' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery' => 'color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Separador: Preço PIX
        $element->add_control(
            'dw_pix_price_heading',
            array(
                'label' => __('Preço PIX (Valor)', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Tipografia do preço
        $element->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'dw_pix_price_typography',
                'label' => __('Tipografia do Preço', 'dw-parcelas-customizadas-woo'),
                'selector' => '{{WRAPPER}} .dw-pix-price-info .dw-pix-price-amount, {{WRAPPER}} .dw-pix-price-info-gallery .dw-pix-price-amount',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Cor do preço
        $element->add_control(
            'dw_pix_price_color',
            array(
                'label' => __('Cor do Preço', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info .dw-pix-price-amount' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery .dw-pix-price-amount' => 'color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Separador: Container
        $element->add_control(
            'dw_pix_container_heading',
            array(
                'label' => __('Container / Fundo / Bordas', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Cor de fundo
        $element->add_control(
            'dw_pix_background_color',
            array(
                'label' => __('Cor de Fundo', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'background-color: {{VALUE}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery' => 'background-color: {{VALUE}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Tipo de borda
        $element->add_control(
            'dw_pix_border_type',
            array(
                'label' => __('Tipo de Borda', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => array(
                    'default' => __('Padrão', 'dw-parcelas-customizadas-woo'),
                    'none' => __('Nenhuma', 'dw-parcelas-customizadas-woo'),
                    'solid' => __('Sólida', 'dw-parcelas-customizadas-woo'),
                    'dashed' => __('Tracejada', 'dw-parcelas-customizadas-woo'),
                    'dotted' => __('Pontilhada', 'dw-parcelas-customizadas-woo'),
                    'double' => __('Dupla', 'dw-parcelas-customizadas-woo'),
                ),
                'default' => 'default',
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'border-style: {{VALUE}};',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                    'dw_pix_border_type!' => 'none',
                ),
            )
        );

        // Cor da borda
        $element->add_control(
            'dw_pix_border_color',
            array(
                'label' => __('Cor da Borda', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'border-color: {{VALUE}};',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                    'dw_pix_border_type!' => array('none', 'default'),
                ),
            )
        );

        // Largura da borda
        $element->add_responsive_control(
            'dw_pix_border_width',
            array(
                'label' => __('Largura da Borda', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                    'dw_pix_border_type!' => array('none', 'default'),
                ),
            )
        );

        // Raio da borda (border-radius)
        $element->add_responsive_control(
            'dw_pix_border_radius',
            array(
                'label' => __('Raio da Borda (Arredondamento)', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'default' => array(
                    'unit' => 'px',
                ),
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Margem externa
        $element->add_responsive_control(
            'dw_pix_margin',
            array(
                'label' => __('Margem Externa', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Margem interna (padding)
        $element->add_responsive_control(
            'dw_pix_padding',
            array(
                'label' => __('Margem Interna (Padding)', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => array('px', 'em', '%'),
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                    '{{WRAPPER}} .dw-pix-price-info-gallery' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Cor do preço PIX
        $element->add_control(
            'dw_pix_price_color',
            array(
                'label' => __('Cor do Preço PIX', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info .dw-pix-price-amount' => 'color: {{VALUE}};',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        // Cor do texto principal
        $element->add_control(
            'dw_pix_text_color',
            array(
                'label' => __('Cor do Texto Principal', 'dw-parcelas-customizadas-woo'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .dw-pix-price-info .dw-pix-price-text' => 'color: {{VALUE}};',
                ),
                'condition' => array(
                    'dw_pix_show_discount' => 'yes',
                ),
            )
        );

        $element->end_controls_section();
    }


    /**
     * Captura configurações do widget antes de renderizar
     */
    public function before_render_widget($widget) {
        if (!$this->is_elementor_active()) {
            return;
        }

        $widget_name = $widget->get_name();
        $widget_class = get_class($widget);
        
        // Verifica se é um widget de produtos
        if ($this->is_product_widget($widget_name, $widget_class)) {
            $settings = $widget->get_settings_for_display();
            $element_id = $widget->get_id();
            
            // Armazena as configurações para uso posterior
            self::$current_widget_id = $element_id;
            self::$current_widget_settings = $settings;
        }
    }

    /**
     * Obtém configurações do widget de produtos do documento atual (fallback para arquivo/loop).
     * Usado quando o loop de produtos é renderizado sem passar por before_render_widget do widget.
     *
     * @return array|null Array com 'id' e 'settings' ou null
     */
    private function get_archive_products_widget_settings() {
        if (!$this->is_elementor_active() || !function_exists('is_shop')) {
            return null;
        }
        if (!is_shop() && !is_product_category() && !is_product_tag() && !is_post_type_archive('product')) {
            return null;
        }
        $document = \Elementor\Plugin::$instance->documents->get_current();
        if (!$document) {
            return null;
        }
        $elements_data = $document->get_elements_data();
        if (empty($elements_data)) {
            return null;
        }
        return $this->find_first_product_widget_in_elements($elements_data);
    }

    /**
     * Encontra o primeiro widget de produtos nos dados de elementos (recursivo).
     *
     * @param array $elements
     * @return array|null Array com 'id' e 'settings' ou null
     */
    private function find_first_product_widget_in_elements($elements) {
        foreach ($elements as $element) {
            $widget_type = isset($element['widgetType']) ? $element['widgetType'] : '';
            if ($widget_type && $this->is_product_widget($widget_type, '')) {
                $element_id = isset($element['id']) ? $element['id'] : '';
                $widget_settings = isset($element['settings']) ? $element['settings'] : array();
                return array('id' => $element_id, 'settings' => $widget_settings);
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found = $this->find_first_product_widget_in_elements($element['elements']);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Aplica configurações do Elementor ao preço PIX
     */
    public function apply_elementor_pix_settings($settings, $product) {
        $elementor_settings = self::$current_widget_settings;
        $element_id = self::$current_widget_id;

        // Fallback: em páginas de arquivo (loja, categoria), tenta obter do documento
        if (empty($elementor_settings)) {
            $archive_widget = $this->get_archive_products_widget_settings();
            if ($archive_widget !== null && !empty($archive_widget['settings'])) {
                $elementor_settings = $archive_widget['settings'];
                $element_id = $archive_widget['id'];
            }
        }

        if (empty($elementor_settings)) {
            return $settings;
        }
        
        // Marca que está usando Elementor (para remover estilos inline)
        $settings['using_elementor'] = true;
        
        // Adiciona classe com o ID do elemento para vincular CSS
        if ($element_id) {
            $settings['dw_pix_elementor_class'] = 'elementor-widget-' . $element_id;
        }
        
        // Verifica se deve mostrar o desconto
        if (isset($elementor_settings['dw_pix_show_discount'])) {
            $settings['dw_pix_show_discount'] = $elementor_settings['dw_pix_show_discount'] === 'yes' ? 'yes' : 'no';
        }

        // Mantém os estilos de "geral" como base; o CSS dinâmico do Elementor sobrescreve
        // apenas as propriedades que o usuário alterar no design do widget (com !important).
        // Não limpa background_color, text_color, etc. — assim .dw-pix-price-info sempre
        // recebe o design geral e o Elementor só sobrescreve o que for configurado no widget.
        
        return $settings;
    }

    /**
     * Aplica configurações do Elementor às parcelas
     */
    public function apply_elementor_installments_settings($settings, $product) {
        $elementor_settings = self::$current_widget_settings;
        $element_id = self::$current_widget_id;

        // Fallback: em páginas de arquivo (loja, categoria), tenta obter do documento
        if (empty($elementor_settings)) {
            $archive_widget = $this->get_archive_products_widget_settings();
            if ($archive_widget !== null && !empty($archive_widget['settings'])) {
                $elementor_settings = $archive_widget['settings'];
                $element_id = $archive_widget['id'];
            }
        }

        if (empty($elementor_settings)) {
            return $settings;
        }
        
        // Marca que está usando Elementor (para remover estilos inline)
        $settings['using_elementor'] = true;
        
        // Adiciona classe com o ID do elemento para vincular CSS
        if ($element_id) {
            $settings['dw_installments_elementor_class'] = 'elementor-widget-' . $element_id;
        }
        
        // Verifica se deve mostrar as parcelas
        if (isset($elementor_settings['dw_show_installments'])) {
            $settings['dw_show_installments'] = $elementor_settings['dw_show_installments'] === 'yes' ? 'yes' : 'no';
        }

        // Mantém os estilos de "geral" como base; o CSS dinâmico do Elementor sobrescreve
        // apenas as propriedades alteradas no design do widget (com !important).
        
        return $settings;
    }

    /**
     * Gera CSS a partir dos elementos do Elementor
     */
    private function generate_css_from_elements($elements, $css = '') {
        foreach ($elements as $element) {
            // Verifica se é um widget de produtos
            if (isset($element['widgetType']) && $this->is_product_widget($element['widgetType'], '')) {
                $settings = isset($element['settings']) ? $element['settings'] : array();
                $element_id = isset($element['id']) ? $element['id'] : '';
                
                // Se tem configurações, gera CSS para PIX e Parcelas
                if (!empty($settings) && $element_id) {
                    $css .= $this->generate_pix_css($element_id, $settings);
                    $css .= $this->generate_installments_css($element_id, $settings);
                }
            }
            
            // Processa elementos filhos recursivamente
            if (isset($element['elements']) && is_array($element['elements'])) {
                $css = $this->generate_css_from_elements($element['elements'], $css);
            }
        }
        
        return $css;
    }

    /**
     * Gera CSS específico para o preço PIX baseado nas configurações do Elementor
     */
    private function generate_pix_css($element_id, $settings) {
        $css = '';
        // Seletores corretos para pegar o elemento
        $selector_container = '.elementor-element-' . $element_id . ' .dw-pix-price-info';
        $selector_discount = '.elementor-element-' . $element_id . ' .dw-pix-price-info .dw-pix-discount-percent';
        $selector_text = '.elementor-element-' . $element_id . ' .dw-pix-price-info .dw-pix-price-text';
        $selector_price = '.elementor-element-' . $element_id . ' .dw-pix-price-info .dw-pix-price-amount';
        
        // Verifica se deve mostrar o desconto
        if (isset($settings['dw_pix_show_discount']) && $settings['dw_pix_show_discount'] !== 'yes') {
            $css .= $selector_container . ' { display: none !important; }';
            return $css;
        }
        
        // Tipografia do DESCONTO
        if (!empty($settings['dw_pix_discount_typography_font_family'])) {
            $css .= $selector_discount . ' { font-family: ' . esc_attr($settings['dw_pix_discount_typography_font_family']) . ' !important; }';
        }
        if (!empty($settings['dw_pix_discount_typography_font_size']['size'])) {
            $unit = isset($settings['dw_pix_discount_typography_font_size']['unit']) ? $settings['dw_pix_discount_typography_font_size']['unit'] : 'px';
            $css .= $selector_discount . ' { font-size: ' . esc_attr($settings['dw_pix_discount_typography_font_size']['size']) . $unit . ' !important; }';
        }
        if (!empty($settings['dw_pix_discount_typography_font_weight'])) {
            $css .= $selector_discount . ' { font-weight: ' . esc_attr($settings['dw_pix_discount_typography_font_weight']) . ' !important; }';
        }
        
        // Cor do DESCONTO
        if (!empty($settings['dw_pix_discount_text_color'])) {
            $css .= $selector_discount . ' { color: ' . esc_attr($settings['dw_pix_discount_text_color']) . ' !important; }';
        }
        
        // Tipografia do TEXTO PRINCIPAL
        if (!empty($settings['dw_pix_main_text_typography_font_family'])) {
            $css .= $selector_text . ' { font-family: ' . esc_attr($settings['dw_pix_main_text_typography_font_family']) . ' !important; }';
        }
        if (!empty($settings['dw_pix_main_text_typography_font_size']['size'])) {
            $unit = isset($settings['dw_pix_main_text_typography_font_size']['unit']) ? $settings['dw_pix_main_text_typography_font_size']['unit'] : 'px';
            $css .= $selector_text . ' { font-size: ' . esc_attr($settings['dw_pix_main_text_typography_font_size']['size']) . $unit . ' !important; }';
        }
        if (!empty($settings['dw_pix_main_text_typography_font_weight'])) {
            $css .= $selector_text . ' { font-weight: ' . esc_attr($settings['dw_pix_main_text_typography_font_weight']) . ' !important; }';
        }
        
        // Cor do TEXTO PRINCIPAL
        if (!empty($settings['dw_pix_text_color'])) {
            $css .= $selector_text . ' { color: ' . esc_attr($settings['dw_pix_text_color']) . ' !important; }';
        }
        
        // Tipografia do PREÇO PIX (seletores com alta especificidade)
        if (!empty($settings['dw_pix_price_typography_font_family'])) {
            $css .= $selector_price . ' { font-family: ' . esc_attr($settings['dw_pix_price_typography_font_family']) . ' !important; }';
            $css .= $selector_price . ' * { font-family: ' . esc_attr($settings['dw_pix_price_typography_font_family']) . ' !important; }';
            $css .= $selector_price . ' .amount { font-family: ' . esc_attr($settings['dw_pix_price_typography_font_family']) . ' !important; }';
        }
        if (!empty($settings['dw_pix_price_typography_font_size']['size'])) {
            $unit = isset($settings['dw_pix_price_typography_font_size']['unit']) ? $settings['dw_pix_price_typography_font_size']['unit'] : 'px';
            $size = esc_attr($settings['dw_pix_price_typography_font_size']['size']) . $unit;
            $css .= $selector_price . ' { font-size: ' . $size . ' !important; }';
            $css .= $selector_price . ' * { font-size: ' . $size . ' !important; }';
            $css .= $selector_price . ' .amount { font-size: ' . $size . ' !important; }';
            $css .= $selector_price . ' span.amount { font-size: ' . $size . ' !important; }';
        }
        if (!empty($settings['dw_pix_price_typography_font_weight'])) {
            $weight = esc_attr($settings['dw_pix_price_typography_font_weight']);
            $css .= $selector_price . ' { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_price . ' * { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_price . ' .amount { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_price . ' span.amount { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_price . ' .woocommerce-Price-amount.amount { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_price . ' bdi { font-weight: ' . $weight . ' !important; }';
        }
        
        // Cor do PREÇO PIX (máxima especificidade para sobrescrever .amount)
        if (!empty($settings['dw_pix_price_color'])) {
            $color = esc_attr($settings['dw_pix_price_color']);
            $css .= $selector_price . ' { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' * { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' .amount { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' span.amount { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' .woocommerce-Price-amount { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' .woocommerce-Price-amount.amount { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' span { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' bdi { color: ' . $color . ' !important; }';
            $css .= $selector_price . ' .woocommerce-Price-currencySymbol { color: ' . $color . ' !important; }';
            // Seletor super específico como último recurso
            $css .= '.elementor-element-' . $element_id . ' .dw-pix-price-info .dw-pix-price-amount span.woocommerce-Price-amount.amount bdi { color: ' . $color . ' !important; }';
        }
        
        // Cor de fundo do CONTAINER
        if (!empty($settings['dw_pix_background_color'])) {
            $css .= $selector_container . ' { background-color: ' . esc_attr($settings['dw_pix_background_color']) . ' !important; }';
        }
        
        // Tipo de borda
        if (!empty($settings['dw_pix_border_type']) && $settings['dw_pix_border_type'] !== 'default') {
            $css .= $selector_container . ' { border-style: ' . esc_attr($settings['dw_pix_border_type']) . ' !important; }';
        }
        
        // Cor da borda
        if (!empty($settings['dw_pix_border_color'])) {
            $css .= $selector_container . ' { border-color: ' . esc_attr($settings['dw_pix_border_color']) . ' !important; }';
        }
        
        // Largura da borda
        if (!empty($settings['dw_pix_border_width'])) {
            if (isset($settings['dw_pix_border_width']['top'])) {
                $unit = isset($settings['dw_pix_border_width']['unit']) ? $settings['dw_pix_border_width']['unit'] : 'px';
                $top = isset($settings['dw_pix_border_width']['top']) ? $settings['dw_pix_border_width']['top'] . $unit : '0';
                $right = isset($settings['dw_pix_border_width']['right']) ? $settings['dw_pix_border_width']['right'] . $unit : '0';
                $bottom = isset($settings['dw_pix_border_width']['bottom']) ? $settings['dw_pix_border_width']['bottom'] . $unit : '0';
                $left = isset($settings['dw_pix_border_width']['left']) ? $settings['dw_pix_border_width']['left'] . $unit : '0';
                $css .= $selector_container . ' { border-width: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        // Raio da borda
        if (!empty($settings['dw_pix_border_radius'])) {
            if (isset($settings['dw_pix_border_radius']['top'])) {
                $unit = isset($settings['dw_pix_border_radius']['unit']) ? $settings['dw_pix_border_radius']['unit'] : 'px';
                $top = isset($settings['dw_pix_border_radius']['top']) ? $settings['dw_pix_border_radius']['top'] . $unit : '0';
                $right = isset($settings['dw_pix_border_radius']['right']) ? $settings['dw_pix_border_radius']['right'] . $unit : '0';
                $bottom = isset($settings['dw_pix_border_radius']['bottom']) ? $settings['dw_pix_border_radius']['bottom'] . $unit : '0';
                $left = isset($settings['dw_pix_border_radius']['left']) ? $settings['dw_pix_border_radius']['left'] . $unit : '0';
                $css .= $selector_container . ' { border-radius: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        // Margem
        if (!empty($settings['dw_pix_margin'])) {
            if (isset($settings['dw_pix_margin']['top'])) {
                $unit = isset($settings['dw_pix_margin']['unit']) ? $settings['dw_pix_margin']['unit'] : 'px';
                $top = isset($settings['dw_pix_margin']['top']) ? $settings['dw_pix_margin']['top'] . $unit : '0';
                $right = isset($settings['dw_pix_margin']['right']) ? $settings['dw_pix_margin']['right'] . $unit : '0';
                $bottom = isset($settings['dw_pix_margin']['bottom']) ? $settings['dw_pix_margin']['bottom'] . $unit : '0';
                $left = isset($settings['dw_pix_margin']['left']) ? $settings['dw_pix_margin']['left'] . $unit : '0';
                $css .= $selector_container . ' { margin: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        // Padding
        if (!empty($settings['dw_pix_padding'])) {
            if (isset($settings['dw_pix_padding']['top'])) {
                $unit = isset($settings['dw_pix_padding']['unit']) ? $settings['dw_pix_padding']['unit'] : 'px';
                $top = isset($settings['dw_pix_padding']['top']) ? $settings['dw_pix_padding']['top'] . $unit : '0';
                $right = isset($settings['dw_pix_padding']['right']) ? $settings['dw_pix_padding']['right'] . $unit : '0';
                $bottom = isset($settings['dw_pix_padding']['bottom']) ? $settings['dw_pix_padding']['bottom'] . $unit : '0';
                $left = isset($settings['dw_pix_padding']['left']) ? $settings['dw_pix_padding']['left'] . $unit : '0';
                $css .= $selector_container . ' { padding: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        return $css;
    }

    /**
     * Gera CSS específico para as parcelas baseado nas configurações do Elementor
     */
    private function generate_installments_css($element_id, $settings) {
        $css = '';
        // Seletores corretos para pegar o elemento
        $selector_container = '.elementor-element-' . $element_id . ' .dw-installments-info';
        $selector_text = '.elementor-element-' . $element_id . ' .dw-installments-info .dw-parcelas-text';
        $selector_value = '.elementor-element-' . $element_id . ' .dw-installments-info .dw-installment-value';
        
        // Verifica se deve mostrar as parcelas
        if (isset($settings['dw_show_installments']) && $settings['dw_show_installments'] !== 'yes') {
            $css .= $selector_container . ' { display: none !important; }';
            return $css;
        }
        
        // Tipografia do TEXTO GERAL ("até X de")
        if (!empty($settings['dw_installments_text_typography_font_family'])) {
            $css .= $selector_text . ' { font-family: ' . esc_attr($settings['dw_installments_text_typography_font_family']) . ' !important; }';
        }
        if (!empty($settings['dw_installments_text_typography_font_size']['size'])) {
            $unit = isset($settings['dw_installments_text_typography_font_size']['unit']) ? $settings['dw_installments_text_typography_font_size']['unit'] : 'px';
            $css .= $selector_text . ' { font-size: ' . esc_attr($settings['dw_installments_text_typography_font_size']['size']) . $unit . ' !important; }';
        }
        if (!empty($settings['dw_installments_text_typography_font_weight'])) {
            $css .= $selector_text . ' { font-weight: ' . esc_attr($settings['dw_installments_text_typography_font_weight']) . ' !important; }';
        }
        
        // Cor do TEXTO GERAL
        if (!empty($settings['dw_installments_text_color'])) {
            $css .= $selector_text . ' { color: ' . esc_attr($settings['dw_installments_text_color']) . ' !important; }';
        }
        
        // Tipografia do VALOR DA PARCELA (seletores com alta especificidade)
        if (!empty($settings['dw_installments_value_typography_font_family'])) {
            $css .= $selector_value . ' { font-family: ' . esc_attr($settings['dw_installments_value_typography_font_family']) . ' !important; }';
            $css .= $selector_value . ' * { font-family: ' . esc_attr($settings['dw_installments_value_typography_font_family']) . ' !important; }';
            $css .= $selector_value . ' .amount { font-family: ' . esc_attr($settings['dw_installments_value_typography_font_family']) . ' !important; }';
        }
        if (!empty($settings['dw_installments_value_typography_font_size']['size'])) {
            $unit = isset($settings['dw_installments_value_typography_font_size']['unit']) ? $settings['dw_installments_value_typography_font_size']['unit'] : 'px';
            $size = esc_attr($settings['dw_installments_value_typography_font_size']['size']) . $unit;
            $css .= $selector_value . ' { font-size: ' . $size . ' !important; }';
            $css .= $selector_value . ' * { font-size: ' . $size . ' !important; }';
            $css .= $selector_value . ' .amount { font-size: ' . $size . ' !important; }';
            $css .= $selector_value . ' span.amount { font-size: ' . $size . ' !important; }';
        }
        if (!empty($settings['dw_installments_value_typography_font_weight'])) {
            $weight = esc_attr($settings['dw_installments_value_typography_font_weight']);
            $css .= $selector_value . ' { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_value . ' * { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_value . ' .amount { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_value . ' span.amount { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_value . ' .woocommerce-Price-amount.amount { font-weight: ' . $weight . ' !important; }';
            $css .= $selector_value . ' bdi { font-weight: ' . $weight . ' !important; }';
        }
        
        // Cor do VALOR (máxima especificidade para sobrescrever .amount)
        if (!empty($settings['dw_installments_value_color'])) {
            $color = esc_attr($settings['dw_installments_value_color']);
            $css .= $selector_value . ' { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' * { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' .amount { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' span.amount { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' .woocommerce-Price-amount { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' .woocommerce-Price-amount.amount { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' span { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' bdi { color: ' . $color . ' !important; }';
            $css .= $selector_value . ' .woocommerce-Price-currencySymbol { color: ' . $color . ' !important; }';
            // Seletor super específico como último recurso
            $css .= '.elementor-element-' . $element_id . ' .dw-installments-info .dw-installment-value span.woocommerce-Price-amount.amount bdi { color: ' . $color . ' !important; }';
        }
        
        // Cor de fundo do CONTAINER
        if (!empty($settings['dw_installments_background_color'])) {
            $css .= $selector_container . ' { background-color: ' . esc_attr($settings['dw_installments_background_color']) . ' !important; }';
        }
        
        // Margem
        if (!empty($settings['dw_installments_margin'])) {
            if (isset($settings['dw_installments_margin']['top'])) {
                $unit = isset($settings['dw_installments_margin']['unit']) ? $settings['dw_installments_margin']['unit'] : 'px';
                $top = isset($settings['dw_installments_margin']['top']) ? $settings['dw_installments_margin']['top'] . $unit : '0';
                $right = isset($settings['dw_installments_margin']['right']) ? $settings['dw_installments_margin']['right'] . $unit : '0';
                $bottom = isset($settings['dw_installments_margin']['bottom']) ? $settings['dw_installments_margin']['bottom'] . $unit : '0';
                $left = isset($settings['dw_installments_margin']['left']) ? $settings['dw_installments_margin']['left'] . $unit : '0';
                $css .= $selector_container . ' { margin: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        // Padding
        if (!empty($settings['dw_installments_padding'])) {
            if (isset($settings['dw_installments_padding']['top'])) {
                $unit = isset($settings['dw_installments_padding']['unit']) ? $settings['dw_installments_padding']['unit'] : 'px';
                $top = isset($settings['dw_installments_padding']['top']) ? $settings['dw_installments_padding']['top'] . $unit : '0';
                $right = isset($settings['dw_installments_padding']['right']) ? $settings['dw_installments_padding']['right'] . $unit : '0';
                $bottom = isset($settings['dw_installments_padding']['bottom']) ? $settings['dw_installments_padding']['bottom'] . $unit : '0';
                $left = isset($settings['dw_installments_padding']['left']) ? $settings['dw_installments_padding']['left'] . $unit : '0';
                $css .= $selector_container . ' { padding: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        // Border Radius
        if (!empty($settings['dw_installments_border_radius'])) {
            if (isset($settings['dw_installments_border_radius']['top'])) {
                $unit = isset($settings['dw_installments_border_radius']['unit']) ? $settings['dw_installments_border_radius']['unit'] : 'px';
                $top = isset($settings['dw_installments_border_radius']['top']) ? $settings['dw_installments_border_radius']['top'] . $unit : '0';
                $right = isset($settings['dw_installments_border_radius']['right']) ? $settings['dw_installments_border_radius']['right'] . $unit : '0';
                $bottom = isset($settings['dw_installments_border_radius']['bottom']) ? $settings['dw_installments_border_radius']['bottom'] . $unit : '0';
                $left = isset($settings['dw_installments_border_radius']['left']) ? $settings['dw_installments_border_radius']['left'] . $unit : '0';
                $css .= $selector_container . ' { border-radius: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ' !important; }';
            }
        }
        
        return $css;
    }

    /**
     * Adiciona CSS do Elementor no head
     */
    public function add_elementor_css() {
        if (!$this->is_elementor_active() || is_admin()) {
            return;
        }

        // Obtém o documento Elementor atual
        $document = \Elementor\Plugin::$instance->documents->get_current();
        
        if (!$document) {
            return;
        }

        // Obtém todos os elementos da página
        $elements_data = $document->get_elements_data();
        
        if (empty($elements_data)) {
            return;
        }

        // Processa elementos e gera CSS
        $css = $this->generate_css_from_elements($elements_data);
        
        // Adiciona regras globais para sobrescrever .amount do WooCommerce
        $css .= $this->generate_global_override_css();
        
        if (!empty($css)) {
            echo '<style id="dw-pix-elementor-dynamic">' . $css . '</style>';
        }
    }

    /**
     * Gera CSS global para sobrescrever .amount do WooCommerce
     */
    private function generate_global_override_css() {
        $css = '
        /* Força aplicação de estilos do Elementor sobre .amount do WooCommerce */
        [class*="elementor-element-"] .dw-installment-value .amount,
        [class*="elementor-element-"] .dw-installment-value span.amount,
        [class*="elementor-element-"] .dw-pix-price-amount .amount,
        [class*="elementor-element-"] .dw-pix-price-amount span.amount {
            all: inherit !important;
        }
        ';
        
        return $css;
    }

    /**
     * Adiciona ID do elemento Elementor às classes do produto
     */
    public function add_elementor_id_to_product($classes, $product) {
        // Este método será usado no futuro se necessário
        return $classes;
    }
}

// Inicializa a integração quando o Elementor estiver carregado
add_action('elementor/loaded', function() {
    new DW_Elementor_Integration();
}, 20);

