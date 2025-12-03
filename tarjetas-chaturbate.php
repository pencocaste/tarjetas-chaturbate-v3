<?php
/*
 * Plugin Name: Tarjetas de Modelos de Chaturbate
 * Description: Muestra tarjetas de modelos de Chaturbate con diseño personalizado y funcionalidades avanzadas.
 * Version: 3.6.0
 * Author: Tu Nombre
 */

/*────────────────────  Seguridad  ────────────────────*/
if (!defined('ABSPATH')) {
    exit;
}

/*────────────────────  Constantes del plugin  ────────────────────*/
define('CHATURBATE_CARDS_VERSION', '3.6.0');
define('CHATURBATE_CARDS_ESI_BLOCK', 'chaturbate_models_esi');

/*────────────────────  Detectar LiteSpeed Cache y ESI  ────────────────────*/
function chaturbate_is_litespeed_esi_active() {
    // Verificar si LiteSpeed Cache está activo y ESI está habilitado
    if (defined('LSCWP_V') && apply_filters('litespeed_esi_status', false)) {
        return true;
    }
    return false;
}

/*────────────────────  Eliminar JS heredado del tema  ────────────────────*/
add_action('wp_print_scripts', function () {
    wp_dequeue_script('chaturbate-models-js');
    wp_deregister_script('chaturbate-models-js');
}, 1);

/*────────────────────  Registrar nonce con LiteSpeed  ────────────────────*/
add_action('init', function() {
    if (defined('LSCWP_V')) {
        // Registrar el nonce personalizado con LiteSpeed para que lo maneje como ESI
        do_action('litespeed_nonce', 'chaturbate_model_cards_nonce');
    }
});

/*────────────────────  Encolar scripts y estilos  ────────────────────*/
function chaturbate_model_cards_enqueue_scripts() {
    wp_enqueue_script(
        'chaturbate-model-cards',
        plugin_dir_url(__FILE__) . 'js/chaturbate-model-cards.js',
        array(),
        CHATURBATE_CARDS_VERSION,
        true
    );

    wp_localize_script('chaturbate-model-cards', 'chaturbate_ajax', array(
        'wm'               => 'lRUVu',
        'nonce'            => wp_create_nonce('chaturbate_model_cards_nonce'),
        'ajaxurl'          => admin_url('admin-ajax.php'),
        'refresh_interval' => 60000,
    ));

    wp_enqueue_style(
        'chaturbate-model-cards-style',
        plugin_dir_url(__FILE__) . 'css/chaturbate-model-cards.css',
        array(),
        CHATURBATE_CARDS_VERSION
    );
}
add_action('wp_enqueue_scripts', 'chaturbate_model_cards_enqueue_scripts');

/*────────────────────  Cron (limpieza de transients)  ────────────────────*/
function chaturbate_add_cron_interval($s) {
    $s['every_minute'] = array('interval' => 60, 'display' => 'Every Minute');
    return $s;
}
add_filter('cron_schedules', 'chaturbate_add_cron_interval');

function chaturbate_activate() {
    if (!wp_next_scheduled('chaturbate_cleanup_cache')) {
        wp_schedule_event(time(), 'every_minute', 'chaturbate_cleanup_cache');
    }
}
register_activation_hook(__FILE__, 'chaturbate_activate');

function chaturbate_deactivate() {
    wp_clear_scheduled_hook('chaturbate_cleanup_cache');
}
register_deactivation_hook(__FILE__, 'chaturbate_deactivate');

add_action('chaturbate_cleanup_cache', function () {
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM $wpdb->options
             WHERE option_name LIKE %s
               AND option_value < %d",
            '_transient_timeout_chaturbate_models_%',
            time()
        )
    );
});

/*────────────────────  Helper de caché interno (transients)  ────────────────────*/
function get_chaturbate_api_cache($key) {
    $c = get_transient('chaturbate_models_' . $key);
    if ($c && isset($c['timestamp'], $c['data']) && (time() - $c['timestamp']) <= 120) {
        return $c['data'];
    }
    delete_transient('chaturbate_models_' . $key);
    return false;
}

function set_chaturbate_api_cache($key, $data, $ttl = 180) {
    set_transient(
        'chaturbate_models_' . $key,
        array('timestamp' => time(), 'data' => $data),
        $ttl
    );
}

/*────────────────────  Petición a API  ────────────────────*/
function fetch_chaturbate_models($gender = 'f', $limit = 10, $offset = 0, $tag = '', $region = '', $force_fresh = false) {
    $key = md5($gender . $limit . $offset . $tag . $region);

    if (!$force_fresh && ($cache = get_chaturbate_api_cache($key))) {
        return $cache;
    }

    // Usar siempre la IP del visitante en server-side requests
    $client_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'request_ip';

    $url = 'https://chaturbate.com/api/public/affiliates/onlinerooms/';
    $url = add_query_arg(array(
        'wm'        => 'lRUVu',
        'client_ip' => $client_ip,
        'format'    => 'json',
        'gender'    => $gender,
        'limit'     => intval($limit),
        'offset'    => intval($offset),
    ), $url);

    if ($tag) {
        foreach (explode(',', $tag) as $t) {
            $url = add_query_arg('tag', trim($t), $url);
        }
    }
    if ($region) {
        foreach (explode(',', $region) as $r) {
            $url = add_query_arg('region', trim($r), $url);
        }
    }

    $res = wp_remote_get($url, array(
        'timeout' => 10,
        'headers' => array(
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' - Chaturbate Models Plugin'
        )
    ));

    if (is_wp_error($res)) {
        error_log('Chaturbate API Error: ' . $res->get_error_message());
        return array('results' => array());
    }

    $data = json_decode(wp_remote_retrieve_body($res), true);
    $data['results'] = $data['results'] ?? array();

    if (!$force_fresh) {
        set_chaturbate_api_cache($key, $data, 300);
    }

    return $data;
}

/*────────────────────  Tarjeta de modelo  ────────────────────*/
function chaturbate_generate_model_card($m, $wl = '', $track = 'default') {
    $user = $m['username'];
    $base = rtrim($wl ?: 'https://chaturbate.com', '/');
    $url  = sprintf(
        '%s/in/?tour=dT8X&campaign=lRUVu&track=%s&room=%s',
        $base,
        rawurlencode($track),
        rawurlencode($user)
    );

    $img   = $m['image_url_360x270'];
    $age   = $m['age'] ?? '';
    $cc    = strtolower($m['country'] ?? '');
    $subj  = $m['room_subject'] ?? '';
    $hrs   = round(($m['seconds_online'] ?? 0) / 3600, 1);
    $view  = $m['num_users'] ?? 0;
    $gSym  = array('f'=>'♀','m'=>'♂','t'=>'⚧','c'=>'⚤')[$m['gender']] ?? '';
    $flag  = $cc ? '<img src="https://flagcdn.com/24x18/'.$cc.'.png" alt="'.$cc.'">' : '';

    ob_start(); ?>
    <div class="chaturbate-model-card" data-username="<?php echo esc_attr($user); ?>">
        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener nofollow">
            <div class="chaturbate-model-image">
                <img src="<?php echo esc_url($img); ?>"
                     alt="<?php echo esc_attr("$user live cam"); ?>"
                     loading="lazy" width="360" height="270">
            </div>
            <div class="chaturbate-model-info">
                <div class="chaturbate-model-name"><strong><?php echo esc_html($user); ?></strong></div>
                <div class="chaturbate-model-age-gender">
                    <?php if ($age)  echo '<span class="chaturbate-model-age">'.esc_html($age).'</span>'; ?>
                    <?php if ($gSym) echo '<span class="chaturbate-model-gender">'.esc_html($gSym).'</span>'; ?>
                    <?php if ($flag) echo '<span class="chaturbate-model-country">'.$flag.'</span>'; ?>
                </div>
                <div class="chaturbate-divider"></div>
                <div class="chaturbate-model-subject" title="<?php echo esc_attr($subj); ?>">
                    <?php echo esc_html($subj); ?>
                </div>
                <div class="chaturbate-model-stats">
                    <span class="chaturbate-model-time-online">
                        <span class="emoticon webcam-icon"></span>
                        <?php echo esc_html("$hrs hrs"); ?>
                    </span>
                    <span class="chaturbate-model-viewers">
                        <span class="emoticon eye-icon"></span>
                        <?php echo esc_html($view); ?>
                    </span>
                </div>
            </div>
        </a>
    </div>
    <?php
    return ob_get_clean();
}

/*────────────────────  Generar HTML del contenedor de modelos  ────────────────────*/
function chaturbate_render_models_html($atts) {
    $g   = sanitize_text_field($atts['gender']);
    $l   = absint($atts['limit']);
    $tag = sanitize_text_field($atts['tag']);
    $reg = sanitize_text_field($atts['region']);
    $wl  = esc_url($atts['whitelabel']);
    $trk = sanitize_text_field($atts['track']);

    $id  = 'chaturbate-models-' . md5($g . $l . $tag . $reg . $wl . $trk);
    $res = fetch_chaturbate_models($g, $l, 0, $tag, $reg, true);
    $mods= $res['results'] ?? array();

    ob_start(); ?>
    <div id="<?php echo esc_attr($id); ?>" class="chaturbate-models-container"
         data-gender="<?php echo esc_attr($g); ?>"
         data-limit="<?php echo esc_attr($l); ?>"
         data-offset="<?php echo esc_attr($l); ?>"
         data-tag="<?php echo esc_attr($tag); ?>"
         data-region="<?php echo esc_attr($reg); ?>"
         data-whitelabel="<?php echo esc_attr($wl); ?>"
         data-track="<?php echo esc_attr($trk); ?>">
        <?php if ($mods) : ?>
            <div class="chaturbate-models">
                <?php foreach ($mods as $m) {
                    echo chaturbate_generate_model_card($m, $wl, $trk);
                } ?>
            </div>
        <?php else : ?>
            <p class="chaturbate-no-models">No models available.</p>
        <?php endif; ?>
    </div>
    <div class="chaturbate-load-more-container">
        <button id="chaturbate-load-more"
                class="chaturbate-load-more-button"
                data-container="<?php echo esc_attr($id); ?>">
            More webcams
        </button>
    </div>
    <?php
    return ob_get_clean();
}

/*────────────────────  Handler ESI de LiteSpeed  ────────────────────*/
add_action('litespeed_esi_load-' . CHATURBATE_CARDS_ESI_BLOCK, function($params) {
    // Marcar este bloque como NO cacheable - siempre se genera dinámicamente
    do_action('litespeed_control_set_nocache');

    // Reconstruir los atributos desde los parámetros ESI
    $atts = array(
        'gender'     => isset($params['gender']) ? $params['gender'] : 'f',
        'limit'      => isset($params['limit']) ? $params['limit'] : 10,
        'tag'        => isset($params['tag']) ? $params['tag'] : '',
        'region'     => isset($params['region']) ? $params['region'] : '',
        'whitelabel' => isset($params['whitelabel']) ? $params['whitelabel'] : '',
        'track'      => isset($params['track']) ? $params['track'] : 'default',
    );

    // Generar y mostrar el HTML
    echo chaturbate_render_models_html($atts);
});

/*────────────────────  Shortcode  ────────────────────*/
function chaturbate_model_cards_shortcode($atts) {
    $atts = shortcode_atts(array(
        'gender'     => 'f',
        'limit'      => 10,
        'tag'        => '',
        'region'     => '',
        'whitelabel' => '',
        'track'      => 'default',
    ), $atts, 'chaturbate_models');

    // Si LiteSpeed ESI está activo, usar bloque ESI
    if (chaturbate_is_litespeed_esi_active()) {
        // Parámetros para pasar al bloque ESI
        $esi_params = array(
            'gender'     => sanitize_text_field($atts['gender']),
            'limit'      => absint($atts['limit']),
            'tag'        => sanitize_text_field($atts['tag']),
            'region'     => sanitize_text_field($atts['region']),
            'whitelabel' => esc_url($atts['whitelabel']),
            'track'      => sanitize_text_field($atts['track']),
        );

        // Generar URL del bloque ESI (no cacheado)
        // Parámetros: block_id, wrapper/comment, params, control, silence
        return apply_filters(
            'litespeed_esi_url',
            CHATURBATE_CARDS_ESI_BLOCK,           // block_id
            'Chaturbate Models Dynamic Block',     // wrapper/comment
            $esi_params,                           // params
            'no-cache'                             // control - marcar como no cacheable
        );
    }

    // Fallback: renderizar directamente si ESI no está disponible
    return chaturbate_render_models_html($atts);
}
add_shortcode('chaturbate_models', 'chaturbate_model_cards_shortcode');

/*────────────────────  Registrar acciones AJAX  ────────────────────*/
add_action('wp_ajax_chaturbate_load_more_models',        'chaturbate_load_more_models');
add_action('wp_ajax_nopriv_chaturbate_load_more_models', 'chaturbate_load_more_models');
add_action('wp_ajax_chaturbate_refresh_models',          'chaturbate_refresh_models');
add_action('wp_ajax_nopriv_chaturbate_refresh_models',   'chaturbate_refresh_models');

/*────────────────────  AJAX: load more  ────────────────────*/
function chaturbate_load_more_models() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chaturbate_model_cards_nonce')) {
        wp_send_json_error('Invalid security token');
        wp_die();
    }

    $g   = sanitize_text_field($_POST['gender'] ?? 'f');
    $l   = absint($_POST['limit']  ?? 10);
    $o   = absint($_POST['offset'] ?? 0);
    $t   = sanitize_text_field($_POST['tag']    ?? '');
    $r   = sanitize_text_field($_POST['region'] ?? '');
    $wl  = esc_url($_POST['whitelabel'] ?? '');
    $trk = sanitize_text_field($_POST['track'] ?? 'default');

    $res  = fetch_chaturbate_models($g, $l, $o, $t, $r, true);
    $mods = $res['results'] ?? array();

    $html = '';
    foreach ($mods as $m) {
        $html .= chaturbate_generate_model_card($m, $wl, $trk);
    }

    wp_send_json_success(array(
        'html'       => $html,
        'count'      => count($mods),
        'new_offset' => $o + count($mods),
        'new_nonce'  => wp_create_nonce('chaturbate_model_cards_nonce'),
    ));
    wp_die();
}

/*────────────────────  AJAX: refresh  ────────────────────*/
function chaturbate_refresh_models() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chaturbate_model_cards_nonce')) {
        wp_send_json_error('Invalid security token');
        wp_die();
    }

    $g   = sanitize_text_field($_POST['gender'] ?? 'f');
    $l   = absint($_POST['limit']  ?? 10);
    $t   = sanitize_text_field($_POST['tag']    ?? '');
    $r   = sanitize_text_field($_POST['region'] ?? '');
    $wl  = esc_url($_POST['whitelabel'] ?? '');
    $trk = sanitize_text_field($_POST['track'] ?? 'default');
    $curr= array_map('sanitize_text_field', json_decode(stripslashes($_POST['usernames'] ?? '[]'), true) ?: array());

    delete_transient('chaturbate_models_' . md5($g . $l . 0 . $t . $r));

    $res  = fetch_chaturbate_models($g, $l, 0, $t, $r, true);
    $mods = $res['results'] ?? array();

    $online  = wp_list_pluck($mods, 'username');
    $offline = array_diff($curr, $online);

    $html = '';
    foreach ($mods as $m) {
        if (!in_array($m['username'], $curr, true)) {
            $html .= chaturbate_generate_model_card($m, $wl, $trk);
        }
    }

    wp_send_json_success(array(
        'online_usernames'  => array_values($online),
        'offline_usernames' => array_values($offline),
        'new_models_html'   => $html,
        'new_nonce'         => wp_create_nonce('chaturbate_model_cards_nonce'),
    ));
    wp_die();
}
