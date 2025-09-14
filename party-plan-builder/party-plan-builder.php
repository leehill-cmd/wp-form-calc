<?php
/**
 * Plugin Name: Party Plan Builder
 * Description: Auto-calculating party plan builder with date-driven nightly pricing, styling controls, and branded HTML emails. Shortcode: [party_plan_builder template="simple|advanced" show_estimate="live|gated|off"]
 * Version: 1.6.2
 * Author: White Star Tools
 */

if (!defined('ABSPATH')) exit;

/** Legacy shim to prevent fatals if any old code calls fmt() */
if (!function_exists('fmt')) {
    function fmt($n, $currency = '£') {
        if (!is_numeric($n)) {
            $n = floatval(preg_replace('/[^0-9\.\-]/', '', (string)$n));
        }
        return $currency . number_format((float)$n, 2);
    }
}

class PartyPlanBuilder {
    const OPTION_KEY = 'ppb_settings';

    public function __construct() {
        add_shortcode('party_plan_builder', [$this, 'render_builder']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'register_cpt']);
        add_action('wp_ajax_ppb_submit_quote', [$this, 'handle_submit']);
        add_action('wp_ajax_nopriv_ppb_submit_quote', [$this, 'handle_submit']);
        add_action('add_meta_boxes', [$this, 'register_quote_metabox']);
        add_filter('manage_ppb_quote_posts_columns', [$this, 'quote_columns']);
        add_action('manage_ppb_quote_posts_custom_column', [$this, 'quote_columns_content'], 10, 2);
        add_action('admin_head', [$this, 'admin_styles']);
    }

    public function default_settings() {
        return [
            'currency' => '£',
            'enable_vat' => true,
            'vat_percent' => 20,
            'service_percent' => 0,
            'min_room_hire_enabled' => false,
            'min_room_hire_guests' => 20,
            'addons' => [
                ['id' => 'string_quartet', 'label' => 'String Quartet', 'type' => 'fixed', 'amount' => 350, 'frequency' => 'once', 'min_guests' => 0, 'max_guests' => 0],
                ['id' => 'private_bar', 'label' => 'Private Bar Setup', 'type' => 'fixed', 'amount' => 150, 'frequency' => 'once', 'min_guests' => 0, 'max_guests' => 0],
                ['id' => 'spa_pool', 'label' => 'Spa Pool Access', 'type' => 'fixed', 'amount' => 250, 'frequency' => 'per_night', 'min_guests' => 0, 'max_guests' => 0],
                ['id' => 'dessert_course', 'label' => 'Extra Dessert Course', 'type' => 'per_person', 'amount' => 6, 'frequency' => 'once', 'min_guests' => 0, 'max_guests' => 0],
            ],
            'drinks_package' => [
                'enabled' => false,
                'label' => 'Drinks Package',
            ],
            'pricing' => [
                'base_pp_by_dow' => [30,30,30,30,35,40,40], // Mon..Sun
                'special_ranges' => []
            ],
            'brand' => [
                'name' => get_bloginfo('name'),
                'logo_url' => '',
                'primary_color' => '#111827',
                'email_intro' => 'Thanks for your enquiry. Here’s a summary of your quote. We’ll get back to you shortly.',
                'email_footer' => 'If you have any questions, just reply to this email. We look forward to hosting you!',
                'send_customer_email' => true,
                'email_subject_admin' => 'New Party Plan Quote - {brand}',
                'email_subject_customer' => 'Your Quote Request at {brand}'
            ],
            'admin_notify' => get_option('admin_email'),
            'ui' => [
                'primary' => '#0f172a',
                'accent' => '#2563eb',
                'radius_card' => 14,
                'radius_field' => 10,
                'radius_button' => 10,
                'spacing' => 12,
                'font_size' => 15,
                'layout' => 'two-column', // two-column | single-column
                'mobile_breakpoint' => 900,
                'estimate_mode' => 'live', // live | gated | off
            ]
        ];
    }

    public function get_settings() {
        $saved = get_option(self::OPTION_KEY);
        if (!is_array($saved)) $saved = [];
        $merged = wp_parse_args($saved, $this->default_settings());
        foreach ($merged['addons'] as &$a) {
            if (!isset($a['min_guests'])) $a['min_guests'] = 0;
            if (!isset($a['max_guests'])) $a['max_guests'] = 0;
            if (empty($a['frequency'])) $a['frequency'] = 'once';
        }
        if (!isset($merged['drinks_package']) || !is_array($merged['drinks_package'])) {
            $merged['drinks_package'] = ['enabled' => false, 'label' => 'Drinks Package'];
        } else {
            if (!isset($merged['drinks_package']['enabled'])) $merged['drinks_package']['enabled'] = false;
            if (!isset($merged['drinks_package']['label'])) $merged['drinks_package']['label'] = 'Drinks Package';
        }
        $merged['addons'] = array_values(array_filter($merged['addons'], function($a){ return ($a['id'] ?? '') !== 'drinks_package'; }));
        if (!isset($merged['pricing']['base_pp_by_dow']) || !is_array($merged['pricing']['base_pp_by_dow'])) {
            $merged['pricing']['base_pp_by_dow'] = [30,30,30,30,35,40,40];
        }
        if (!isset($merged['pricing']['special_ranges']) || !is_array($merged['pricing']['special_ranges'])) {
            $merged['pricing']['special_ranges'] = [];
        }
        if (!isset($merged['brand']) || !is_array($merged['brand'])) {
            $merged['brand'] = $this->default_settings()['brand'];
        }
        if (!isset($merged['ui']) || !is_array($merged['ui'])) {
            $merged['ui'] = $this->default_settings()['ui'];
        }
        if (!isset($merged['enable_vat'])) {
            $merged['enable_vat'] = true;
        } else {
            $merged['enable_vat'] = (bool)$merged['enable_vat'];
        }
        return $merged;
    }

    public function register_cpt() {
        register_post_type('ppb_quote', [
            'label' => 'Party Plan Quotes',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'custom-fields'],
            'menu_icon' => 'dashicons-calculator'
        ]);
    }

    public function enqueue_assets() {
        $s = $this->get_settings();

        wp_register_script('ppb-script', false, ['jquery'], '1.6.1', true);
        wp_localize_script('ppb-script', 'PPB_CONFIG', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ppb_nonce'),
            'currency' => $s['currency'],
            'enable_vat' => !empty($s['enable_vat']),
            'vat_percent' => floatval($s['vat_percent']),
            'service_percent' => floatval($s['service_percent']),
            'min_room_hire_enabled' => !empty($s['min_room_hire_enabled']),
            'min_room_hire_guests' => intval($s['min_room_hire_guests']),
            'addons' => $s['addons'],
            'drinks_package' => $s['drinks_package'],
            'pricing' => $s['pricing'],
            'ui' => $s['ui'],
        ]);
        wp_enqueue_script('ppb-script');
        wp_add_inline_script('ppb-script', $this->inline_js());

        wp_register_style('ppb-style', false);
        wp_enqueue_style('ppb-style');
        wp_add_inline_style('ppb-style', $this->inline_css($s['ui']));
    }

    public function render_builder($atts) {
        $atts = shortcode_atts([
            'template' => 'simple', // simple | advanced
            'title' => '',
            'show_estimate' => '' // live | gated | off
        ], $atts, 'party_plan_builder');

        $s = $this->get_settings();
        $title = sanitize_text_field($atts['title']);
        $template = sanitize_key($atts['template']);
        $mode = $atts['show_estimate'] ? sanitize_key($atts['show_estimate']) : $s['ui']['estimate_mode'];

        ob_start();
        if ($title) echo '<h2 class="ppb-title">'.esc_html($title).'</h2>';
        if ($template === 'advanced' || $template === 'multi' || $template === 'multistep') {
            echo $this->render_advanced($s, $mode);
        } else {
            echo $this->render_simple($s, $mode);
        }
        return ob_get_clean();
    }

    private function render_simple($s, $mode) {
        $layout_class = $s['ui']['layout'] === 'single-column' ? 'ppb-single' : 'ppb-two';
        ob_start(); ?>
        <form id="ppb-form" class="ppb-wrap <?php echo esc_attr($layout_class); ?>" novalidate data-template="simple" data-estimate-mode="<?php echo esc_attr($mode); ?>">
            <div class="ppb-grid">
                <div class="ppb-card">
                    <h3>Event Details</h3>
                    <div class="ppb-row-inline">
                        <label class="ppb-inline">
                            Arrival date
                            <input type="date" name="arrival_date" required>
                        </label>
                        <label class="ppb-inline">
                            Nights
                            <input type="number" min="1" step="1" name="nights" value="1" required>
                        </label>
                    </div>
                    <label>
                        Event type
                        <input type="text" name="event_type" placeholder="Wedding, Corporate, Birthday" required>
                    </label>
                    <label>
                        Guests
                        <input type="number" min="1" step="1" name="guests" value="50" required>
                    </label>
                    <fieldset class="ppb-fieldset">
                        <legend>Add-ons</legend>
                        <div class="ppb-addon-grid">
                        <?php foreach ($s['addons'] as $a): ?>
                            <label class="ppb-checkbox ppb-addon" data-addon-id="<?php echo esc_attr($a['id']); ?>">
                                <input type="checkbox" name="addons[]" value="<?php echo esc_attr($a['id']); ?>">
                                <span><?php echo esc_html($a['label']); ?></span>
                                <small data-addon="<?php echo esc_attr($a['id']); ?>"></small>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <label>
                        Notes
                        <textarea name="notes" rows="4" placeholder="Anything we should know"></textarea>
                    </label>
                </div>
                <div class="ppb-card ppb-sticky">
                    <h3>Your Details</h3>
                    <label>
                        Name
                        <input type="text" name="name" required>
                    </label>
                    <label>
                        Email
                        <input type="email" name="email" required>
                    </label>
                    <label>
                        Phone
                        <input type="tel" name="phone">
                    </label>

                    <h3>Estimate</h3>
                    <div id="ppb-summary" class="ppb-summary"></div>
                    <div id="ppb-mask" class="ppb-mask" hidden>Enter your details and submit to reveal your estimate. We’ll email it to you as well.</div>
                    <button type="submit" class="ppb-btn ppb-btn-primary">Get my quote</button>
                    <div id="ppb-msg" class="ppb-msg" aria-live="polite"></div>
                </div>
            </div>
        </form>
        <?php return ob_get_clean();
    }

    private function render_advanced($s, $mode) {
        $layout_class = $s['ui']['layout'] === 'single-column' ? 'ppb-single' : 'ppb-two';
        ob_start(); ?>
        <form id="ppb-form" class="ppb-wrap <?php echo esc_attr($layout_class); ?>" novalidate data-template="advanced" data-estimate-mode="<?php echo esc_attr($mode); ?>">
            <div class="ppb-steps">
                <div class="ppb-step-indicator">
                    <div class="ppb-step-dot" data-step="1">1</div>
                    <div class="ppb-step-dot" data-step="2">2</div>
                    <div class="ppb-step-dot" data-step="3">3</div>
                </div>
                <div class="ppb-progress"><div class="ppb-progress-bar" style="width:33%"></div></div>
            </div>
            <div class="ppb-grid">
                <div class="ppb-card">
                    <section class="ppb-step" data-step="1">
                        <h3>Dates & Guests</h3>
                        <div class="ppb-row-inline">
                            <label class="ppb-inline">
                                Arrival date
                                <input type="date" name="arrival_date" required>
                            </label>
                            <label class="ppb-inline">
                                Nights
                                <input type="number" min="1" step="1" name="nights" value="1" required>
                            </label>
                        </div>
                        <div class="ppb-row-inline">
                            <label class="ppb-inline">
                                Guests
                                <input type="number" min="1" step="1" name="guests" value="50" required>
                            </label>
                        </div>
                        <div class="ppb-step-actions">
                            <button type="button" class="ppb-btn ppb-next">Next</button>
                        </div>
                    </section>

                    <section class="ppb-step" data-step="2" hidden>
                        <h3>Event & Extras</h3>
                        <label>
                            Event type
                            <input type="text" name="event_type" placeholder="Wedding, Corporate, Birthday" required>
                        </label>
                        <p class="ppb-muted">Options shown will adapt to your guest count.</p>
                        <div class="ppb-addon-grid">
                        <?php foreach ($s['addons'] as $a): ?>
                            <label class="ppb-checkbox ppb-addon" data-addon-id="<?php echo esc_attr($a['id']); ?>">
                                <input type="checkbox" name="addons[]" value="<?php echo esc_attr($a['id']); ?>">
                                <span><?php echo esc_html($a['label']); ?></span>
                                <small data-addon="<?php echo esc_attr($a['id']); ?>"></small>
                            </label>
                        <?php endforeach; ?>
                        </div>
                        <label>
                            Notes
                            <textarea name="notes" rows="4" placeholder="Anything we should know"></textarea>
                        </label>
                        <div class="ppb-step-actions">
                            <button type="button" class="ppb-btn ppb-secondary ppb-back">Back</button>
                            <button type="button" class="ppb-btn ppb-next">Next</button>
                        </div>
                    </section>

                    <section class="ppb-step" data-step="3" hidden>
                        <h3>Your Details</h3>
                        <label>
                            Name
                            <input type="text" name="name" required>
                        </label>
                        <label>
                            Email
                            <input type="email" name="email" required>
                        </label>
                        <label>
                            Phone
                            <input type="tel" name="phone">
                        </label>
                        <div class="ppb-step-actions">
                            <button type="button" class="ppb-btn ppb-secondary ppb-back">Back</button>
                            <button type="submit" class="ppb-btn ppb-btn-primary">Get my quote</button>
                        </div>
                        <div id="ppb-msg" class="ppb-msg" aria-live="polite"></div>
                    </section>
                </div>

                <div class="ppb-card ppb-sticky">
                    <h3>Estimate</h3>
                    <div id="ppb-summary" class="ppb-summary"></div>
                    <div id="ppb-mask" class="ppb-mask" hidden>We’ll display your estimate here after you submit your details. It’ll also be emailed to you.</div>
                </div>
            </div>
        </form>
        <?php return ob_get_clean();
    }

    public function handle_submit() {
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_bloginfo('charset'));

        $required = ['name','email','arrival_date','nights','guests','nonce'];
        foreach ($required as $r) {
            if (!isset($_POST[$r]) || $_POST[$r] === '') {
                echo wp_json_encode(['success'=>false,'data'=>['message'=>"Missing required field: $r"]]);
                wp_die();
            }
        }

        if (!wp_verify_nonce($_POST['nonce'], 'ppb_nonce')) {
            echo wp_json_encode(['success'=>false,'data'=>['message'=>'Security check failed. Please refresh and try again.']]);
            wp_die();
        }

        $name  = sanitize_text_field(wp_unslash($_POST['name']));
        $email = sanitize_email(wp_unslash($_POST['email']));
        $arrival_date = sanitize_text_field(wp_unslash($_POST['arrival_date']));
        $nights = max(1, intval($_POST['nights']));
        $guests = max(1, intval($_POST['guests']));
        $event_type = isset($_POST['event_type']) ? sanitize_text_field(wp_unslash($_POST['event_type'])) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $addons = isset($_POST['addons']) && is_array($_POST['addons']) ? array_map('sanitize_text_field', array_map('wp_unslash', $_POST['addons'])) : [];

        $calc = $this->server_calculate_breakdown($arrival_date, $nights, $guests, $addons);

        $quote_title = $name . ' - ' . $arrival_date . ' (' . $nights . ' night' . ($nights>1?'s':'') . ')';
        $post_id = wp_insert_post([
            'post_type' => 'ppb_quote',
            'post_status' => 'publish',
            'post_title' => $quote_title,
        ], true);

        if (is_wp_error($post_id)) {
            error_log('[PPB] Insert error: ' . $post_id->get_error_message());
            echo wp_json_encode(['success'=>false,'data'=>['message'=>'Could not save your quote (insert error).']]);
            wp_die();
        }

        $save_meta = [
            'name' => $name, 'email' => $email, 'arrival_date' => $arrival_date, 'nights' => $nights,
            'guests' => $guests, 'event_type' => $event_type, 'notes' => $notes,
            'addons' => $addons, 'calc' => $calc
        ];
        foreach ($save_meta as $k => $v) {
            update_post_meta($post_id, 'ppb_' . $k, $v);
        }

        $settings = $this->get_settings();
        $brand = $settings['brand'];
        $admin_to = sanitize_email($settings['admin_notify']);
        $admin_subject = str_replace('{brand}', $brand['name'], $brand['email_subject_admin']);
        $cust_subject  = str_replace('{brand}', $brand['name'], $brand['email_subject_customer']);

        add_filter('wp_mail_content_type', function(){ return 'text/html; charset=UTF-8'; });
        $admin_body = $this->render_email_html($brand, $name, $email, $arrival_date, $nights, $guests, $event_type, $notes, $addons, $calc, true);
        if ($admin_to) wp_mail($admin_to, $admin_subject, $admin_body);
        if (!empty($brand['send_customer_email']) && $email) {
            $cust_body = $this->render_email_html($brand, $name, $email, $arrival_date, $nights, $guests, $event_type, $notes, $addons, $calc, false);
            wp_mail($email, $cust_subject, $cust_body);
        }
        remove_filter('wp_mail_content_type', '__return_false');

        echo wp_json_encode(['success'=>true,'data'=>['message'=>'Thanks. Your quote has been received. We’ll email you shortly.']]);
        wp_die();
    }

    private function ppp_for_date(DateTime $d, $pricing){
        $dow = intval($d->format('w')); // 0 Sun..6 Sat
        $dow_index = [0=>6,1=>0,2=>1,3=>2,4=>3,5=>4,6=>5][$dow];
        $base = isset($pricing['base_pp_by_dow'][$dow_index]) ? floatval($pricing['base_pp_by_dow'][$dow_index]) : 0.0;

        $date_str = $d->format('Y-m-d');
        foreach ($pricing['special_ranges'] as $r) {
            $start = isset($r['start']) ? $r['start'] : '';
            $end   = isset($r['end']) ? $r['end'] : '';
            if ($start && $end && $date_str >= $start && $date_str <= $end) {
                if (isset($r['pp_override']) && $r['pp_override'] !== '') return floatval($r['pp_override']);
                if (isset($r['multiplier']) && $r['multiplier'] !== '') return $base * floatval($r['multiplier']);
            }
        }
        return $base;
    }

    private function server_calculate_breakdown($arrival_date, $nights, $guests, $selected_addons) {
        $s = $this->get_settings();
        $billable_guests = !empty($s['min_room_hire_enabled']) ? max($guests, intval($s['min_room_hire_guests'])) : $guests;
        $dates = [];
        try { $start = new DateTime($arrival_date); } catch (Exception $e) { $start = new DateTime(); }
        for ($i=0; $i<$nights; $i++) { $d = clone $start; $d->modify("+$i day"); $dates[] = $d; }

        $line_items = [];
        $per_person_nightly_addons = 0; $fixed_nightly_addons = 0; $oneoff_per_person = 0; $oneoff_fixed = 0; $drinks_total = 0;
        if (!empty($s['drinks_package']['enabled'])) {
            $drinks_total = min(500 + max(0, $guests-20) * 25, 1000);
            $oneoff_fixed += $drinks_total;
        }
        foreach ($s['addons'] as $a) {
            if (!in_array($a['id'], $selected_addons, true)) continue;
            $amount = floatval($a['amount']); $freq = isset($a['frequency']) ? $a['frequency'] : 'once';
            if ($a['type'] === 'per_person') { if ($freq === 'per_night') $per_person_nightly_addons += $amount; else $oneoff_per_person += $amount; }
            else { if ($freq === 'per_night') $fixed_nightly_addons += $amount; else $oneoff_fixed += $amount; }
        }

        $subtotal = 0;
        foreach ($dates as $d) {
            $ppp = $this->ppp_for_date($d, $s['pricing']);
            $line = [
                'date' => $d->format('Y-m-d'),
                'label' => $d->format('D j M Y'),
                'ppp' => $ppp,
                'guests' => $guests,
                'per_person_total' => $ppp * $billable_guests,
                'per_person_addons' => $per_person_nightly_addons * $billable_guests,
                'fixed_addons' => $fixed_nightly_addons,
            ];
            $line['night_total'] = $line['per_person_total'] + $line['per_person_addons'] + $line['fixed_addons'];
            $line_items[] = $line;
            $subtotal += $line['night_total'];
        }

        $subtotal += ($oneoff_per_person * $billable_guests) + $oneoff_fixed;

        $service = $subtotal * (floatval($s['service_percent'])/100);
        $taxable = $subtotal + $service;
        $enable_vat = !empty($s['enable_vat']);
        $vat = $enable_vat ? $taxable * (floatval($s['vat_percent'])/100) : 0;
        $grand = $taxable + $vat;

        $grand_pp = $billable_guests > 0 ? ($grand / $billable_guests) : $grand;
        $subtotal_pp = $billable_guests > 0 ? ($subtotal / $billable_guests) : $subtotal;
        $service_pp = $billable_guests > 0 ? ($service / $billable_guests) : $service;
        $vat_pp = $billable_guests > 0 ? ($vat / $billable_guests) : $vat;

        return [
            'lines' => $line_items,
            'oneoff_per_person' => $oneoff_per_person,
            'oneoff_fixed' => $oneoff_fixed,
            'drinks_package' => $drinks_total,
            'summary' => [
                'subtotal' => $subtotal,
                'service' => $service,
                'vat' => $vat,
                'grand' => $grand,
                'subtotal_pp' => $subtotal_pp,
                'service_pp' => $service_pp,
                'vat_pp' => $vat_pp,
                'grand_pp' => $grand_pp,
            ],
            'currency' => $s['currency'],
            'enable_vat' => $enable_vat
        ];
    }

    public function admin_menu() {
        add_menu_page('Party Plan Builder', 'Party Plan Builder', 'manage_options', 'ppb-settings', [$this, 'settings_page'], 'dashicons-calculator', 56);
    }

    public function register_settings() {
        register_setting('ppb_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
    }

    public function sanitize_settings($input){
        $defaults = $this->default_settings();
        $out = [
            'currency' => isset($input['currency']) ? sanitize_text_field($input['currency']) : $defaults['currency'],
            'enable_vat' => !empty($input['enable_vat']),
            'vat_percent' => isset($input['vat_percent']) ? floatval($input['vat_percent']) : $defaults['vat_percent'],
            'service_percent' => isset($input['service_percent']) ? floatval($input['service_percent']) : $defaults['service_percent'],
            'min_room_hire_enabled' => !empty($input['min_room_hire_enabled']),
            'min_room_hire_guests' => isset($input['min_room_hire_guests']) ? intval($input['min_room_hire_guests']) : $defaults['min_room_hire_guests'],
            'admin_notify' => isset($input['admin_notify']) ? sanitize_email($input['admin_notify']) : $defaults['admin_notify'],
            'drinks_package' => ['enabled' => false, 'label' => $defaults['drinks_package']['label']],
            'addons' => [],
            'pricing' => ['base_pp_by_dow' => [30,30,30,30,35,40,40], 'special_ranges' => []],
            'brand' => $defaults['brand'],
            'ui' => $defaults['ui'],
        ];

        if (!empty($input['addons']) && is_array($input['addons'])) {
            foreach ($input['addons'] as $a) {
                if (empty($a['label'])) continue;
                $id = sanitize_title($a['id'] ?? $a['label']);
                if ($id === 'drinks_package') continue;
                $type = ($a['type'] === 'per_person') ? 'per_person' : 'fixed';
                $freq = ($a['frequency'] === 'per_night') ? 'per_night' : 'once';
                $out['addons'][] = [
                    'id' => $id,
                    'label' => sanitize_text_field($a['label']),
                    'type' => $type,
                    'frequency' => $freq,
                    'amount' => isset($a['amount']) ? floatval($a['amount']) : 0,
                    'min_guests' => isset($a['min_guests']) ? intval($a['min_guests']) : 0,
                    'max_guests' => isset($a['max_guests']) ? intval($a['max_guests']) : 0,
                ];
            }
        }

        if (!empty($input['drinks_package']) && is_array($input['drinks_package'])) {
            $dp = $input['drinks_package'];
            $out['drinks_package'] = [
                'enabled' => !empty($dp['enabled']),
                'label' => sanitize_text_field($dp['label'] ?? $defaults['drinks_package']['label']),
            ];
        }

        if (!empty($input['pricing']['base_pp_by_dow']) && is_array($input['pricing']['base_pp_by_dow'])) {
            $dows = [];
            for ($i=0; $i<7; $i++) $dows[$i] = isset($input['pricing']['base_pp_by_dow'][$i]) ? floatval($input['pricing']['base_pp_by_dow'][$i]) : 0.0;
            $out['pricing']['base_pp_by_dow'] = $dows;
        }
        if (!empty($input['pricing']['special_ranges']) && is_array($input['pricing']['special_ranges'])) {
            $ranges = [];
            foreach ($input['pricing']['special_ranges'] as $r) {
                $ranges[] = [
                    'label' => sanitize_text_field($r['label'] ?? ''),
                    'start' => sanitize_text_field($r['start'] ?? ''),
                    'end' => sanitize_text_field($r['end'] ?? ''),
                    'pp_override' => isset($r['pp_override']) && $r['pp_override'] !== '' ? floatval($r['pp_override']) : '',
                    'multiplier' => isset($r['multiplier']) && $r['multiplier'] !== '' ? floatval($r['multiplier']) : ''
                ];
            }
            $out['pricing']['special_ranges'] = $ranges;
        }

        if (!empty($input['brand']) && is_array($input['brand'])) {
            $b = $input['brand'];
            $out['brand'] = [
                'name' => sanitize_text_field($b['name'] ?? $defaults['brand']['name']),
                'logo_url' => esc_url_raw($b['logo_url'] ?? ''),
                'primary_color' => sanitize_hex_color($b['primary_color'] ?? '#111827'),
                'email_intro' => wp_kses_post($b['email_intro'] ?? $defaults['brand']['email_intro']),
                'email_footer' => wp_kses_post($b['email_footer'] ?? $defaults['brand']['email_footer']),
                'send_customer_email' => !empty($b['send_customer_email']) ? true : false,
                'email_subject_admin' => sanitize_text_field($b['email_subject_admin'] ?? $defaults['brand']['email_subject_admin']),
                'email_subject_customer' => sanitize_text_field($b['email_subject_customer'] ?? $defaults['brand']['email_subject_customer']),
            ];
        }

        if (!empty($input['ui']) && is_array($input['ui'])) {
            $u = $input['ui'];
            $out['ui'] = [
                'primary' => sanitize_hex_color($u['primary'] ?? '#0f172a'),
                'accent' => sanitize_hex_color($u['accent'] ?? '#2563eb'),
                'radius_card' => intval($u['radius_card'] ?? 14),
                'radius_field' => intval($u['radius_field'] ?? 10),
                'radius_button' => intval($u['radius_button'] ?? 10),
                'spacing' => intval($u['spacing'] ?? 12),
                'font_size' => intval($u['font_size'] ?? 15),
                'layout' => in_array(($u['layout'] ?? 'two-column'), ['two-column','single-column'], true) ? $u['layout'] : 'two-column',
                'mobile_breakpoint' => intval($u['mobile_breakpoint'] ?? 900),
                'estimate_mode' => in_array(($u['estimate_mode'] ?? 'live'), ['live','gated','off'], true) ? $u['estimate_mode'] : 'live',
            ];
        }

        return $out;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        $addons   = is_array($s['addons']) ? array_values($s['addons']) : [];
        $brand    = $s['brand'];
        $ui       = $s['ui'];
        $drinks   = $s['drinks_package'];
        if (empty($addons)) $addons = [['id' => 'string_quartet','label' => 'String Quartet','type' => 'fixed','frequency' => 'once','amount' => 350,'min_guests' => 0,'max_guests' => 0]];
        ?>
        <div class="wrap">
            <h1>Party Plan Builder Settings</h1>
            <form method="post" action="options.php" id="ppb-admin-form">
                <?php settings_fields('ppb_settings_group'); ?>

                <h2>General</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="currency">Currency symbol</label></th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[currency]" id="currency" type="text" value="<?php echo esc_attr($s['currency']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="enable_vat">Enable VAT</label></th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[enable_vat]" id="enable_vat" type="checkbox" value="1" <?php checked(!empty($s['enable_vat'])); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vat_percent">VAT percent</label></th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[vat_percent]" id="vat_percent" type="number" step="0.1" value="<?php echo esc_attr($s['vat_percent']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="service_percent">Service percent</label></th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[service_percent]" id="service_percent" type="number" step="0.1" value="<?php echo esc_attr($s['service_percent']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_room_hire_guests">Min billable guests</label></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[min_room_hire_enabled]" value="1" <?php checked(true, !empty($s['min_room_hire_enabled'])); ?> /> Enable</label>
                            <input name="<?php echo self::OPTION_KEY; ?>[min_room_hire_guests]" id="min_room_hire_guests" type="number" step="1" min="1" value="<?php echo esc_attr($s['min_room_hire_guests']); ?>" style="width:80px;margin-left:8px;" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Admin notify email</th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[admin_notify]" type="email" value="<?php echo esc_attr($s['admin_notify']); ?>" class="regular-text" /></td>
                    </tr>
                </table>

                <h2>Add-ons</h2>
                <p>Frequency decides if an add-on is charged once or per night. Min/Max guests filter by group size (0 = no limit).</p>
                <table class="widefat fixed striped" id="ppb-addons-table">
                    <thead><tr><th style="width:12%">ID</th><th>Label</th><th style="width:12%">Type</th><th style="width:12%">Frequency</th><th style="width:12%">Amount</th><th style="width:8%">Min</th><th style="width:8%">Max</th><th style="width:10%">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($addons as $j => $a): ?>
                        <tr>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][id]" type="text" value="<?php echo esc_attr($a['id']); ?>" /></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][label]" type="text" value="<?php echo esc_attr($a['label']); ?>" /></td>
                            <td>
                                <select name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][type]">
                                    <option value="fixed" <?php selected('fixed', $a['type']); ?>>fixed</option>
                                    <option value="per_person" <?php selected('per_person', $a['type']); ?>>per_person</option>
                                </select>
                            </td>
                            <td>
                                <select name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][frequency]">
                                    <option value="once" <?php selected('once', $a['frequency']); ?>>once</option>
                                    <option value="per_night" <?php selected('per_night', $a['frequency']); ?>>per night</option>
                                </select>
                            </td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][amount]" type="number" step="0.01" value="<?php echo esc_attr($a['amount']); ?>" /></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][min_guests]" type="number" step="1" value="<?php echo esc_attr($a['min_guests']); ?>" /></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[addons][<?php echo $j; ?>][max_guests]" type="number" step="1" value="<?php echo esc_attr($a['max_guests']); ?>" /></td>
                            <td><button class="button button-link-delete ppb-remove-row" type="button">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="ppb-add-addon">Add add-on</button></p>

                <h2>Drinks Package</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable?</th>
                        <td><label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[drinks_package][enabled]" value="1" <?php checked(true, !empty($drinks['enabled'])); ?>> Yes</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="drinks_package_label">Label</label></th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[drinks_package][label]" id="drinks_package_label" type="text" value="<?php echo esc_attr($drinks['label']); ?>" class="regular-text" /></td>
                    </tr>
                </table>

                <h2>Pricing Rules</h2>
                <h3>Base per-person price by day</h3>
                <table class="widefat fixed striped">
                    <thead><tr><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th></tr></thead>
                    <tbody><tr>
                        <?php for($i=0;$i<7;$i++): ?>
                        <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][base_pp_by_dow][<?php echo $i; ?>]" type="number" step="0.01" value="<?php echo esc_attr($s['pricing']['base_pp_by_dow'][$i]); ?>"></td>
                        <?php endfor; ?>
                    </tr></tbody>
                </table>

                <h3>Special date ranges</h3>
                <p>Either set a <strong>PP override</strong> (exact per-person nightly price) or a <strong>Multiplier</strong> for those dates. Override wins if both are set.</p>
                <table class="widefat fixed striped" id="ppb-ranges-table">
                    <thead><tr><th>Label</th><th>Start</th><th>End</th><th>PP override</th><th>Multiplier</th><th style="width:10%">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($s['pricing']['special_ranges'] as $k => $r): ?>
                        <tr>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][<?php echo $k; ?>][label]" type="text" value="<?php echo esc_attr($r['label']); ?>"></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][<?php echo $k; ?>][start]" type="date" value="<?php echo esc_attr($r['start']); ?>"></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][<?php echo $k; ?>][end]" type="date" value="<?php echo esc_attr($r['end']); ?>"></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][<?php echo $k; ?>][pp_override]" type="number" step="0.01" value="<?php echo esc_attr($r['pp_override']); ?>"></td>
                            <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][<?php echo $k; ?>][multiplier]" type="number" step="0.01" value="<?php echo esc_attr($r['multiplier']); ?>"></td>
                            <td><button class="button button-link-delete ppb-remove-row" type="button">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="ppb-add-range">Add date range</button></p>

                <h2>Brand & Email</h2>
                <table class="form-table">
                    <tr><th>Brand name</th><td><input name="<?php echo self::OPTION_KEY; ?>[brand][name]" type="text" value="<?php echo esc_attr($brand['name']); ?>" class="regular-text"></td></tr>
                    <tr><th>Logo URL</th><td><input name="<?php echo self::OPTION_KEY; ?>[brand][logo_url]" type="url" value="<?php echo esc_attr($brand['logo_url']); ?>" class="regular-text"></td></tr>
                    <tr><th>Primary colour</th><td><input name="<?php echo self::OPTION_KEY; ?>[brand][primary_color]" type="text" value="<?php echo esc_attr($brand['primary_color']); ?>" class="regular-text" placeholder="#111827"></td></tr>
                    <tr><th>Intro (customer email)</th><td><textarea name="<?php echo self::OPTION_KEY; ?>[brand][email_intro]" rows="3" class="large-text"><?php echo esc_textarea($brand['email_intro']); ?></textarea></td></tr>
                    <tr><th>Footer (both emails)</th><td><textarea name="<?php echo self::OPTION_KEY; ?>[brand][email_footer]" rows="3" class="large-text"><?php echo esc_textarea($brand['email_footer']); ?></textarea></td></tr>
                    <tr><th>Send customer confirmation?</th><td><label><input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[brand][send_customer_email]" value="1" <?php checked(true, !empty($brand['send_customer_email'])); ?>> Yes</label></td></tr>
                    <tr><th>Admin email subject</th><td><input name="<?php echo self::OPTION_KEY; ?>[brand][email_subject_admin]" type="text" value="<?php echo esc_attr($brand['email_subject_admin']); ?>" class="regular-text"></td></tr>
                    <tr><th>Customer email subject</th><td><input name="<?php echo self::OPTION_KEY; ?>[brand][email_subject_customer]" type="text" value="<?php echo esc_attr($brand['email_subject_customer']); ?>" class="regular-text"></td></tr>
                </table>

                <h2>Styling & Layout</h2>
                <table class="form-table">
                    <tr><th>Primary colour</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][primary]" type="text" value="<?php echo esc_attr($ui['primary']); ?>" class="regular-text"></td></tr>
                    <tr><th>Accent colour</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][accent]" type="text" value="<?php echo esc_attr($ui['accent']); ?>" class="regular-text"></td></tr>
                    <tr><th>Card radius (px)</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][radius_card]" type="number" value="<?php echo esc_attr($ui['radius_card']); ?>"></td></tr>
                    <tr><th>Field radius (px)</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][radius_field]" type="number" value="<?php echo esc_attr($ui['radius_field']); ?>"></td></tr>
                    <tr><th>Button radius (px)</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][radius_button]" type="number" value="<?php echo esc_attr($ui['radius_button']); ?>"></td></tr>
                    <tr><th>Spacing (px)</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][spacing]" type="number" value="<?php echo esc_attr($ui['spacing']); ?>"></td></tr>
                    <tr><th>Base font size (px)</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][font_size]" type="number" value="<?php echo esc_attr($ui['font_size']); ?>"></td></tr>
                    <tr><th>Layout</th><td>
                        <select name="<?php echo self::OPTION_KEY; ?>[ui][layout]">
                            <option value="two-column" <?php selected('two-column', $ui['layout']); ?>>Two column</option>
                            <option value="single-column" <?php selected('single-column', $ui['layout']); ?>>Single column</option>
                        </select>
                    </td></tr>
                    <tr><th>Mobile breakpoint (px)</th><td><input name="<?php echo self::OPTION_KEY; ?>[ui][mobile_breakpoint]" type="number" value="<?php echo esc_attr($ui['mobile_breakpoint']); ?>"></td></tr>
                    <tr><th>Estimate display</th><td>
                        <select name="<?php echo self::OPTION_KEY; ?>[ui][estimate_mode]">
                            <option value="live" <?php selected('live', $ui['estimate_mode']); ?>>Live (always visible)</option>
                            <option value="gated" <?php selected('gated', $ui['estimate_mode']); ?>>Gated (show after submit)</option>
                            <option value="off" <?php selected('off', $ui['estimate_mode']); ?>>Off (email only)</option>
                        </select>
                    </td></tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <p><strong>Shortcodes</strong> – Simple: <code>[party_plan_builder template="simple"]</code> • Advanced (multi-step): <code>[party_plan_builder template="advanced"]</code> • Override display: <code>show_estimate="live|gated|off"</code>.</p>
        </div>

        <script>
        (function(){
            function addRow(tableId, cells){
                const tbody = document.querySelector('#'+tableId+' tbody');
                const idx = tbody.querySelectorAll('tr').length;
                const tr = document.createElement('tr');
                tr.innerHTML = cells.replaceAll('__INDEX__', idx);
                tbody.appendChild(tr);
            }
            document.getElementById('ppb-add-addon')?.addEventListener('click', function(){
                addRow('ppb-addons-table', `
                    <td><input name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][id]" type="text" value="" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][label]" type="text" value="" /></td>
                    <td>
                        <select name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][type]">
                            <option value="fixed">fixed</option>
                            <option value="per_person">per_person</option>
                        </select>
                    </td>
                    <td>
                        <select name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][frequency]">
                            <option value="once">once</option>
                            <option value="per_night">per night</option>
                        </select>
                    </td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][amount]" type="number" step="0.01" value="0" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][min_guests]" type="number" step="1" value="0" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[addons][__INDEX__][max_guests]" type="number" step="1" value="0" /></td>
                    <td><button class="button button-link-delete ppb-remove-row" type="button">Remove</button></td>
                `);
            });
            document.getElementById('ppb-add-range')?.addEventListener('click', function(){
                addRow('ppb-ranges-table', `
                    <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][__INDEX__][label]" type="text" value="" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][__INDEX__][start]" type="date" value="" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][__INDEX__][end]" type="date" value="" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][__INDEX__][pp_override]" type="number" step="0.01" value="" /></td>
                    <td><input name="<?php echo self::OPTION_KEY; ?>[pricing][special_ranges][__INDEX__][multiplier]" type="number" step="0.01" value="" /></td>
                    <td><button class="button button-link-delete ppb-remove-row" type="button">Remove</button></td>
                `);
            });
            document.addEventListener('click', function(e){
                if(e.target && e.target.classList.contains('ppb-remove-row')){
                    e.preventDefault();
                    const tr = e.target.closest('tr');
                    tr?.parentNode?.removeChild(tr);
                }
            });
        })();
        </script>
        <style>#ppb-addons-table input, #ppb-addons-table select { width: 100%; }</style>
        <?php
    }

    private function render_email_html($brand, $name, $email, $arrival, $nights, $guests, $event_type, $notes, $addons, $calc, $for_admin = true){
        $report = $this->render_quote_html($name, $email, $arrival, $nights, $guests, $event_type, $notes, $addons, $calc, true);
        $logo = $brand['logo_url'] ? '<img src="'.esc_url($brand['logo_url']).'" alt="'.esc_attr($brand['name']).'" style="max-height:50px;vertical-align:middle">' : '';
        $intro = $for_admin ? '<p style="margin:0 0 12px 0">A new enquiry has arrived.</p>' : '<p style="margin:0 0 12px 0">'.wp_kses_post($brand['email_intro']).'</p>';
        $footer = '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">'.wp_kses_post($brand['email_footer']).'</p>';
        $color = esc_attr($brand['primary_color'] ?: '#111827');
        $brand_name = esc_html($brand['name']);
        return '
<!doctype html><html><body style="margin:0;padding:0;background:#f6f7f9">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f9;padding:24px 0">
    <tr><td align="center">
      <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
        <tr><td style="background:'.$color.';color:#fff;padding:14px 18px;font:600 18px/1.2 system-ui,Segoe UI,Arial">'.$logo.' <span style="margin-left:10px;vertical-align:middle">'.$brand_name.'</span></td></tr>
        <tr><td style="padding:18px">'. $intro . $report . $footer . '</td></tr>
      </table>
    </td></tr>
  </table>
</body></html>';
    }

    public function register_quote_metabox() {
        add_meta_box('ppb_quote_box', 'Quote Report', [$this, 'render_quote_metabox'], 'ppb_quote', 'normal', 'high');
    }

    public function render_quote_metabox($post) {
        $name = get_post_meta($post->ID, 'ppb_name', true);
        $email = get_post_meta($post->ID, 'ppb_email', true);
        $arrival = get_post_meta($post->ID, 'ppb_arrival_date', true);
        $nights = intval(get_post_meta($post->ID, 'ppb_nights', true));
        $guests = intval(get_post_meta($post->ID, 'ppb_guests', true));
        $event_type = get_post_meta($post->ID, 'ppb_event_type', true);
        $notes = get_post_meta($post->ID, 'ppb_notes', true);
        $calc = get_post_meta($post->ID, 'ppb_calc', true);
        if (!is_array($calc)) $calc = [];
        echo $this->render_quote_html($name, $email, $arrival, $nights, $guests, $event_type, $notes, get_post_meta($post->ID, 'ppb_addons', true), $calc, false);
    }

    private function render_quote_html($name, $email, $arrival, $nights, $guests, $event_type, $notes, $addons, $calc, $for_email = false) {
        $currency = isset($calc['currency']) ? $calc['currency'] : '£';
        $sum = isset($calc['summary']) ? $calc['summary'] : ['subtotal' => 0,'service' => 0,'vat' => 0,'grand' => 0,'subtotal_pp' => 0,'service_pp' => 0,'vat_pp' => 0,'grand_pp' => 0];
        $s = $this->get_settings();
        $drinks_label = $s['drinks_package']['label'] ?? 'Drinks Package';
        $drinks_amount = isset($calc['drinks_package']) ? floatval($calc['drinks_package']) : (!empty($s['drinks_package']['enabled']) ? min(500 + max(0, $guests-20) * 25, 1000) : 0);
        $billable = ($sum['grand_pp'] > 0) ? max(1, $sum['grand'] / $sum['grand_pp']) : max(1, intval($guests));
        ob_start(); ?>
        <div class="ppb-quote-report" style="<?php echo $for_email ? 'font-family:system-ui,Segoe UI,Arial;color:#111827' : ''; ?>">
            <div class="ppb-quote-header" style="<?php echo $for_email ? 'margin-bottom:12px' : ''; ?>">
                <div>
                    <h2 style="<?php echo $for_email ? 'font-size:20px;margin:0 0 4px' : ''; ?>">Quote Summary</h2>
                    <p style="margin:0 0 6px"><strong>Client:</strong> <?php echo esc_html($name); ?> &nbsp; | &nbsp; <strong>Email:</strong> <?php echo esc_html($email); ?></p>
                    <p style="margin:0 0 6px"><strong>Event:</strong> <?php echo esc_html($event_type ?: '—'); ?> &nbsp; | &nbsp; <strong>Guests:</strong> <?php echo intval($guests); ?></p>
                    <p style="margin:0 0 6px"><strong>Arrival:</strong> <?php echo esc_html($arrival); ?> &nbsp; | &nbsp; <strong>Nights:</strong> <?php echo intval($nights); ?></p>
                </div>
            </div>

            <?php if (!empty($calc['lines'])): ?>
            <table class="ppb-table" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:auto">
                <thead>
                    <tr>
                        <th align="left" style="border-bottom:1px solid #e5e7eb;padding:10px 8px;min-width:160px">Date</th>
                        <th align="right" style="border-bottom:1px solid #e5e7eb;padding:10px 8px;min-width:110px">PPP</th>
                        <th align="right" style="border-bottom:1px solid #e5e7eb;padding:10px 8px;min-width:90px">Guests</th>
                        <th align="right" style="border-bottom:1px solid #e5e7eb;padding:10px 8px;min-width:170px">Per-person total</th>
                        <th align="right" style="border-bottom:1px solid #e5e7eb;padding:10px 8px;min-width:150px">Night add-ons</th>
                        <th align="right" style="border-bottom:1px solid #e5e7eb;padding:10px 8px;min-width:140px">Night total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calc['lines'] as $line): ?>
                    <tr>
                        <td style="border-bottom:1px solid #f3f4f6;padding:8px 8px"><?php echo esc_html($line['label']); ?></td>
                        <td align="right" style="border-bottom:1px solid #f3f4f6;padding:8px 8px"><?php echo esc_html($currency . number_format((float)$line['ppp'],2)); ?></td>
                        <td align="right" style="border-bottom:1px solid #f3f4f6;padding:8px 8px"><?php echo intval($line['guests']); ?></td>
                        <td align="right" style="border-bottom:1px solid #f3f4f6;padding:8px 8px"><?php echo esc_html($currency . number_format((float)$line['per_person_total'],2)); ?></td>
                        <td align="right" style="border-bottom:1px solid #f3f4f6;padding:8px 8px"><?php echo esc_html($currency . number_format((float)($line['per_person_addons'] + $line['fixed_addons']),2)); ?></td>
                        <td align="right" style="border-bottom:1px solid #f3f4f6;padding:8px 8px"><strong><?php echo esc_html($currency . number_format((float)$line['night_total'],2)); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="ppb-summary-totals" style="margin-top:12px">
                <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">
                    <tbody>
                        <?php if ($drinks_amount > 0): ?>
                        <tr>
                            <td style="padding:8px 6px"><?php echo esc_html($drinks_label); ?></td>
                            <td align="right" style="padding:8px 6px"><?php echo $currency . number_format((float)$drinks_amount,2); ?></td>
                            <td align="right" style="padding:8px 6px;color:#6b7280">/ person</td>
                            <td align="right" style="padding:8px 6px;color:#6b7280"><?php echo $currency . number_format((float)($drinks_amount/$billable),2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:8px 6px"><strong>Subtotal</strong></td>
                            <td align="right" style="padding:8px 6px"><?php echo $currency . number_format((float)$sum['subtotal'],2); ?></td>
                            <td align="right" style="padding:8px 6px;color:#6b7280">/ person</td>
                            <td align="right" style="padding:8px 6px;color:#6b7280"><?php echo $currency . number_format((float)$sum['subtotal_pp'],2); ?></td>
                        </tr>
                        <?php if ($sum['service'] > 0): ?>
                        <tr>
                            <td style="padding:8px 6px"><strong>Service</strong></td>
                            <td align="right" style="padding:8px 6px"><?php echo $currency . number_format((float)$sum['service'],2); ?></td>
                            <td align="right" style="padding:8px 6px;color:#6b7280">/ person</td>
                            <td align="right" style="padding:8px 6px;color:#6b7280"><?php echo $currency . number_format((float)$sum['service_pp'],2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($calc['enable_vat']) && $sum['vat'] > 0): ?>
                        <tr>
                            <td style="padding:8px 6px"><strong>VAT</strong></td>
                            <td align="right" style="padding:8px 6px"><?php echo $currency . number_format((float)$sum['vat'],2); ?></td>
                            <td align="right" style="padding:8px 6px;color:#6b7280">/ person</td>
                            <td align="right" style="padding:8px 6px;color:#6b7280"><?php echo $currency . number_format((float)$sum['vat_pp'],2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:10px 6px;border-top:1px solid #e5e7eb"><strong>Total</strong></td>
                            <td align="right" style="padding:10px 6px;border-top:1px solid #e5e7eb"><strong><?php echo $currency . number_format((float)$sum['grand'],2); ?></strong></td>
                            <td align="right" style="padding:10px 6px;border-top:1px solid #e5e7eb;color:#6b7280"><strong>/ person</strong></td>
                            <td align="right" style="padding:10px 6px;border-top:1px solid #e5e7eb;color:#6b7280"><strong><?php echo $currency . number_format((float)$sum['grand_pp'],2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($notes)): ?>
            <div style="margin-top:10px">
                <strong>Notes:</strong>
                <div><?php echo nl2br(esc_html($notes)); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function quote_columns($cols) {
        $cols['arrival'] = 'Arrival';
        $cols['nights'] = 'Nights';
        $cols['guests'] = 'Guests';
        $cols['total'] = 'Total';
        $cols['pp'] = 'Per person';
        return $cols;
    }
    public function quote_columns_content($col, $post_id) {
        $calc = get_post_meta($post_id, 'ppb_calc', true);
        switch ($col) {
            case 'arrival': echo esc_html(get_post_meta($post_id, 'ppb_arrival_date', true)); break;
            case 'nights': echo intval(get_post_meta($post_id, 'ppb_nights', true)); break;
            case 'guests': echo intval(get_post_meta($post_id, 'ppb_guests', true)); break;
            case 'total': echo isset($calc['summary']['grand']) ? esc_html(($calc['currency'] ?? '£') . number_format((float)$calc['summary']['grand'],2)) : '—'; break;
            case 'pp': echo isset($calc['summary']['grand_pp']) ? esc_html(($calc['currency'] ?? '£') . number_format((float)$calc['summary']['grand_pp'],2)) : '—'; break;
        }
    }

    public function admin_styles() { ?>
        <style>
            /* Admin metabox quote table header legibility */
            #ppb_quote_box .ppb-table th { white-space: nowrap; padding: 10px 8px; }
            #ppb_quote_box .ppb-table th:nth-child(1){ min-width: 160px; }
            #ppb_quote_box .ppb-table th:nth-child(2){ min-width: 110px; text-align: right; }
            #ppb_quote_box .ppb-table th:nth-child(3){ min-width: 90px; text-align: right; }
            #ppb_quote_box .ppb-table th:nth-child(4){ min-width: 170px; text-align: right; }
            #ppb_quote_box .ppb-table th:nth-child(5){ min-width: 150px; text-align: right; }
            #ppb_quote_box .ppb-table th:nth-child(6){ min-width: 140px; text-align: right; }
            .post-type-ppb_quote #ppb_quote_box .ppb-table th, 
            .post-type-ppb_quote #ppb_quote_box .ppb-table td { font-size:14px; }
            .post-type-ppb_quote #ppb_quote_box h2 { margin-top:0; }
        </style>
    <?php }

    private function inline_css($ui) {
        $primary = esc_html($ui['primary'] ?? '#0f172a');
        $accent = esc_html($ui['accent'] ?? '#2563eb');
        $rc = intval($ui['radius_card'] ?? 14);
        $rf = intval($ui['radius_field'] ?? 10);
        $rb = intval($ui['radius_button'] ?? 10);
        $sp = intval($ui['spacing'] ?? 12);
        $fs = intval($ui['font_size'] ?? 15);
        $bp = intval($ui['mobile_breakpoint'] ?? 900);

        return "
        .ppb-title { font-size: ".($fs+13)."px; margin: 8px 0 16px; }
        .ppb-wrap { max-width: 1100px; margin: 0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; font-size: {$fs}px; }
        .ppb-grid { display: grid; grid-template-columns: 1fr 1fr; gap: ".($sp*2)."px; }
        .ppb-single .ppb-grid { grid-template-columns: 1fr; }
        .ppb-card { background: #fff; border: 1px solid #e5e7eb; border-radius: {$rc}px; padding: ".($sp*1.6)."px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .ppb-card h3 { margin-top: 0; font-size: ".($fs+5)."px; }
        .ppb-wrap label { display: block; margin-bottom: ".($sp+2)."px; font-weight: 600; }
        .ppb-wrap input[type=text], .ppb-wrap input[type=date], .ppb-wrap input[type=number], .ppb-wrap input[type=email], .ppb-wrap input[type=tel], .ppb-wrap select, .ppb-wrap textarea { width: 100%; padding: ".($sp)."px ".($sp+2)."px; border: 1px solid #d1d5db; border-radius: {$rf}px; font-weight: 400; }
        .ppb-row-inline { display: grid; grid-template-columns: 1fr 1fr; gap: {$sp}px; }
        .ppb-inline { display: block; }
        .ppb-fieldset { border: 1px solid #e5e7eb; border-radius: {$rf}px; padding: ".($sp)."px ".($sp+2)."px; margin: {$sp}px 0; }
        .ppb-checkbox { display: flex; align-items: center; gap: ".($sp-2)."px; margin: ".($sp/2)."px 0; font-weight: 500; }
        .ppb-addon-grid { display: grid; grid-template-columns: 1fr; gap: ".($sp/2)."px 0; }
        .ppb-btn { display: inline-block; padding: ".($sp)."px ".($sp+4)."px; background: {$primary}; color: #fff; border-radius: {$rb}px; border: none; cursor: pointer; transition: transform .03s ease, background .2s ease; }
        .ppb-btn:hover { background: {$accent}; }
        .ppb-btn:active { transform: translateY(1px); }
        .ppb-secondary { background: #374151; }
        .ppb-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .ppb-sticky { position: sticky; top: 16px; height: fit-content; }
        .ppb-summary { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: {$rf}px; padding: ".($sp)."px; font-size: ".($fs-1)."px; }
        .ppb-summary .ppb-row { display: flex; justify-content: space-between; margin: 6px 0; }
        .ppb-summary .ppb-muted { color: #6b7280; }
        .ppb-summary .ppb-total { font-weight: 700; border-top: 1px solid #e5e7eb; padding-top: 8px; margin-top: 8px; font-size: ".($fs+1)."px; }
        .ppb-msg { margin-top: 10px; }
        .ppb-steps { margin-bottom: ".($sp/1.5)."px; }
        .ppb-step-indicator { display: flex; gap: ".($sp/2)."px; align-items: center; }
        .ppb-step-dot { width: 28px; height: 28px; border-radius: 50%; background: #e5e7eb; color:#111827; display:flex; align-items:center; justify-content:center; font-weight:700; }
        .ppb-step-dot.ppb-active { background: {$primary}; color:white; }
        .ppb-progress { width: 100%; background: #e5e7eb; border-radius: 9999px; height: 6px; margin-top: 6px; }
        .ppb-progress-bar { height: 6px; background: {$primary}; border-radius: 9999px; width: 33%; transition: width .2s ease; }
        .ppb-step-actions { display:flex; gap:{$sp}px; margin-top: ".($sp/2)."px; }
        .ppb-muted { color:#6b7280; font-size: ".($fs-1)."px; }
        .ppb-mask { border: 1px dashed #d1d5db; padding: ".($sp)."px; border-radius: {$rf}px; color:#6b7280; margin-bottom: {$sp}px; }
        @media (max-width: {$bp}px) { .ppb-grid { grid-template-columns: 1fr; } .ppb-addon-grid { grid-template-columns: 1fr; } .ppb-row-inline { grid-template-columns: 1fr; } }
        ";
    }

    private function inline_js() {
        return <<<'JS'
        (function($){
            const cfg = PPB_CONFIG;
            function fmt(n){ return cfg.currency + Number(n).toFixed(2); }

            function basePPPForDate(d){
                const jsDow = d.getDay(); // 0 Sun..6 Sat
                const dowIndex = ({0:6,1:0,2:1,3:2,4:3,5:4,6:5})[jsDow];
                let base = parseFloat((cfg.pricing?.base_pp_by_dow || [0,0,0,0,0,0,0])[dowIndex] || 0);
                (cfg.pricing?.special_ranges || []).forEach(r=>{
                    if (!r.start || !r.end) return;
                    const ds = d.toISOString().slice(0,10);
                    if (ds >= r.start && ds <= r.end) {
                        if (r.pp_override !== undefined && r.pp_override !== '' && r.pp_override !== null) {
                            base = parseFloat(r.pp_override);
                        } else if (r.multiplier !== undefined && r.multiplier !== '' && r.multiplier !== null) {
                            base = base * parseFloat(r.multiplier);
                        }
                    }
                });
                return base;
            }

            function calculate(form){
                const $form = $(form);
                const guests = parseInt($form.find('[name="guests"]').val(), 10) || 0;
                const billableGuests = cfg.min_room_hire_enabled ? Math.max(guests, parseInt(cfg.min_room_hire_guests, 10) || 0) : guests;
                const arrival = $form.find('[name="arrival_date"]').val();
                const nights = parseInt($form.find('[name="nights"]').val(), 10) || 1;

                let perPersonNightly = 0, fixedNightly = 0, perPersonOnce = 0, fixedOnce = 0;
                if (cfg.drinks_package && cfg.drinks_package.enabled) {
                    fixedOnce += Math.min(500 + Math.max(0, guests-20)*25, 1000);
                }
                const selectedAddons = $form.find('[name="addons[]"]:checked').map(function(){return $(this).val();}).get();
                selectedAddons.forEach(id => {
                    const a = cfg.addons.find(x => x.id === id);
                    if (!a) return;
                    const amount = parseFloat(a.amount);
                    const freq = a.frequency || 'once';
                    if (a.type === 'per_person') {
                        if (freq === 'per_night') perPersonNightly += amount; else perPersonOnce += amount;
                    } else {
                        if (freq === 'per_night') fixedNightly += amount; else fixedOnce += amount;
                    }
                });

                let subtotal = 0;
                const rows = [];
                if (arrival) {
                    try {
                        const start = new Date(arrival + 'T00:00:00');
                        for (let i=0;i<nights;i++){
                            const d = new Date(start); d.setDate(d.getDate()+i);
                            const ppp = basePPPForDate(d);
                            const perPerson = ppp * billableGuests;
                            const ppAddons = perPersonNightly * billableGuests;
                            const fxAddons = fixedNightly;
                            const nightTotal = perPerson + ppAddons + fxAddons;
                            subtotal += nightTotal;
                            rows.push({label: d.toDateString(), ppp, guests, perPersonTotal: perPerson, addons: ppAddons+fxAddons, nightTotal});
                        }
                    } catch(e){ /* ignore */ }
                }
                if (rows.length === 0){
                    const avgBase = (cfg.pricing?.base_pp_by_dow || []).reduce((a,b)=>a+Number(b||0),0)/7 || 0;
                    const perPerson = avgBase * billableGuests * nights;
                    const ppAddons = perPersonNightly * billableGuests * nights;
                    const fxAddons = fixedNightly * nights;
                    subtotal = perPerson + ppAddons + fxAddons;
                }
                subtotal += (perPersonOnce * billableGuests) + fixedOnce;

                const service = subtotal * ((parseFloat(cfg.service_percent)||0)/100);
                const taxable = subtotal + service;
                const vat = cfg.enable_vat ? taxable * ((parseFloat(cfg.vat_percent)||0)/100) : 0;
                const grand = taxable + vat;
                const grandPP = billableGuests>0 ? grand/billableGuests : grand;

                renderSummary($form, rows, {subtotal, service, vat, grand, grandPP});
                updateAddonHints($form);

                handleEstimateMask($form);
            }

            function updateAddonHints($form){
                const guests = parseInt($form.find('[name="guests"]').val(), 10) || 0;
                cfg.addons.forEach(a => {
                    const hint = $form.find(`[data-addon="${a.id}"]`);
                    if (!hint.length) return;
                    if (a.type === 'per_person') {
                        hint.text('+' + fmt(a.amount) + (a.frequency==='per_night'?' per person / night':' per person'));
                    } else if (a.type === 'fixed') {
                        hint.text('+' + fmt(a.amount) + (a.frequency==='per_night'?' per night':' fixed'));
                    }
                });
            }

            function renderSummary($form, nightlyRows, totals){
                const summary = $form.find('#ppb-summary');
                const rows = [];
                if (nightlyRows && nightlyRows.length){
                    nightlyRows.forEach(r=>{ rows.push(row(r.label, fmt(r.nightTotal))); });
                }
                rows.push(row('Subtotal', fmt(totals.subtotal)));
                if (parseFloat(cfg.service_percent)) rows.push(row('Service ' + cfg.service_percent + '%', fmt(totals.service)));
                if (cfg.enable_vat && parseFloat(cfg.vat_percent)) rows.push(row('VAT ' + cfg.vat_percent + '%', fmt(totals.vat)));
                rows.push(`<div class="ppb-row"><span><strong>Total</strong></span><span><strong>${fmt(totals.grand)}</strong></span></div>`);
                rows.push(`<div class="ppb-row"><span class="ppb-muted">Per person</span><span class="ppb-muted">${fmt(totals.grandPP)}</span></div>`);
                summary.html(rows.join(''));
            }
            function row(l, r){ return `<div class="ppb-row"><span>${l}</span><span>${r}</span></div>`; }

            function bindMultiStep(form){
                const $form = $(form);
                const tmpl = $form.data('template');
                if (tmpl !== 'advanced') return;
                let current = 1;
                const max = 3;
                const dots = $form.find('.ppb-step-dot');
                const prog = $form.find('.ppb-progress-bar');
                function go(step){
                    current = Math.min(Math.max(step,1), max);
                    $form.find('.ppb-step').attr('hidden', true);
                    $form.find(`.ppb-step[data-step="${current}"]`).attr('hidden', false);
                    dots.removeClass('ppb-active');
                    dots.filter(`[data-step="${current}"]`).addClass('ppb-active');
                    prog.css('width', (current/max*100) + '%');
                    $('html,body').animate({scrollTop: $form.offset().top - 20}, 150);
                    handleEstimateMask($form);
                }
                function validateStep(step){
                    const $step = $form.find(`.ppb-step[data-step="${step}"]`);
                    let ok = true;
                    $step.find('[required]').each(function(){ if (!this.value) { this.classList.add('ppb-error'); ok = false; } else this.classList.remove('ppb-error'); });
                    return ok;
                }
                $form.on('click', '.ppb-next', function(){ if (validateStep(current)) go(current+1); });
                $form.on('click', '.ppb-back', function(){ go(current-1); });
                go(1);
            }

            function handleEstimateMask($form){
                const mode = ($form.data('estimate-mode') || 'live').toString();
                const summary = $form.find('#ppb-summary');
                const mask = $form.find('#ppb-mask');
                if (mode === 'off') { summary.hide(); mask.show(); }
                else if (mode === 'gated') {
                    if ($form.data('submitted') === true) { summary.show(); mask.hide(); }
                    else { summary.hide(); mask.show(); }
                } else { summary.show(); mask.hide(); }
            }

            $(document).on('change keyup', '#ppb-form input, #ppb-form select, #ppb-form textarea', function(){
                const form = document.getElementById('ppb-form'); if (form) calculate(form);
            });

            $(document).on('submit', '#ppb-form', function(e){
                e.preventDefault();
                const form = this; const $form = $(form);
                $form.find('#ppb-msg').text('');
                const data = $form.serializeArray();
                data.push({name: 'action', value: 'ppb_submit_quote'});
                data.push({name: 'nonce', value: cfg.nonce});
                $('.ppb-btn').prop('disabled', true);
                $.post(cfg.ajax_url, data)
                 .done(res => { 
                    if (res && res.success) { 
                        $form.find('#ppb-msg').text(res.data.message); 
                        $form.data('submitted', true);
                        handleEstimateMask($form);
                        if (($form.data('estimate-mode')||'live') !== 'off') {
                            // keep values so user sees the summary; don't reset form entirely
                        } else {
                            form.reset();
                        }
                        calculate(form);
                    } else { 
                        $form.find('#ppb-msg').text(res && res.data && res.data.message ? res.data.message : 'Sorry. There was a problem.'); 
                    } 
                 })
                 .fail(() => { $form.find('#ppb-msg').text('Sorry. There was a problem.'); })
                 .always(() => { $('.ppb-btn').prop('disabled', false); });
            });

            const form = document.getElementById('ppb-form');
            if (form){ calculate(form); bindMultiStep(form); }
        })(jQuery);
JS;
    }
}

new PartyPlanBuilder();
