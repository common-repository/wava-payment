<?php
// Make sure we don't expose any info if called directly
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}
/**
 * Plugin Name: Wava Payment
 * 
 * Plugin URI: https://wava.co
 * Description: Entrega una experiencia simple de pago con billeteras digitales para que tus usuarios paguen en el menor tiempo posible y sin fricciones.
 * Version: 0.3.7
 * 
 * Author: Wava Technologies
 * Author URI: https://wava.co/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * 
 * Text Domain: wava-payment
 * Domain Path: /languages
 * 
 **/

function wava_version()
{
  return '0.3.7';
}

function wava_redirect()
{
  $url_shop = site_url();
  $version = wava_version();
  $confirmation = urlencode($url_shop . "/wp-json/wava-payment/webhook/orders");
  $install_url =  urlencode($url_shop . "/wp-json/wava-payment/webhook/install");
  $redirect_url = urlencode($url_shop . "/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wava_payment");
  wp_redirect('https://checkout.wava.co/login?shop=' . urlencode($url_shop) . '&orders=' . $confirmation . '&install=' . $install_url . '&redirect=' . $redirect_url . '&version=' . $version . '&platform=woocommerce');
  exit();
}

register_activation_hook(__FILE__, 'wava_activation');
function wava_activation()
{
  //Make sure WooCommerce is active
  if (!is_plugin_active('woocommerce/woocommerce.php')) {
    wp_die("<div class='error'><p><strong>Se ha producido un error</strong> Se requiere <strong>WooCommerce.</strong>Por favor instálelo y actívelo.</p></div>");
  }
}

register_deactivation_hook(__FILE__, 'wava_deactivation');
function wava_deactivation()
{
  update_option('wava_active_flag', "Desactivado");

  $api_url = 'https://api.wava.co/integrations/woo/uninstall';
  $merchant_key = get_option('wava_merchant_key_site');
  $args = array(
    'method' => 'POST',
    'body' => wp_json_encode(array(
      'merchant_key' => $merchant_key,
      'version' => wava_version()
    )),
    'headers' => array(
      'Content-Type' => 'application/json'
    ),
  );
  $response = wp_remote_post($api_url, $args);

  if (is_wp_error($response)) {
    wp_die('Error during activation #' . $response->get_error_message());
  } else {
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code === 200) {
      delete_option('wava_merchant_key_site');
    }
  }
}

function wava_remote_check_store_key($key, $url_shop)
{
  $api_url = 'https://api.wava.co/stores/' . $key . '/validate';
  $confirmation = urlencode($url_shop . "/wp-json/wava-payment/webhook/orders");
  $install_url =  urlencode($url_shop . "/wp-json/wava-payment/webhook/install");
  $args = array(
    'method' => 'POST',
    'body' => wp_json_encode(array(
      'store_url' => $url_shop,
      'webhook' => $confirmation,
      'install' => $install_url,
      'version' => wava_version()
    )),
    'headers' => array(
      'Content-Type' => 'application/json'
    ),
  );

  $response = wp_remote_post($api_url, $args);

  if (is_wp_error($response)) {
    wp_die('Error during activation #' . $response->get_error_message());
  } else {
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code === 200) {
      return true;
    } else {
      return false;
    }
  }
}

function wava_plugin_settings_link($links)
{
  $settings_link = '<a href="' . site_url() . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wava_payment">Configuración</a>';
  array_push($links, $settings_link);
  return $links;
}

function wava_plugin_install_link($links)
{
  if (!get_option('wava_merchant_key_site')) {
    $url_shop = site_url();
    $version = wava_version();
    $confirmation = urlencode($url_shop . "/wp-json/wava-payment/webhook/orders");
    $install_url =  urlencode($url_shop . "/wp-json/wava-payment/webhook/install");
    $redirect_url = urlencode($url_shop . "/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wava_payment");

    $install_link = '<a href="https://www.loom.com/share/05eadce009d84baabe7281f4bd4cfb26">Ayuda</a> | <a href="https://app.wava.co/login?shop=' . urlencode($url_shop) . '&orders=' . $confirmation . '&install=' . $install_url . '&redirect=' . $redirect_url . '&version=' . $version . '&platform=woocommerce" style="color:#c9356e;">Completar Instalación</a>';
    array_push($links, $install_link);
    return $links;
  } else {
    return $links;
  }
}

add_action('plugins_loaded', 'Wava_plugin_init', 0);
function Wava_plugin_init()
{
  //Make Merchant key is present
  if (get_option('wava_merchant_key_site')) {

    update_option('wava_active_flag', "Activo y Verificado");
    update_option('wava_plugin_version', wava_version());

    //Register Dynamic Language
    $plugin_dir = basename(dirname(__FILE__));
    load_plugin_textdomain('wava-payment', false, $plugin_dir . '/languages');

    //Register Back CSS
    wp_register_style('wava_cst_style',  plugin_dir_url(__FILE__) . 'assets/css/admin.css');
    wp_enqueue_style('wava_cst_style');

    //Register Front CSS  
    wp_register_style('wava_chk_style',  plugin_dir_url(__FILE__) . 'assets/css/checkout.css');
    wp_enqueue_style('wava_chk_style');
    if (is_checkout()) {
      wp_enqueue_style('wava_chk_style');
    }

    //add settings button in plugins directory
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wava_plugin_settings_link');


    //add wava payment method
    add_filter('woocommerce_payment_gateways', 'wava_add_gateway_class');

    //add wava gateway in woocommerce
    add_action('plugins_loaded', 'register_wava_payment_gateway');

    //register wava order status
    add_action('init', 'wava_register_order_status');

    //add order status to woocommerce selectors
    add_filter('wc_order_statuses', 'wava_selector');

    //add mail notifications if order is cancelled or rejected
    add_action('woocommerce_order_status_changed', 'wava_order_status_changed', 20, 4);
  } else {
    update_option('wava_active_flag', "Activo Sin Verificar");
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wava_plugin_install_link');
    add_action('admin_notices', 'wava_error_notice');
    function wava_error_notice()
    {
      $url_shop = site_url();
      $version = wava_version();
      $confirmation = urlencode($url_shop . "/wp-json/wava-payment/webhook/orders");
      $install_url =  urlencode($url_shop . "/wp-json/wava-payment/webhook/install");
      $redirect_url = urlencode($url_shop . "/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wava_payment");
?>
      <div class="notice notice-warning">
        <p><strong><?php echo __('Termina la instalación del plugin de Wava Payment.', 'wava-payment'); ?></strong></p>
        <p><?php echo __('Completa la instalación del plugin para comenzar a recibir pagos a través de Nequi en tu tienda en línea.', 'wava-payment'); ?></p>
        <p class="submit">
          <a href="<?php echo 'https://app.wava.co/login?shop=' . urlencode($url_shop) . '&orders=' . $confirmation . '&install=' . $install_url . '&redirect=' . $redirect_url . '&version=' . $version . '&platform=woocommerce'; ?>" class="wc-update-now button-primary">
            Completar Instalación</a>
          <a href="https://www.loom.com/share/05eadce009d84baabe7281f4bd4cfb26" class="button-secondary">
            Ayuda</a>
        </p>
      </div>
<?php
    }
  }
}

function wava_add_gateway_class($gateways)
{
  $gateways[] = 'WC_Gateway_Wava';
  return $gateways;
}

function register_wava_payment_gateway()
{
  class WC_Gateway_Wava extends WC_Payment_Gateway
  {
    public function __construct()
    {
      $this->id = 'wava_payment';
      $this->method_title = __('Wava', 'wava_checkout');
      $this->icon = $this->get_option('wava_logo');
      $this->has_fields = false;
      $this->method_title = __('Wava Payment', 'wava_checkout');
      $this->method_description = __('Recibe pagos a través de Nequi y Daviplata', 'wava_checkout');
      $this->order_button_text = __('Pagar', 'wava_checkout');

      $this->init_form_fields();
      $this->init_settings();
      $this->merchantid = get_option('wava_merchant_key_site');
      $this->wava_endorder_state = $this->get_option('wava_endorder_state');
      $this->wava_cancelorder_state = $this->get_option('wava_cancelorder_state');
      $this->wava_payorder_state = $this->get_option('wava_payorder_state');
      $this->title = $this->get_option('wava_title');
      $this->logo = $this->get_option('wava_logo');
      $this->description =  __('Compra seguro y fácil con Wava.', 'wava-payment');
      $this->instructions = $this->get_option('wava_instructions', $this->description);
      $this->redirect_link = $this->get_option('wava_redirect_link');

      add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
      $order_statuses = wc_get_order_statuses();

      $this->form_fields = array(
        'enabled' => array(
          'title'   => 'Habilitar/Deshabilitar',
          'type'    => 'checkbox',
          'label'   => __('Activar Wava como procesador de pago', 'wava-payment'),
          'default' => 'yes'
        ),
        'wava_title' => array(
          'title'       => __('Titulo', 'wava-payment'),
          'type'        => 'text',
          'description' => __('Esto controla el texto que ve el usuario durante el pago.', 'wava-payment'),
          'default'     => __('Nequi by Wava', 'wava-payment'),
          'desc_tip'    => true,
        ),
        "wava_merchant_key_site" => array(
          'title'       =>  __('Merchant Key', 'wava-payment'),
          'type'        =>  'text',
          'description' =>  __('Merchant Key de tu cuenta WAVA', 'wava-payment'),
          'default'     =>  get_option('wava_merchant_key_site'),
          'desc_tip'    =>   true,
          'custom_attributes' => array(
            'readonly' => 'readonly', // Esto establece el campo como solo lectura
          ),
        ),
        'wava_endorder_state' => array(
          'title' => __('Estado del Pedido al crearse la orden', 'wava-payment'),
          'type' => 'select',
          'css' => 'line-height: inherit',
          'default'     =>  "wc-pending",
          'description' => __('Seleccione el estado del pedido que se aplicará a la hora de aceptar la orden', 'wava-payment'),
          'options' => $order_statuses,
        ),
        'wava_cancelorder_state' => array(
          'title' => __('Estado del Pedido al rechazar o cancelar el pago', 'wava-payment'),
          'type' => 'select',
          'css' => 'line-height: inherit',
          'default'     =>  "wc-cancelled",
          'description' => __('Seleccione el estado del pedido que se aplicará luego de rechazar o cancelar el pago', 'wava-payment'),
          'options' => $order_statuses,
        ),
        'wava_payorder_state' => array(
          'title' => __('Estado del Pedido al confirmarse el pago', 'wava-payment'),
          'type' => 'select',
          'css' => 'line-height: inherit',
          'default'     =>  "wc-completed",
          'description' => __('Seleccione el estado del pedido que se aplicará luego de confirmar el estado de pago', 'wava-payment'),
          'options' => $order_statuses,
        ),
        'wava_logo' => array(
          'title'       => __('Logotipos', 'wava-payment'),
          'type'        => 'select',
          'description' => __('Elige una imagen para mostrar', 'wava-payment'),
          'default'     => __('Sin Logo', 'wava-payment'),
          'css'         => 'line-height: inherit',
          'options'     =>

          array(
            __('Sin Logo', 'wava-payment'),
            //nequi
            plugin_dir_url(__FILE__) . 'assets/img/nequi-light-horizontal.png'  => 'Nequi Horizontal para Tema Claro',
            plugin_dir_url(__FILE__) . 'assets/img/nequi-light-vertical.png'    => 'Nequi Vertical para Tema Claro',
            plugin_dir_url(__FILE__) . 'assets/img/nequi-dark-horizontal.png'   => 'Nequi Horizontal para Tema Oscuro',
            plugin_dir_url(__FILE__) . 'assets/img/nequi-dark-vertical.png'   => 'Nequi Vertical para Tema Oscuro',
            //wallets
            plugin_dir_url(__FILE__) . 'assets/img/wava-light-horizontal.png'  => 'Nequi/DaviPlata Horizontal para Tema Claro',
            plugin_dir_url(__FILE__) . 'assets/img/wava-light-vertical.png'    => 'Nequi/DaviPlata Vertical para Tema Claro',
            plugin_dir_url(__FILE__) . 'assets/img/wava-dark-horizontal.png'   => 'Nequi/DaviPlata Horizontal para Tema Oscuro',
            plugin_dir_url(__FILE__) . 'assets/img/wava-dark-vertical.png'   => 'Nequi/DaviPlata Vertical para Tema Oscuro'                        
          ),
          'desc_tip'    => true,
        ),
        'wava_additional_text' => array(
          'title'       => __('Texto Adicional', 'wava-payment'),
          'type'        => 'textarea',
          'description' => __('Úsalo para recordar Cupones o beneficios pagando con Wava.', 'wava-payment'),
          'default'     => __('Compra seguro y fácil con Wava.', 'wava-payment'),
          'desc_tip'    => true,
        ),
        'wava_instructions' => array(
          'title'       => __('Instrucciones', 'wava-payment'),
          'type'        => 'select',
          'description' => __('Instrucciones que se agregarán a la página de checkout', 'wava-payment'),
          'default'     => __('Elegir tema', 'wava-payment'),
          'css'         => 'line-height: inherit',
          'options'     => array('nequi-light' => 'Nequi para tema claro', 'nequi-dark' => 'Nequi para tema oscuro', 'light' => ' Nequi/DaviPlata para tema claro', 'dark' => 'Nequi/DaviPlata para tema oscuro'),
          'desc_tip'    => true,
        ),
        'wava_redirect_link' => array(
          'title'       => __('Página de Confirmación', 'wava-payment'),
          'type'        => 'select',
          'css'         => 'line-height: inherit',
          'description' => __('Enlace de la página a la que se redirige al cliente luego del pago.', 'wava-payment'),
          'options'     => $this->get_pages(__('Seleccionar página', 'wava-payment')),
        ),
      );
    }

    function get_pages($title = false, $indent = true)
    {
      $wp_pages = get_pages('sort_column=menu_order');
      $page_list = array();
      if ($title) $page_list[] = $title;
      $page_list[] = 'Por defecto Woocommerce';
      foreach ($wp_pages as $page) {
        $prefix = '';
        // show indented child pages?
        if ($indent) {
          $has_parent = $page->post_parent;
          while ($has_parent) {
            $prefix .=  ' - ';
            $next_page = get_page($has_parent);
            $has_parent = $next_page->post_parent;
          }
        }
        // add to page list array array
        $page_list[$page->ID] = $prefix . $page->post_title;
      }
      return $page_list;
    }

    function get_theme($theme)
    {
      if ($theme === "light") {
        $properties = array(
          '#f2f2f2', '#484848',  plugin_dir_url(__FILE__) . 'assets/img/powered-light-horizontal.png', plugin_dir_url(__FILE__) . 'assets/img/wava-generic-light.png', plugin_dir_url(__FILE__) . 'assets/img/wallets-push-notification.gif',
        'Nequi o DaviPlata');
      } 
      else if($theme === "nequi-light"){
        $properties = array(
          '#f2f2f2', '#484848',  plugin_dir_url(__FILE__) . 'assets/img/powered-light-horizontal.png', plugin_dir_url(__FILE__) . 'assets/img/wava-generic-light.png', plugin_dir_url(__FILE__) . 'assets/img/nequi-push-notification.gif',
        'Nequi');
      }
      else if ( $theme === "nequi-dark"){
        $properties = array(
          '#202020', '#fff', plugin_dir_url(__FILE__) . 'assets/img/powered-dark-horizontal.png', plugin_dir_url(__FILE__) . 'assets/img/wava-generic-dark.png', plugin_dir_url(__FILE__) . 'assets/img/nequi-push-notification.gif',
        'Nequi');
      }
      else {
        $properties = array(
          '#202020', '#fff', plugin_dir_url(__FILE__) . 'assets/img/powered-dark-horizontal.png', plugin_dir_url(__FILE__) . 'assets/img/wava-generic-dark.png', plugin_dir_url(__FILE__) . 'assets/img/wallets-push-notification.gif',
        'Nequi o DaviPlata');
      }
      return $properties;
    }

    public function payment_fields()
    {
      $text = $this->get_option('wava_additional_text', $this->wava_additional_text);
      if ($text) {
        $description = '<p style="background-color: #D8F6C4;padding: 4px 8px;font-size: 14px;font-weight: 600;line-height: 22px;border-radius: 4px;color: #000;">' .  $this->get_option('wava_description', $this->description) . '</p>';
      } else {
        $description = "<p></p>";
      }
      $theme = $this->get_theme($this->get_option('wava_instructions'));
      // AGREGA AL FORMULARIO DE PAGO LA INFORMACION DEL METODO DE PAGO CON NEQUI                 
      echo '
      <div style="width: fit-content;margin: 0.5rem;">' . $description . '</div>		  
      <div class="wava-box" style="background-color:' . $theme[0] . '; color: ' . $theme[1] . '";>
        <img src="' . $theme[4] . '" class="wava-gif"/>
        <div class="wava-container ">
          <div class="wava-steps-container">               
            <div class ="wava-number-border">
              <div class="wava-step-number">1</div>
            </div>
            <div>
              <div class="wava-step-title">Paga con tu app '.$theme[5].'.</div>
              <div class="wava-step-subtitle"> Abre la notificación o ve a la app.</div>
            </div>
          </div>

          <div class="wava-steps-container">
            <div class ="wava-number-border">
              <div class="wava-step-number">2</div>
            </div>
            <div>
              <div class="wava-step-title">Confirma el pago en la app '.$theme[5].'.</div>
              <div class="wava-step-subtitle">Acepta o ingresa el código para confirmar el pago.</div>
            </div>
          </div>

          <div class="wava-steps-container">
            <div class ="wava-number-border">
              <div class="wava-step-number">3</div>
            </div>
            <div>        
              <div class="wava-step-title">La compra será confirmada.</div>
              <div class="wava-step-subtitle">Tu pago se confirmará automáticamente.</div>
            </div>
          </div>      
        </div>

      <div class="wava-logo-container">
        <img src="' . $theme[2] . '" class="wava-logo">
      </div>
    </div>';
    }

    public function process_payment($order_id)
    {

      $order = new WC_Order($order_id);
      $order->update_status($this->get_option('wava_endorder_state'));
      $order_received_url = wc_get_endpoint_url('order-received', '', wc_get_page_permalink('checkout'));
      $key = $order->order_key;
      $purchase_summary = $order_received_url . $order_id . '/?key=' . $key;
      if ($this->get_option('wava_redirect_link') == '1') {
        $redirect_url = $purchase_summary;
      } else {
        $redirect_url = site_url()  . '?p=' . $this->get_option('wava_redirect_link') . '&key=' . $key;
      }

      //se Obtienen todos los detalles de la orden     
      $order_name = "";
      $name_billing = $order->get_billing_first_name();
      $Lastname_billing = $order->get_billing_last_name();
      $amount = $order->get_total();
      $phone_billing = $order->get_billing_phone();
      $email_billing = $order->get_billing_email();
      // Obtiene los elementos de la orden
      $items = $order->get_items();
      // Itera a través de los elementos de la orden
      foreach ($items as $item) {
        // Agrega el nombre del producto a la variable "order_name"
        $product_name = $item->get_name();
        $order_name .= $product_name . '- ';
      }
      // Elimina la coma adicional al final de "order_name"
      $order_name = rtrim($order_name, '- ');
      $generate_link = array(
        'amount' => $amount,
        'description' => $order_name,
        'user' => array(
          'first_name' => $name_billing,
          "last_name" => $Lastname_billing,
          "email" => $email_billing,
          "phone_number" => $phone_billing,
          "country" => "CO",
        ),
        'redirect_link' => $redirect_url,
        'order_key' => $order_id
      );
      // Codifica en base64     
      $api_url = 'http://api.wava.co/links';

      $args = array(
        'method' => 'POST',
        'body' => wp_json_encode($generate_link),
        'headers' => array(
          'Content-Type' => 'application/json',
          'merchant-key' => get_option('wava_merchant_key_site'),
          'x-app' => 'Woo'
        ),
      );

      $response = wp_remote_post($api_url, $args);

      if (is_wp_error($response)) {
        wp_die('Error processing transaction' . $response->get_error_message());
      } else {
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        $link =  $data['result']['link'];

        // Limpia la sesión de la orden
        WC()->session->set('order_awaiting_payment', null);

        return [
          'result' => 'success', // return success status
          'redirect' => $link
        ];
      }
    }
  }
}

function wava_register_order_status()
{
  register_post_status('wc-wava-confirm', array(
    'label'                     => 'Wava Pago Confirmado',
    'public'                    => true,
    'show_in_admin_status_list' => true,
    'show_in_admin_all_list'    => true,
    'exclude_from_search'       => false,
    'label_count'               => _n_noop('Wava Pago Confirmado <span class="count">(%s)</span>', 'Wava Pago Confirmado <span class="count">(%s)</span>')
  ));

  register_post_status('wc-wava-cancelled', array(
    'label'                     => 'Wava Pago Cancelado',
    'public'                    => true,
    'show_in_admin_status_list' => true,
    'show_in_admin_all_list'    => true,
    'exclude_from_search'       => false,
    'label_count'               => _n_noop('Wava Pago Cancelado <span class="count">(%s)</span>', 'Wava Pago Cancelado <span class="count">(%s)</span>')
  ));

  register_post_status('wc-wava-pending', array(
    'label'                     => 'Wava Pago Pendiente',
    'public'                    => true,
    'show_in_admin_status_list' => true,
    'show_in_admin_all_list'    => true,
    'exclude_from_search'       => false,
    'label_count'               => _n_noop('Wava Pago Pendiente <span class="count">(%s)</span>', 'Wava Pago Pendiente <span class="count">(%s)</span>')
  ));

  register_post_status('wc-wava-rejected', array(
    'label'                     => 'Wava Pago Rechazado',
    'public'                    => true,
    'show_in_admin_status_list' => true,
    'show_in_admin_all_list'    => true,
    'exclude_from_search'       => false,
    'label_count'               => _n_noop('Wava Pago Rechazado <span class="count">(%s)</span>', 'Wava Pago Pendiente <span class="count">(%s)</span>')
  ));
}

function wava_selector($order_statuses)
{
  $order_statuses['wc-wava-confirm'] = __('Wava Pago Confirmado', 'wava-payment');
  $order_statuses['wc-wava-cancelled'] = __('Wava Pago Cancelado', 'wava-payment');
  $order_statuses['wc-wava-pending'] = __('Wava Pago Pendiente', 'wava-payment');
  $order_statuses['wc-wava-rejected'] = __('Wava Pago Rechazado', 'wava-payment');

  return $order_statuses;
}

function wava_order_status_changed($order_id, $from, $to, $order)
{
  $wc_emails = WC()->mailer()->get_emails();

  if ($to == 'wava-cancelled') {
    $wc_emails['WC_Email_Cancelled_Order']->trigger($order_id);
  }
  if ($to == 'wava-rejected') {
    $wc_emails['WC_Email_Failed_Order']->trigger($order_id);
  }
}

//register action to orders webhook
add_action('rest_api_init', function () {
  register_rest_route('wava-payment', '/webhook/orders', array(
    'methods' => 'POST',
    'callback' => 'wava_webhook_orders',
  ));
});

//register action to install webhook
add_action('rest_api_init', function () {
  register_rest_route('wava-payment', '/webhook/install', array(
    'methods' => 'POST',
    'callback' => 'wava_webhook_install',
  ));
});

//register action to version webhook
add_action('rest_api_init', function () {
  register_rest_route('wava-payment', '/webhook/version', array(
    'methods' => 'GET',
    'callback' => 'wava_webhook_version',
  ));
});

//WEBHOOKS 
function wava_webhook_version()
{
  $response_data = array(
    'version' => get_option('wava_plugin_version'),
    'estado' => get_option('wava_active_flag')
  );

  return new WP_REST_Response($response_data, 200);
}

function wava_webhook_install($request)
{
  $data = $request->get_json_params();
  $merchant_key = $data['wava_merchant_key_site'];
  update_option('wava_merchant_key_site', $merchant_key);
  update_option('wava_active_flag', "Activo y Verificado");
}

function wava_webhook_orders($request)
{
  $data = $request->get_json_params();
  $merchant_key = get_option('wava_merchant_key_site');
  $wava_id_order      = $data['wava_id_order'];
  $wava_amount        = $data['wava_total_price'];
  $wava_status_code   = $data['wava_status_code'];

  $headers = getallheaders();

  // Verificar si se han recibido las cabeceras
  if ($headers) {
    foreach ($headers as $key => $value) {
      if ($key == 'Wava-Signature') {
        $wava_signature = $value;
      }
    }
  }

  $signature = hash('sha256', $merchant_key . '|' . $wava_id_order . '|' . $wava_amount);

  $gateway_id = 'wava_payment';
  $gateway_options = get_option("woocommerce_{$gateway_id}_settings");

  if (isset($gateway_options['wava_payorder_state'])) {
    $payorder_state = $gateway_options['wava_payorder_state'];
  } else {
    $payorder_state = 'wc-completed';
  }

  if (isset($gateway_options['wava_cancelorder_state'])) {
    $cancelorder_state = $gateway_options['wava_cancelorder_state'];
  } else {
    $cancelorder_state = 'wc-cancelled';
  }


  $woocomm_order = new WC_Order($wava_id_order);
  if ($woocomm_order) {
    // La orden existe
    //if ($wava_signature == $signature) {
    if ($woocomm_order->has_status('completed')) {
      return new WP_REST_Response("Estado no actualizado, la orden ya se encuentra completa", 200);
    } else {

      switch ($wava_status_code) {
        case 1:
          //echo "transacción aceptada";
          $woocomm_order->update_status('wc-completed');
          $woocomm_order->update_status($payorder_state);
          return new WP_REST_Response("Orden confirmada Actualizada: " . $woocomm_order, 200);
          break;
        case 2:
          //echo "transacción rechazada";
          $woocomm_order->update_status($cancelorder_state);
          return new WP_REST_Response("Orden cancelada Actualizada: " . $woocomm_order, 200);
          //echo "Orden cancelada Actualizada: ". $woocomm_order ;
          break;
        case 3:
          //echo "transacción pendiente";
          $woocomm_order->update_status('wc-wava-pending');
          return new WP_REST_Response("Orden pendiente Actualizada: " . $woocomm_order, 200);
          //echo "Orden pendiente Actualizada: ". $woocomm_order ;
          break;
        case 4:
          //echo "transacción pendiente";
          $woocomm_order->update_status('wc-wava-rejected');
          //echo "Orden rechazada Actualizada: ". $woocomm_order ;
          return new WP_REST_Response("Orden rechazada Actualizada: " . $woocomm_order, 200);
          break;
      }
    }

    /*} else {
      //echo("Firma no válida");
      return new WP_REST_Response("Firma no válida", 403);
    }*/
  } else {
    // La orden no existe
    return new WP_REST_Response("La orden no existe.", 200);
    //echo 'La orden no existe.';
  }
}
