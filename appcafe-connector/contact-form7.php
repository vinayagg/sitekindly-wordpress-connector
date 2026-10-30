<?php
// Part of the SiteKindly connector. Never loaded outside WordPress.
defined('ABSPATH') || exit;
function appcafe_cf7_available() {
    return function_exists('wpcf7_contact_form') && function_exists('wpcf7_get_current_contact_form') && function_exists('wpcf7_is_mailbox_list') &&
        method_exists('WPCF7_ContactForm', 'get_instance') && method_exists('WPCF7_ContactForm', 'prop') &&
        method_exists('WPCF7_ContactForm', 'is_true') && method_exists('WPCF7_ContactForm', 'in_demo_mode') && method_exists('WPCF7_Mail', 'name');
}

function appcafe_cf7_details($target, $user = null, $check_context = true) {
    $unavailable = new WP_Error('unsupported_form', 'Choose one public page and a Contact Form 7 form you can edit, with one fixed main recipient and no Cc/Bcc.', array('status' => 422));
    if (!appcafe_cf7_available() || !is_array($target) || ($target['resource'] ?? '') !== 'contact_form' ||
        ($target['field'] ?? '') !== 'sender' || !is_int($target['id'] ?? null) || $target['id'] < 1 ||
        !is_int($target['pageId'] ?? null) || $target['pageId'] < 1) return $unavailable;
    $user = $user ?? get_current_user_id();
    $post = get_post($target['id']); $page = get_post($target['pageId']);
    if (!$post || $post->post_type !== 'wpcf7_contact_form' || $post->post_status !== 'publish' || !$page ||
        $page->post_type !== 'page' || $page->post_status !== 'publish' || $page->post_password) return $unavailable;
    if (!user_can($user, 'edit_pages') || !user_can($user, 'wpcf7_edit_contact_form', $post->ID) || !user_can($user, 'edit_post', $page->ID)) {
        return new WP_Error('forbidden_form', 'The connected account cannot edit this form and page.', array('status' => 403));
    }
    $mail = get_post_meta($post->ID, '_mail', true);
    if (!is_array($mail) || !is_string($mail['sender'] ?? null) || !is_string($mail['recipient'] ?? null) || !is_string($mail['additional_headers'] ?? '') ||
        preg_match('/[\x00-\x1f\x7f]/', $mail['sender']) || strlen($mail['sender']) > 500 || strlen($mail['recipient']) > 1000 ||
        preg_match('/(?:^|[\r\n])[ \t]*(?:Cc|Bcc)[ \t]*:/i', $mail['additional_headers'] ?? '')) return $unavailable;
    $recipient = trim($mail['recipient']);
    $recipient_label = $recipient;
    if ($recipient === '[_site_admin_email]') {
        $recipient = (string) get_option('admin_email'); $recipient_label = 'WordPress site administrator';
    }
    if (str_contains($recipient, '[') || preg_match('/[\x00-\x1f\x7f]/', $recipient)) return $unavailable;
    $addresses = wpcf7_is_mailbox_list($recipient);
    if (!is_array($addresses) || count($addresses) !== 1) return $unavailable;
    if (!is_string(get_post_meta($post->ID, '_additional_settings', true))) return $unavailable;
    $previous_form = wpcf7_get_current_contact_form();
    $form = wpcf7_contact_form($post->ID);
    try { $disabled = $form->in_demo_mode() || $form->is_true('skip_mail') || $form->is_true('subscribers_only'); }
    finally { WPCF7_ContactForm::get_instance($previous_form); }
    if ($disabled) return new WP_Error('disabled_form_mail', 'Demo, skipped-mail or subscribers-only forms need a separate review before a sender repair.', array('status' => 422));
    $properties = array();
    foreach (array('_form', '_mail', '_mail_2', '_messages', '_additional_settings', '_locale') as $key) $properties[$key] = get_post_meta($post->ID, $key, true);
    unset($properties['_mail']['sender']);
    $context = array('site' => home_url('/'), 'formId' => $post->ID, 'title' => $post->post_title, 'properties' => $properties,
        'recipient' => $addresses[0], 'pageId' => $page->ID, 'content' => $page->post_content, 'pageMeta' => get_post_meta($page->ID));
    $hash = hash('sha256', serialize($context));
    if ($check_context && (!is_string($target['contextHash'] ?? null) || !hash_equals($hash, $target['contextHash']))) {
        return new WP_Error('stale_form', 'The form, recipient or page changed. Request a fresh compatibility check.', array('status' => 409));
    }
    return array('post' => $post, 'page' => $page, 'mail' => $mail, 'contextHash' => $hash,
        'recipientAddress' => $addresses[0], 'recipientLabel' => $recipient_label);
}

function appcafe_cf7_info($request) {
    $target = $request->get_param('target');
    $details = appcafe_cf7_details($target, null, false);
    if (is_wp_error($details)) return $details;
    $target = array('resource' => 'contact_form', 'id' => $target['id'], 'field' => 'sender', 'pageId' => $target['pageId'], 'contextHash' => $details['contextHash']);
    return appcafe_response(array('target' => $target, 'formTitle' => $details['post']->post_title,
        'value' => $details['mail']['sender'], 'hash' => hash('sha256', $details['mail']['sender']),
        'url' => get_permalink($target['pageId']), 'recipientLabel' => $details['recipientLabel']));
}

function appcafe_cf7_test_context($token, $unused = true) {
    $unavailable = new WP_Error('form_test_unavailable', 'This test expired, was used, or the website changed. Request a fresh test in SiteKindly.', array('status' => 410));
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return $unavailable;
    $name = 'sitekindly_form_test_' . hash('sha256', $token);
    $context = get_option($name);
    if (!is_array($context) || $context['expires'] <= time() || ($unused && get_option($name . '_used'))) return $unavailable;
    $details = appcafe_cf7_details($context['target'], $context['user']);
    if (is_wp_error($details) || !hash_equals($context['afterHash'], hash('sha256', $details['mail']['sender']))) return $unavailable;
    return $context;
}

function appcafe_cf7_test($request) {
    $target = $request->get_param('target'); $token = $request->get_param('testId');
    $after_hash = $request->get_param('afterHash'); $expires = $request->get_param('expiresAt');
    if ($request->get_param('acknowledgeOwnerSubmission') !== true || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token) ||
        !is_string($after_hash) || !preg_match('/^[a-f0-9]{64}$/', $after_hash) || !is_int($expires) ||
        $expires <= time() || $expires > time() + 15 * MINUTE_IN_SECONDS) {
        return new WP_Error('invalid_form_test', 'A test needs explicit owner submission consent and a fixed expiry within fifteen minutes.', array('status' => 400));
    }
    $details = appcafe_cf7_details($target);
    if (is_wp_error($details)) return $details;
    $target = array('resource' => 'contact_form', 'id' => $target['id'], 'field' => 'sender', 'pageId' => $target['pageId'], 'contextHash' => $details['contextHash']);
    if (!hash_equals($after_hash, hash('sha256', $details['mail']['sender']))) return new WP_Error('stale_sender', 'The approved sender changed.', array('status' => 409));
    // The service accepts an exact owner-chosen address; tags are permitted only when restoring an old setting.
    $site_domain = preg_replace('/^www\./i', '', strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
    $sender_domain = strtolower(substr($details['mail']['sender'], strrpos($details['mail']['sender'], '@') + 1));
    if (!is_email($details['mail']['sender']) || $sender_domain !== $site_domain) {
        return new WP_Error('unsupported_sender', 'The delivery test needs one plain sender email address on the website domain.', array('status' => 422));
    }
    $context = array('user' => get_current_user_id(), 'target' => $target, 'afterHash' => $after_hash, 'expires' => $expires,
        'code' => (string) random_int(10000000, 99999999), 'recipientAddress' => $details['recipientAddress']);
    $name = 'sitekindly_form_test_' . hash('sha256', $token);
    if (!add_option($name, $context, '', false)) {
        $existing = appcafe_cf7_test_context($token, false);
        if (is_wp_error($existing) || $existing['target'] !== $target || $existing['afterHash'] !== $after_hash ||
            $existing['user'] !== $context['user'] || $existing['expires'] !== $expires) return new WP_Error('test_conflict', 'This test ID cannot be reused for another request.', array('status' => 409));
        $context = $existing;
    }
    if (!wp_next_scheduled('sitekindly_form_test_cleanup', array($name))) wp_schedule_single_event($expires, 'sitekindly_form_test_cleanup', array($name));
    return appcafe_response(array('testId' => $token, 'expiresAt' => gmdate('c', $expires),
        'receiptHash' => hash('sha256', $token . ':' . $context['code']), 'used' => (bool) get_option($name . '_used'),
        'url' => add_query_arg('sitekindly_form_test', $token, get_permalink($target['pageId'])), 'recipientLabel' => $details['recipientLabel']));
}

add_action('sitekindly_form_test_cleanup', function ($name) {
    if (!is_string($name) || !preg_match('/^sitekindly_form_test_[a-f0-9]{64}$/', $name)) return;
    delete_option($name); delete_option($name . '_used');
});

add_action('init', function () {
    if (!isset($_GET['sitekindly_form_test'])) return;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers(); header('Cache-Control: private, no-store'); header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    $token = $_GET['sitekindly_form_test'];
    $context = appcafe_cf7_test_context($token);
    if (!is_ssl() || isset($_GET['appcafe_preview']) || is_wp_error($context)) wp_die('This test is unavailable. Request a fresh test in SiteKindly.', '', array('response' => 410));
    wp_set_current_user(0); show_admin_bar(false);
    add_action('template_redirect', function () use ($context, $token) {
        if (!is_singular('page') || get_queried_object_id() !== $context['target']['pageId']) wp_die('This page is outside the form test.', '', array('response' => 404));
        remove_action('template_redirect', 'redirect_canonical');
        header('X-SiteKindly-Form-Test: ' . $token);
        $GLOBALS['sitekindly_form_page'] = array('context' => $context, 'token' => $token);
    }, -100);
}, 2);

add_filter('wpcf7_form_hidden_fields', function ($fields) {
    $page = $GLOBALS['sitekindly_form_page'] ?? null;
    $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;
    if ($page && $form && $form->id() === $page['context']['target']['id']) $fields['_sitekindly_test'] = $page['token'];
    return $fields;
});

add_action('wpcf7_before_send_mail', function ($form, &$abort) {
    unset($GLOBALS['sitekindly_form_sending']);
    if (!isset($_POST['_sitekindly_test'])) return;
    $token = wp_unslash($_POST['_sitekindly_test']);
    $context = appcafe_cf7_test_context($token);
    if (!is_ssl() || $abort || is_wp_error($context) || $form->id() !== $context['target']['id']) { $abort = true; return; }
    $mail = get_post_meta($form->id(), '_mail', true);
    if ($form->prop('mail') !== $mail) { $abort = true; return; }
    // This hook runs after normal field, acceptance and spam validation. Failed validation may be retried.
    $name = 'sitekindly_form_test_' . hash('sha256', $token) . '_used';
    if (!add_option($name, true, '', false)) { $abort = true; return; }
    $GLOBALS['sitekindly_form_sending'] = $context;
}, PHP_INT_MAX, 2);

add_filter('wpcf7_mail_components', function ($components, $form, $mail) {
    $context = $GLOBALS['sitekindly_form_sending'] ?? null;
    if (!$context || $form->id() !== $context['target']['id'] || $mail->name() !== 'mail') return $components;
    $addresses = wpcf7_is_mailbox_list($components['recipient'] ?? '');
    if (!is_array($addresses) || count($addresses) !== 1 || strcasecmp($addresses[0], $context['recipientAddress']) ||
        preg_match('/(?:^|[\r\n])[ \t]*(?:Cc|Bcc)[ \t]*:/i', $components['additional_headers'] ?? '')) {
        // Refuse dynamic rerouting instead of putting a verification code in an unapproved email.
        return array_merge($components, array('recipient' => '', 'additional_headers' => '', 'body' => '', 'attachments' => array()));
    }
    $proof = 'SiteKindly delivery confirmation: ' . $context['code'];
    $properties = $form->prop('mail');
    $components['body'] .= !empty($properties['use_html']) ? '<p>' . esc_html($proof) . '</p>' : "\n\n" . $proof;
    return $components;
}, PHP_INT_MAX, 3);
