<?php
/**
 * Plugin Name: Scontistica Dinamica
 * Description: Plugin per applicare sconti dinamici ai prodotti WooCommerce per categoria, brand, tag e custom taxonomies
 * Version: 1.6.5
 * Author: Michael Tamanti - Webemento
 * Text Domain: dynamic-discounts-webemento
 */

// Prevenire accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class DynamicDiscounts {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dynamic_discounts';
        
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'create_table'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Hook per admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_save_discount', array($this, 'save_discount'));
        add_action('wp_ajax_delete_discount', array($this, 'delete_discount'));
        add_action('wp_ajax_toggle_discount', array($this, 'toggle_discount'));
        
        // Hook per applicare sconti usando le API WooCommerce corrette
        add_filter('woocommerce_product_get_price', array($this, 'apply_discount'), 10, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'apply_discount'), 10, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'apply_discount'), 10, 2);
        add_filter('woocommerce_product_variation_get_sale_price', array($this, 'apply_discount'), 10, 2);
        
        // Hook per cart e checkout
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_cart_discounts'), 10, 1);
        
        // Hook per mostrare prezzo scontato
        add_filter('woocommerce_get_price_html', array($this, 'show_discount_price'), 10, 2);
        
        // Hook per aggiungere colonna sconto nella pagina prodotti admin
        add_filter('manage_product_posts_columns', array($this, 'add_discount_column'));
        add_action('manage_product_posts_custom_column', array($this, 'populate_discount_column'), 10, 2);
    }
    
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            discount_type varchar(50) NOT NULL,
            discount_value decimal(10,2) NOT NULL,
            target_type varchar(50) NOT NULL,
            target_value varchar(255) NOT NULL,
            priority int(11) DEFAULT 10,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verifica se la colonna priority esiste già
        $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '{$this->table_name}' 
            AND COLUMN_NAME = 'priority'");
            
        // Se la colonna non esiste, aggiungila
        if (empty($row)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD priority int(11) DEFAULT 10");
            
            // Assegna priorità agli sconti esistenti in base all'ID (più vecchi = priorità più alta)
            $wpdb->query("UPDATE {$this->table_name} SET priority = id");
        }
    }
    
    public function deactivate() {
        // Pulizia se necessaria
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Scontistica Dinamica',
            'Sconti Dinamici',
            'manage_options',
            'dynamic-discounts',
            array($this, 'admin_page'),
            'dashicons-tag',
            30
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_dynamic-discounts') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_style('dynamic-discounts-admin', plugin_dir_url(__FILE__) . 'admin-style.css');
        
        // Inline JavaScript per gestire l'interfaccia
        wp_add_inline_script('jquery', $this->get_admin_js());
    }
    
    private function get_admin_js() {
        return "
        jQuery(document).ready(function($) {
            // Aggiungi nuovo sconto
            $('#add-discount-btn').click(function() {
                $('#discount-form').slideToggle();
            });
            
            // Salva sconto
            $('#save-discount').click(function() {
                var data = {
                    action: 'save_discount',
                    name: $('#discount-name').val(),
                    discount_type: $('#discount-type').val(),
                    discount_value: $('#discount-value').val(),
                    priority: $('#discount-priority').val(),
                    target_type: $('#target-type').val(),
                    target_value: $('#target-value').val(),
                    _wpnonce: '" . wp_create_nonce('dynamic_discounts_nonce') . "'
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore nel salvare lo sconto');
                    }
                });
            });
            
            // Elimina sconto
            $('.delete-discount').click(function() {
                if (!confirm('Sei sicuro di voler eliminare questo sconto?')) return;
                
                var id = $(this).data('id');
                var data = {
                    action: 'delete_discount',
                    id: id,
                    _wpnonce: '" . wp_create_nonce('dynamic_discounts_nonce') . "'
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            });
            
            // Toggle attivo/inattivo
            $('.toggle-discount').change(function() {
                var id = $(this).data('id');
                var is_active = $(this).is(':checked') ? 1 : 0;
                
                var data = {
                    action: 'toggle_discount',
                    id: id,
                    is_active: is_active,
                    _wpnonce: '" . wp_create_nonce('dynamic_discounts_nonce') . "'
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (!response.success) {
                        alert('Errore nell\\'aggiornare lo stato');
                    }
                });
            });
            
            // Aggiorna opzioni target in base al tipo
            $('#target-type').change(function() {
                var type = $(this).val();
                var targetSelect = $('#target-value');
                
                targetSelect.empty().append('<option value=\"\">Caricamento...</option>');
                
                if (type === 'category') {
                    " . $this->get_categories_js() . "
                } else if (type === 'brand') {
                    " . $this->get_brands_js() . "
                } else if (type === 'tag') {
                    " . $this->get_tags_js() . "
                } else if (type === 'custom_taxonomy') {
                    " . $this->get_custom_taxonomies_js() . "
                }
            });
        });
        ";
    }
    
    private function get_tags_js() {
        $tags = get_terms(array(
            'taxonomy' => 'product_tag', 
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        $options = '<option value="">Seleziona tag</option>';
        
        // Debug info
        error_log('Getting product tags');
        
        if (is_wp_error($tags)) {
            error_log('Error getting tags: ' . $tags->get_error_message());
        } else {
            error_log('Found ' . count($tags) . ' tags');
            
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    $option_value = 'product_tag:' . $tag->term_id;
                    error_log('Adding tag option: ' . $tag->name . ' with value: ' . $option_value);
                    
                    $options .= '<option value="' . esc_attr($option_value) . '">' . 
                               esc_html($tag->name) . '</option>';
                }
            }
        }
        
        return "targetSelect.html('" . addslashes($options) . "');";
    }
    
    private function get_categories_js() {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat', 
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        $options = '<option value="">Seleziona categoria</option>';
        
        // Debug info
        error_log('Getting product categories');
        
        if (is_wp_error($categories)) {
            error_log('Error getting categories: ' . $categories->get_error_message());
        } else {
            error_log('Found ' . count($categories) . ' categories');
            
            if (!empty($categories)) {
                foreach ($categories as $cat) {
                    $option_value = 'product_cat:' . $cat->term_id;
                    error_log('Adding category option: ' . $cat->name . ' with value: ' . $option_value);
                    
                    $options .= '<option value="' . esc_attr($option_value) . '">' . 
                               esc_html($cat->name) . '</option>';
                }
            }
        }
        
        return "targetSelect.html('" . addslashes($options) . "');";
    }
    
    private function get_brands_js() {
        // Supporta diverse tassonomie per brand
        $brand_taxonomies = array('product_brand', 'pwb-brand', 'pa_brand');
        $options = '<option value="">Seleziona brand</option>';
        
        // Debug info
        error_log('Checking brand taxonomies: ' . implode(', ', $brand_taxonomies));
        
        foreach ($brand_taxonomies as $taxonomy) {
            error_log('Checking if taxonomy exists: ' . $taxonomy);
            if (taxonomy_exists($taxonomy)) {
                error_log('Taxonomy exists: ' . $taxonomy);
                
                $brands = get_terms(array(
                    'taxonomy' => $taxonomy, 
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC'
                ));
                
                if (is_wp_error($brands)) {
                    error_log('Error getting brands: ' . $brands->get_error_message());
                } else {
                    error_log('Found ' . count($brands) . ' brands for taxonomy ' . $taxonomy);
                    
                    if (!empty($brands)) {
                        foreach ($brands as $brand) {
                            $option_value = $taxonomy . ':' . $brand->term_id;
                            error_log('Adding brand option: ' . $brand->name . ' with value: ' . $option_value);
                            
                            $options .= '<option value="' . esc_attr($option_value) . '">' . 
                                       esc_html($brand->name) . ' (' . $taxonomy . ')</option>';
                        }
                    }
                }
                
                break; // Usa la prima tassonomia trovata
            }
        }
        
        return "targetSelect.html('" . addslashes($options) . "');";
    }
    
    private function get_custom_taxonomies_js() {
        $taxonomies = get_taxonomies(array('public' => true, '_builtin' => false), 'objects');
        $options = '<option value="">Seleziona custom taxonomy</option>';
        
        // Escludi le tassonomie già gestite (categorie, tag, brand)
        $excluded_taxonomies = array('product_cat', 'product_tag', 'product_brand', 'pwb-brand', 'pa_brand');
        
        foreach ($taxonomies as $taxonomy) {
            if (!in_array($taxonomy->name, $excluded_taxonomies)) {
                $terms = get_terms(array(
                    'taxonomy' => $taxonomy->name,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC'
                ));
                
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        $options .= '<option value="' . esc_attr($taxonomy->name . ':' . $term->term_id) . '">' . 
                                   esc_html($term->name) . ' (' . $taxonomy->label . ')</option>';
                    }
                }
            }
        }
        
        return "targetSelect.html('" . addslashes($options) . "');";
    }
    
    public function admin_page() {
        global $wpdb;
        
        // Ottieni tutti gli sconti
        $discounts = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
        
        ?>
        <div class="wrap">
            <h1>Gestione Sconti Dinamici</h1>
            
            <button id="add-discount-btn" class="button button-primary">Aggiungi Nuovo Sconto</button>
            
            <div id="discount-form" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;">
                <h3 id="form-title">Nuovo Sconto</h3>
                <input type="hidden" id="discount-id" value="0">
                <table class="form-table">
                    <tr>
                        <th><label for="discount-name">Nome Sconto</label></th>
                        <td><input type="text" id="discount-name" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="discount-type">Tipo Sconto</label></th>
                        <td>
                            <select id="discount-type">
                                <option value="percentage">Percentuale (%)</option>
                                <option value="fixed">Importo Fisso (€)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="discount-value">Valore Sconto</label></th>
                        <td><input type="number" id="discount-value" step="0.01" min="0" /></td>
                    </tr>
                    <tr>
                        <th><label for="discount-priority">Priorità</label></th>
                        <td>
                            <input type="number" id="discount-priority" value="10" min="1" step="1" />
                            <p class="description">Valore più basso = priorità più alta. In caso di più sconti applicabili, verrà usato quello con priorità più alta.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="target-type">Applica a</label></th>
                        <td>
                            <select id="target-type">
                                <option value="">Seleziona tipo</option>
                                <option value="category">Categoria Prodotto</option>
                                <option value="brand">Brand</option>
                                <option value="tag">Tag</option>
                                <option value="custom_taxonomy">Custom Taxonomy</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="target-value">Target</label></th>
                        <td>
                            <select id="target-value">
                                <option value="">Prima seleziona il tipo</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p>
                    <button id="save-discount" class="button button-primary">Salva Sconto</button>
                    <button onclick="jQuery('#discount-form').slideUp();" class="button">Annulla</button>
                </p>
            </div>
            
            <h2>Sconti Attivi</h2>
            
            <?php if (!empty($discounts)) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo Sconto</th>
                            <th>Valore</th>
                            <th>Priorità</th>
                            <th>Applica a</th>
                            <th>Target</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discounts as $discount) : ?>
                            <tr>
                                <td><?php echo esc_html($discount->name); ?></td>
                                <td><?php echo $discount->discount_type === 'percentage' ? 'Percentuale' : 'Importo Fisso'; ?></td>
                                <td>
                                    <?php 
                                    echo esc_html($discount->discount_value);
                                    echo $discount->discount_type === 'percentage' ? '%' : '€';
                                    ?>
                                </td>
                                <td><?php echo esc_html($discount->priority); ?></td>
                                <td><?php echo ucfirst($discount->target_type); ?></td>
                                <td><?php echo $this->get_target_name($discount->target_type, $discount->target_value); ?></td>
                                <td>
                                    <input type="checkbox" class="toggle-discount" 
                                           data-id="<?php echo $discount->id; ?>" 
                                           <?php checked($discount->is_active, 1); ?> />
                                    <?php echo $discount->is_active ? 'Attivo' : 'Inattivo'; ?>
                                </td>
                                <td>
                                    <button class="button delete-discount" data-id="<?php echo $discount->id; ?>">
                                        Elimina
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Nessuno sconto configurato.</p>
            <?php endif; ?>
        </div>
        
        <style>
        #discount-form {
            border-radius: 5px;
        }
        .toggle-discount {
            margin-right: 10px;
        }
        .delete-discount {
            color: #a00;
        }
        .delete-discount:hover {
            color: #dc3232;
        }
        </style>
        <?php
    }
    
    private function get_target_name($type, $value) {
        switch ($type) {
            case 'category':
                // Debug info
                error_log('Category value: ' . $value);
                
                if (strpos($value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $value, 2);
                    error_log('Category taxonomy: ' . $taxonomy . ', term_id: ' . $term_id);
                    
                    // Verifica se la tassonomia esiste
                    if (!taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy does not exist: ' . $taxonomy);
                        return 'Tassonomia categoria non trovata (' . $taxonomy . ')';
                    }
                    
                    $term = get_term(intval($term_id), $taxonomy);
                    if (!$term || is_wp_error($term)) {
                        error_log('Term not found or error: ' . (is_wp_error($term) ? $term->get_error_message() : 'Term not found'));
                        return 'Categoria non trovata (ID: ' . $term_id . ')';
                    }
                    
                    return esc_html($term->name);
                }
                
                // Fallback per compatibilità con il vecchio formato
                $term = get_term(intval($value), 'product_cat');
                return $term && !is_wp_error($term) ? esc_html($term->name) : 'Categoria non trovata (valore: ' . $value . ')';
            
            case 'brand':
                // Debug info
                error_log('Brand value: ' . $value);
                
                if (strpos($value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $value, 2);
                    error_log('Brand taxonomy: ' . $taxonomy . ', term_id: ' . $term_id);
                    
                    // Verifica se la tassonomia esiste
                    if (!taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy does not exist: ' . $taxonomy);
                        return 'Tassonomia brand non trovata (' . $taxonomy . ')';
                    }
                    
                    $term = get_term(intval($term_id), $taxonomy);
                    if (!$term || is_wp_error($term)) {
                        error_log('Term not found or error: ' . (is_wp_error($term) ? $term->get_error_message() : 'Term not found'));
                        return 'Brand non trovato (ID: ' . $term_id . ')';
                    }
                    
                    return esc_html($term->name) . ' (' . $taxonomy . ')';
                }
                
                // Fallback per compatibilità
                $brand_taxonomies = array('product_brand', 'pwb-brand', 'pa_brand');
                foreach ($brand_taxonomies as $taxonomy) {
                    error_log('Checking fallback taxonomy: ' . $taxonomy);
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term(intval($value), $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            return esc_html($term->name);
                        }
                    }
                }
                
                return 'Brand non trovato (valore: ' . $value . ')';
            
            case 'tag':
                // Debug info
                error_log('Tag value: ' . $value);
                
                if (strpos($value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $value, 2);
                    error_log('Tag taxonomy: ' . $taxonomy . ', term_id: ' . $term_id);
                    
                    // Verifica se la tassonomia esiste
                    if (!taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy does not exist: ' . $taxonomy);
                        return 'Tassonomia tag non trovata (' . $taxonomy . ')';
                    }
                    
                    $term = get_term(intval($term_id), $taxonomy);
                    if (!$term || is_wp_error($term)) {
                        error_log('Term not found or error: ' . (is_wp_error($term) ? $term->get_error_message() : 'Term not found'));
                        return 'Tag non trovato (ID: ' . $term_id . ')';
                    }
                    
                    return esc_html($term->name);
                }
                
                // Fallback per compatibilità con il vecchio formato
                $term = get_term(intval($value), 'product_tag');
                return $term && !is_wp_error($term) ? esc_html($term->name) : 'Tag non trovato (valore: ' . $value . ')';
            
            case 'custom_taxonomy':
                if (strpos($value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $value, 2);
                    $term = get_term(intval($term_id), $taxonomy);
                    $tax_obj = get_taxonomy($taxonomy);
                    $tax_label = $tax_obj ? $tax_obj->label : $taxonomy;
                    return $term && !is_wp_error($term) ? esc_html($term->name) . ' (' . $tax_label . ')' : 'Termine non trovato';
                }
                return 'Taxonomy non valida';
            
            case 'custom_post_type':
                $post_type = get_post_type_object($value);
                return $post_type ? esc_html($post_type->label) : 'Post Type non trovato';
            
            default:
                return esc_html($value);
        }
    }
    
    public function save_discount() {
        // Verifica nonce e capacità utente
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dynamic_discounts_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Accesso non autorizzato');
            return;
        }
        
        global $wpdb;
        
        // Sanitizza tutti i dati usando le funzioni WordPress
        $name = sanitize_text_field(wp_unslash($_POST['name']));
        $discount_type = sanitize_key($_POST['discount_type']);
        $discount_value = floatval($_POST['discount_value']);
        $target_type = sanitize_key($_POST['target_type']);
        $target_value = sanitize_text_field(wp_unslash($_POST['target_value']));
        $priority = isset($_POST['priority']) ? absint($_POST['priority']) : 10;
        
        // Debug info
        error_log('Saving discount - Target type: ' . $target_type . ', Target value: ' . $target_value);
        
        // Validazione dati
        if (empty($name) || empty($discount_type) || $discount_value <= 0 || empty($target_type) || empty($target_value)) {
            wp_send_json_error('Tutti i campi sono obbligatori e il valore deve essere maggiore di 0');
            return;
        }
        
        // Validazione tipo sconto
        if (!in_array($discount_type, array('percentage', 'fixed'))) {
            wp_send_json_error('Tipo di sconto non valido');
            return;
        }
        
        // Validazione percentuale
        if ($discount_type === 'percentage' && $discount_value > 100) {
            wp_send_json_error('La percentuale di sconto non può essere superiore al 100%');
            return;
        }
        
        // Gestione speciale per brand
        if ($target_type === 'brand') {
            error_log('Processing brand target value: ' . $target_value);
            
            // Se il valore non contiene già il formato taxonomy:term_id
            if (strpos($target_value, ':') === false) {
                // Cerca di determinare la tassonomia corretta
                $brand_taxonomies = array('product_brand', 'pwb-brand', 'pa_brand');
                foreach ($brand_taxonomies as $taxonomy) {
                    if (taxonomy_exists($taxonomy)) {
                        error_log('Found brand taxonomy: ' . $taxonomy);
                        // Verifica se il termine esiste
                        $term = get_term(intval($target_value), $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $target_value = $taxonomy . ':' . $target_value;
                            error_log('Updated brand target value: ' . $target_value);
                            break;
                        }
                    }
                }
            }
        }
        
        // Usa prepared statement per sicurezza
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name' => $name,
                'discount_type' => $discount_type,
                'discount_value' => $discount_value,
                'target_type' => $target_type,
                'target_value' => $target_value,
                'priority' => $priority,
                'is_active' => 1
            ),
            array('%s', '%s', '%f', '%s', '%s', '%d', '%d')
        );
        
        if ($result !== false) {
            error_log('Discount saved successfully with ID: ' . $wpdb->insert_id);
            wp_send_json_success(array('message' => 'Sconto salvato con successo'));
        } else {
            error_log('Error saving discount: ' . $wpdb->last_error);
            wp_send_json_error('Errore nel salvare lo sconto: ' . $wpdb->last_error);
        }
    }
    
    public function delete_discount() {
        // Verifica nonce e capacità utente
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dynamic_discounts_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Accesso non autorizzato');
            return;
        }
        
        global $wpdb;
        
        $id = absint($_POST['id']);
        
        if (!$id) {
            wp_send_json_error('ID non valido');
            return;
        }
        
        // Usa prepared statement
        $result = $wpdb->delete(
            $this->table_name, 
            array('id' => $id), 
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Sconto eliminato con successo'));
        } else {
            wp_send_json_error('Errore nell\'eliminare lo sconto');
        }
    }
    
    public function toggle_discount() {
        // Verifica nonce e capacità utente
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dynamic_discounts_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Accesso non autorizzato');
            return;
        }
        
        global $wpdb;
        
        $id = absint($_POST['id']);
        $is_active = absint($_POST['is_active']);
        
        if (!$id) {
            wp_send_json_error('ID non valido');
            return;
        }
        
        // Assicurati che is_active sia 0 o 1
        $is_active = ($is_active === 1) ? 1 : 0;
        
        // Usa prepared statement
        $result = $wpdb->update(
            $this->table_name,
            array('is_active' => $is_active),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Stato aggiornato con successo'));
        } else {
            wp_send_json_error('Errore nell\'aggiornare lo stato');
        }
    }
    
    public function apply_discount($price, $product) {
        // Evita loop infiniti e verifica che il prodotto sia valido
        if (!$price || !is_a($product, 'WC_Product') || doing_action('woocommerce_before_calculate_totals')) {
            return $price;
        }
        
        // Usa il prezzo regolare come base per il calcolo
        $regular_price = $product->get_regular_price();
        if (!$regular_price) {
            return $price;
        }
        
        global $wpdb;
        
        // Ottieni sconti attivi usando WP_Query o get_results con preparazione
        $discounts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE is_active = %d ORDER BY priority ASC",
            1
        ));
        
        if (empty($discounts)) {
            return $price;
        }
        
        $original_price = floatval($regular_price);
        $applied_discount = null;
        
        // Trova il primo sconto applicabile in base alla priorità
        foreach ($discounts as $discount) {
            if ($this->product_matches_discount($product, $discount)) {
                $applied_discount = $discount;
                break; // Usa il primo sconto applicabile (priorità più alta)
            }
        }
        
        // Calcola lo sconto se ne è stato trovato uno applicabile
        if ($applied_discount) {
            $discount_amount = 0;
            
            if ($applied_discount->discount_type === 'percentage') {
                $discount_amount = ($original_price * floatval($applied_discount->discount_value)) / 100;
            } else {
                $discount_amount = floatval($applied_discount->discount_value);
            }
            
            $final_price = $original_price - $discount_amount;
            return max(0, $final_price); // Non permettere prezzi negativi
        }
        
        // Rimuovi il controllo per $max_discount_amount che non è mai inizializzato
        
        return $price;
    }
    
    public function apply_cart_discounts($cart) {
        // Evita di applicare gli sconti più volte
        static $has_run = false;
        if ($has_run) {
            return;
        }
        $has_run = true;
        
        // Debug info
        error_log('Applying cart discounts');
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            if (!is_a($product, 'WC_Product')) {
                error_log('Not a valid product in cart');
                continue;
            }
            
            $product_id = $product->get_id();
            $regular_price = $product->get_regular_price();
            $current_price = $product->get_price();
            
            error_log("Cart product ID: {$product_id}, Regular price: {$regular_price}, Current price: {$current_price}");
            
            // Calcola lo sconto
            $discounted_price = $this->calculate_product_discount($product);
            error_log("Calculated discounted price: {$discounted_price}");
            
            if ($discounted_price > 0 && $discounted_price < floatval($regular_price)) {
                error_log("Setting new price for product {$product_id}: {$discounted_price}");
                
                // Imposta il prezzo scontato
                $product->set_price($discounted_price);
                
                // Aggiungi informazioni sullo sconto come metadati del carrello
                $discount_amount = floatval($regular_price) - $discounted_price;
                $discount_percentage = round(($discount_amount / floatval($regular_price)) * 100, 1);
                
                // Salva le informazioni sullo sconto nei metadati dell'elemento del carrello
                $cart_item['dynamic_discount'] = array(
                    'original_price' => $regular_price,
                    'discount_amount' => $discount_amount,
                    'discount_percentage' => $discount_percentage
                );
                
                // Aggiorna l'elemento del carrello con i metadati
                $cart->cart_contents[$cart_item_key] = $cart_item;
                
                error_log("Added discount metadata: {$discount_percentage}% off");
            } else {
                error_log("No discount applied for product {$product_id}");
            }
        }
        
        // Aggiungi hook per mostrare lo sconto nel carrello
        add_filter('woocommerce_cart_item_price', array($this, 'show_discounted_price_in_cart'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'show_discounted_subtotal_in_cart'), 10, 3);
    }
    
    /**
     * Mostra il prezzo scontato nel carrello con indicazione dello sconto
     */
    public function show_discounted_price_in_cart($price_html, $cart_item, $cart_item_key) {
        if (isset($cart_item['dynamic_discount'])) {
            $discount = $cart_item['dynamic_discount'];
            $original_price = $discount['original_price'];
            $discount_percentage = $discount['discount_percentage'];
            
            // Formatta i prezzi
            $original_price_formatted = wc_price($original_price);
            $current_price_formatted = $price_html;
            
            // Crea l'HTML con l'indicazione dello sconto
            $price_html = sprintf(
                '<del aria-hidden="true"><span class="woocommerce-Price-amount amount">%s</span></del> ' .
                '<ins><span class="woocommerce-Price-amount amount">%s</span></ins> ' .
                '<span class="discount-badge" style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-left: 5px;">-%s%%</span>',
                $original_price_formatted,
                $current_price_formatted,
                $discount_percentage
            );
        }
        
        return $price_html;
    }
    
    /**
     * Mostra il subtotale scontato nel carrello
     */
    public function show_discounted_subtotal_in_cart($subtotal, $cart_item, $cart_item_key) {
        if (isset($cart_item['dynamic_discount'])) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            
            $discount = $cart_item['dynamic_discount'];
            $original_price = $discount['original_price'];
            $discount_percentage = $discount['discount_percentage'];
            
            // Calcola il subtotale originale e quello scontato
            $original_subtotal = $original_price * $quantity;
            $discounted_subtotal = $product->get_price() * $quantity;
            
            // Formatta i subtotali
            $original_subtotal_formatted = wc_price($original_subtotal);
            $discounted_subtotal_formatted = wc_price($discounted_subtotal);
            
            // Crea l'HTML con l'indicazione dello sconto
            $subtotal = sprintf(
                '<del aria-hidden="true"><span class="woocommerce-Price-amount amount">%s</span></del> ' .
                '<ins><span class="woocommerce-Price-amount amount">%s</span></ins> ' .
                '<span class="discount-badge" style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-left: 5px;">-%s%%</span>',
                $original_subtotal_formatted,
                $discounted_subtotal_formatted,
                $discount_percentage
            );
        }
        
        return $subtotal;
    }
    
    private function calculate_product_discount($product) {
        if (!is_a($product, 'WC_Product')) {
            return 0;
        }
        
        $regular_price = floatval($product->get_regular_price());
        if (!$regular_price) {
            return 0;
        }
        
        global $wpdb;
        
        // Ottieni sconti attivi ordinati per priorità
        $discounts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE is_active = %d ORDER BY priority ASC",
            1
        ));
        
        if (empty($discounts)) {
            return $regular_price;
        }
        
        $applied_discount = null;
        
        // Trova il primo sconto applicabile in base alla priorità
        foreach ($discounts as $discount) {
            if ($this->product_matches_discount($product, $discount)) {
                $applied_discount = $discount;
                break; // Usa il primo sconto applicabile (priorità più alta)
            }
        }
        
        // Calcola lo sconto se ne è stato trovato uno applicabile
        if ($applied_discount) {
            $discount_amount = 0;
            
            if ($applied_discount->discount_type === 'percentage') {
                $discount_amount = ($regular_price * floatval($applied_discount->discount_value)) / 100;
            } else {
                $discount_amount = floatval($applied_discount->discount_value);
            }
            
            return max(0, $regular_price - $discount_amount); // Non permettere prezzi negativi
        }
        
        return $regular_price;
    }
    
    private function product_matches_discount($product, $discount) {
        if (!is_a($product, 'WC_Product')) {
            return false;
        }
        
        $product_id = $product->get_id();
        
        switch ($discount->target_type) {
            case 'category':
                // Debug info
                error_log('Product matches discount - Category value: ' . $discount->target_value);
                
                // Supporta il nuovo formato con taxonomy:term_id
                if (strpos($discount->target_value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $discount->target_value, 2);
                    error_log('Checking category match - Taxonomy: ' . $taxonomy . ', Term ID: ' . $term_id);
                    
                    // Verifica se la tassonomia esiste
                    if (!taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy does not exist: ' . $taxonomy);
                        return false;
                    }
                    
                    $product_categories = wc_get_product_cat_ids($product_id);
                    $has_category = in_array(intval($term_id), $product_categories);
                    error_log('Product ' . $product_id . ' has category ' . $term_id . ': ' . ($has_category ? 'Yes' : 'No'));
                    return $has_category;
                }
                
                // Fallback per compatibilità con il vecchio formato
                $product_categories = wc_get_product_cat_ids($product_id);
                $has_category = in_array(intval($discount->target_value), $product_categories);
                error_log('Product ' . $product_id . ' has category ' . $discount->target_value . ': ' . ($has_category ? 'Yes' : 'No'));
                return $has_category;
            
            case 'brand':
                // Debug info
                error_log('Product matches discount - Brand value: ' . $discount->target_value);
                
                // Supporta diverse tassonomie per brand
                if (strpos($discount->target_value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $discount->target_value, 2);
                    error_log('Checking brand match - Taxonomy: ' . $taxonomy . ', Term ID: ' . $term_id);
                    
                    // Verifica se la tassonomia esiste
                    if (!taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy does not exist: ' . $taxonomy);
                        return false;
                    }
                    
                    $has_term = has_term(intval($term_id), $taxonomy, $product_id);
                    error_log('Product ' . $product_id . ' has term ' . $term_id . ' in taxonomy ' . $taxonomy . ': ' . ($has_term ? 'Yes' : 'No'));
                    return $has_term;
                }
                
                // Fallback per compatibilità
                $brand_taxonomies = array('product_brand', 'pwb-brand', 'pa_brand');
                foreach ($brand_taxonomies as $taxonomy) {
                    error_log('Checking fallback taxonomy: ' . $taxonomy);
                    if (taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy exists: ' . $taxonomy);
                        $has_term = has_term($discount->target_value, $taxonomy, $product_id);
                        error_log('Product ' . $product_id . ' has term ' . $discount->target_value . ' in taxonomy ' . $taxonomy . ': ' . ($has_term ? 'Yes' : 'No'));
                        if ($has_term) {
                            return true;
                        }
                    }
                }
                
                return false;
            
            case 'tag':
                // Debug info
                error_log('Product matches discount - Tag value: ' . $discount->target_value);
                
                // Supporta il nuovo formato con taxonomy:term_id
                if (strpos($discount->target_value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $discount->target_value, 2);
                    error_log('Checking tag match - Taxonomy: ' . $taxonomy . ', Term ID: ' . $term_id);
                    
                    // Verifica se la tassonomia esiste
                    if (!taxonomy_exists($taxonomy)) {
                        error_log('Taxonomy does not exist: ' . $taxonomy);
                        return false;
                    }
                    
                    $has_term = has_term(intval($term_id), $taxonomy, $product_id);
                    error_log('Product ' . $product_id . ' has term ' . $term_id . ' in taxonomy ' . $taxonomy . ': ' . ($has_term ? 'Yes' : 'No'));
                    return $has_term;
                }
                
                // Fallback per compatibilità con il vecchio formato
                $has_term = has_term(intval($discount->target_value), 'product_tag', $product_id);
                error_log('Product ' . $product_id . ' has tag ' . $discount->target_value . ': ' . ($has_term ? 'Yes' : 'No'));
                return $has_term;
            
            case 'custom_taxonomy':
                // Supporta custom taxonomies
                if (strpos($discount->target_value, ':') !== false) {
                    list($taxonomy, $term_id) = explode(':', $discount->target_value, 2);
                    return has_term(intval($term_id), $taxonomy, $product_id);
                }
                return false;
            
            case 'custom_post_type':
                return get_post_type($product_id) === $discount->target_value;
            
            default:
                return false;
        }
    }
    
    /**
     * Aggiunge una colonna "Sconto Dinamico" alla tabella dei prodotti in admin
     */
    public function add_discount_column($columns) {
        $new_columns = array();
        
        // Inserisci la colonna sconto dopo la colonna prezzo
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'price') {
                $new_columns['dynamic_discount'] = 'Sconto Dinamico';
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Popola la colonna "Sconto Dinamico" con i dati degli sconti applicati
     */
    public function populate_discount_column($column, $product_id) {
        if ($column !== 'dynamic_discount') {
            return;
        }
        
        // Ottieni il prodotto
        $product = wc_get_product($product_id);
        if (!$product) {
            echo '-';
            return;
        }
        
        // Verifica se è un prodotto variabile
        $is_variable = $product->is_type('variable');
        
        // Ottieni lo sconto applicato al prodotto principale
        $applied_discount = $this->get_applied_discount($product);
        
        // Se è un prodotto variabile, verifica anche le variazioni
        if ($is_variable) {
            $variations = $product->get_available_variations();
            $variation_discounts = array();
            
            foreach ($variations as $variation_data) {
                $variation_id = $variation_data['variation_id'];
                $variation = wc_get_product($variation_id);
                
                if ($variation) {
                    $discount = $this->get_applied_discount($variation);
                    if ($discount) {
                        $variation_discounts[] = $discount;
                    }
                }
            }
            
            // Se tutte le variazioni hanno lo stesso sconto, usa quello
            if (!empty($variation_discounts) && count(array_unique($variation_discounts, SORT_REGULAR)) === 1) {
                $applied_discount = $variation_discounts[0];
            }
            // Se le variazioni hanno sconti diversi o alcune non hanno sconti
            else if (!empty($variation_discounts)) {
                echo '<span style="color: #e74c3c;">Sconti variabili</span>';
                return;
            }
        }
        
        // Mostra lo sconto se presente
        if ($applied_discount) {
            $discount_type = $applied_discount->discount_type === 'percentage' ? '%' : '€';
            $discount_value = $applied_discount->discount_value;
            $target_name = $this->get_target_name($applied_discount->target_type, $applied_discount->target_value);
            
            echo '<span style="color: #2ecc71; font-weight: bold;">';
            echo esc_html($discount_value) . $discount_type . ' - ' . esc_html($target_name);
            echo '</span>';
        } else {
            echo '-';
        }
    }
    
    /**
     * Ottiene lo sconto applicato a un prodotto
     */
    private function get_applied_discount($product) {
        if (!is_a($product, 'WC_Product')) {
            return null;
        }
        
        global $wpdb;
        
        // Ottieni sconti attivi ordinati per priorità
        $discounts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE is_active = %d ORDER BY priority ASC",
            1
        ));
        
        if (empty($discounts)) {
            return null;
        }
        
        // Trova il primo sconto applicabile in base alla priorità
        foreach ($discounts as $discount) {
            if ($this->product_matches_discount($product, $discount)) {
                return $discount;
            }
        }
        
        return null;
    }
    
    public function show_discount_price($price_html, $product) {
        if (!is_a($product, 'WC_Product')) {
            return $price_html;
        }
        
        // Usa le API WooCommerce per ottenere i prezzi
        $regular_price = $product->get_regular_price();
        $current_price = $product->get_price();
        
        if (!$regular_price || !$current_price) {
            return $price_html;
        }
        
        $regular_price_float = floatval($regular_price);
        $current_price_float = floatval($current_price);
        
        // Calcola se c'è uno sconto applicato
        $discounted_price = $this->calculate_product_discount($product);
        
        if ($discounted_price > 0 && $discounted_price < $regular_price_float) {
            // Calcola percentuale sconto con 1 decimale
            $discount_percentage = round((($regular_price_float - $discounted_price) / $regular_price_float) * 100, 1);
            
            // Usa le funzioni WooCommerce per formattare i prezzi
            $regular_price_formatted = wc_price($regular_price_float);
            $sale_price_formatted = wc_price($discounted_price);
            
            $price_html = sprintf(
                '<del aria-hidden="true"><span class="woocommerce-Price-amount amount">%s</span></del> ' .
                '<ins><span class="woocommerce-Price-amount amount">%s</span></ins> ' .
                '<span class="discount-badge" style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-left: 5px;">-%s%%</span>',
                $regular_price_formatted,
                $sale_price_formatted,
                $discount_percentage
            );
        }
        
        return $price_html;
    }
}

// Inizializza il plugin
new DynamicDiscounts();
