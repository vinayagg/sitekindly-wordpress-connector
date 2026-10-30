<?php
/**
 * Plugin Name: SiteKindly Website Care Connector
 * Description: Scoped snapshots and checked, recoverable content changes for owner-approved SiteKindly repairs.
 * Version: 0.4.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/contact-form7.php';

function appcafe_permission() {
    return is_ssl() && is_user_logged_in() && current_user_can('edit_pages');
}
function appcafe_response($value) {
    $response = new WP_REST_Response($value);
    $response->header('Cache-Control', 'private, no-store');
    return $response;
}
function appcafe_transactional_tables() {
    global $wpdb;
    foreach (array($wpdb->posts, $wpdb->postmeta) as $table) {
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        if (strtoupper((string) $engine) !== 'INNODB') return false;
    }
    return true;
}
function appcafe_target($target) {
    if (!is_array($target) || !isset($target['id'], $target['resource'], $target['field']) ||
        !is_int($target['id']) || $target['id'] < 1) {
        return new WP_Error('invalid_target', 'Invalid content target.', array('status' => 400));
    }
    if (($target['resource'] ?? '') === 'contact_form') {
        $details = appcafe_cf7_details($target);
        return is_wp_error($details) ? $details : $details['post'];
    }
    $post = get_post($target['id']);
    if (!$post || !current_user_can('edit_post', $post->ID)) {
        return new WP_Error('forbidden_target', 'This content is unavailable to the connected user.', array('status' => 403));
    }
    $page = $target['resource'] === 'page' && $post->post_type === 'page' && $post->post_status === 'publish' &&
        in_array($target['field'], array('title', 'content'), true);
    $media = $target['resource'] === 'media' && $post->post_type === 'attachment' && wp_attachment_is_image($post->ID) &&
        $target['field'] === 'alt_text' && (!$post->post_parent || get_post_status($post->post_parent) === 'publish');
    if (!$page && !$media) return new WP_Error('unsupported_target', 'This content type is outside the connector scope.', array('status' => 422));
    return $post;
}
function appcafe_value($target, $post) {
    if (($target['resource'] ?? '') === 'contact_form') {
        $mail = get_post_meta($post->ID, '_mail', true);
        return (string) ($mail['sender'] ?? '');
    }
    if ($target['field'] === 'alt_text') return (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true);
    return (string) ($target['field'] === 'title' ? $post->post_title : $post->post_content);
}
function appcafe_add_form_label($html, $id, $label) {
    if (!is_string($html) || strlen($html) > 120000 || !is_string($id) ||
        !preg_match('/^[A-Za-z][A-Za-z0-9_:.-]{0,199}$/', $id) || !is_string($label) ||
        !trim($label) || strlen($label) > 200 || preg_match('/[<>\x00-\x1f]/', $label)) {
        return new WP_Error('invalid_label', 'Choose one field ID and a short, plain-text label.', array('status' => 422));
    }
    $tags = new WP_HTML_Tag_Processor($html);
    $matches = 0; $label_depth = 0;
    while ($tags->next_tag(array('tag_closers' => 'visit'))) {
        $tag = $tags->get_tag();
        if ($tag === 'LABEL') {
            $label_depth = max(0, $label_depth + ($tags->is_tag_closer() ? -1 : 1));
            if (!$tags->is_tag_closer() && $tags->get_attribute('for') === $id) {
                return new WP_Error('existing_label', 'This field already has a label association.', array('status' => 422));
            }
        }
        if ($tags->is_tag_closer() || $tags->get_attribute('id') !== $id) continue;
        $matches++;
        $type = strtolower((string) $tags->get_attribute('type'));
        if (!in_array($tag, array('INPUT', 'SELECT', 'TEXTAREA'), true) || $label_depth ||
            ($tag === 'INPUT' && !in_array($type, array('', 'text', 'email', 'tel', 'url', 'search', 'number'), true)) ||
            $tags->get_attribute('hidden') !== null || $tags->get_attribute('aria-hidden') === 'true' ||
            trim((string) $tags->get_attribute('aria-label')) || trim((string) $tags->get_attribute('aria-labelledby')) ||
            trim((string) $tags->get_attribute('title'))) {
            return new WP_Error('unsupported_label', 'Choose one visible field without an existing label.', array('status' => 422));
        }
        $tags->set_attribute('aria-label', $label);
    }
    if ($matches !== 1) return new WP_Error('ambiguous_field', 'The field ID must occur exactly once in editable page content.', array('status' => 422));
    return $tags->get_updated_html();
}
function appcafe_bounded_form_label_change($before, $after) {
    $fields = array();
    foreach (array($before, $after) as $html) {
        $tags = new WP_HTML_Tag_Processor($html); $labels = array();
        while ($tags->next_tag()) {
            $id = $tags->get_attribute('id');
            if (is_string($id) && in_array($tags->get_tag(), array('INPUT', 'SELECT', 'TEXTAREA'), true)) {
                $labels[$id] = (string) $tags->get_attribute('aria-label');
            }
        }
        $fields[] = $labels;
    }
    foreach ($fields[0] as $id => $label) {
        if (!array_key_exists($id, $fields[1])) continue;
        $next = $fields[1][$id];
        if (!trim($label) && trim($next)) {
            $expected = appcafe_add_form_label($before, $id, $next);
            if (is_string($expected) && $expected === $after) return true;
        }
        // Recovery restores the exact original bytes by reversing the same bounded addition.
        if (trim($label) && !trim($next)) {
            $expected = appcafe_add_form_label($after, $id, $label);
            if (is_string($expected) && $expected === $before) return true;
        }
    }
    return false;
}
function appcafe_form_label_preview($request) {
    $target = $request->get_param('target');
    $post = appcafe_target($target);
    if (is_wp_error($post)) return $post;
    if ($target['resource'] !== 'page' || $target['field'] !== 'content') {
        return new WP_Error('unsupported_target', 'A form label must belong to editable page content.', array('status' => 422));
    }
    $hash = $request->get_param('beforeHash');
    if (!is_string($hash) || !hash_equals(hash('sha256', $post->post_content), $hash)) {
        return new WP_Error('stale_content', 'The page content changed. Read a fresh snapshot.', array('status' => 409));
    }
    $after = appcafe_add_form_label($post->post_content, $request->get_param('controlId'), $request->get_param('label'));
    if (is_wp_error($after)) return $after;
    if ($after !== wp_kses_post($after) && !current_user_can('unfiltered_html')) {
        return new WP_Error('unsupported_permissions', 'The connected account cannot preserve this page markup. This field needs a separate integration.', array('status' => 422));
    }
    return appcafe_response(array('after' => $after));
}
function appcafe_snapshot($request) {
    $targets = $request->get_param('targets');
    if (!is_array($targets) || count($targets) < 1 || count($targets) > 3) {
        return new WP_Error('target_limit', 'Choose one to three content fields.', array('status' => 400));
    }
    $items = array();
    foreach ($targets as $target) {
        $post = appcafe_target($target);
        if (is_wp_error($post)) return $post;
        $value = appcafe_value($target, $post);
        $item = array('resource' => $target['resource'], 'id' => $post->ID, 'field' => $target['field'],
            'value' => $value, 'hash' => hash('sha256', $value), 'url' => get_permalink($post->ID));
        if ($target['resource'] === 'contact_form') {
            $item['pageId'] = $target['pageId']; $item['contextHash'] = $target['contextHash'];
            $item['url'] = get_permalink($target['pageId']);
        }
        $items[] = $item;
    }
    return appcafe_response(array('items' => $items));
}
function appcafe_patch($request) {
    global $wpdb;
    $changes = $request->get_param('changes');
    if (!is_array($changes) || count($changes) < 1 || count($changes) > 3) {
        return new WP_Error('change_limit', 'Choose one to three content changes.', array('status' => 400));
    }
    if (!appcafe_transactional_tables()) return new WP_Error('unsupported_storage', 'Transactional storage is required.', array('status' => 422));
    $ids = array(); $seen = array();
    foreach ($changes as $change) {
        $post = appcafe_target($change);
        if (is_wp_error($post)) return $post;
        if (!isset($change['beforeHash'], $change['after']) || !is_string($change['beforeHash']) ||
            !preg_match('/^[a-f0-9]{64}$/', $change['beforeHash']) || !is_string($change['after']) ||
            strlen($change['after']) > 120000) {
            return new WP_Error('invalid_change', 'Invalid content change.', array('status' => 400));
        }
        $key = $change['resource'] . ':' . $change['id'] . ':' . $change['field'];
        if (isset($seen[$key])) return new WP_Error('duplicate_target', 'Each field can appear only once.', array('status' => 400));
        $seen[$key] = true;
        if ($change['resource'] === 'contact_form' && (!trim($change['after']) || strlen($change['after']) > 500 || preg_match('/[\x00-\x1f\x7f]/', $change['after']))) {
            return new WP_Error('invalid_sender', 'Use a single-line sender value without control characters.', array('status' => 400));
        }
        if ($change['field'] !== 'content' && $change['resource'] !== 'contact_form' && ($change['after'] !== wp_strip_all_tags($change['after']) || strlen($change['after']) > 1000)) {
            return new WP_Error('invalid_text', 'Titles and image alternatives must be plain text.', array('status' => 400));
        }
        if ($change['field'] === 'content' && $post->post_content !== $change['after'] && $change['after'] !== wp_kses_post($change['after']) &&
            !appcafe_bounded_form_label_change($post->post_content, $change['after'])) {
            return new WP_Error('unsafe_markup', 'The content contains unsupported markup.', array('status' => 422));
        }
        $ids[] = $post->ID;
        if ($change['resource'] === 'contact_form') $ids[] = $change['pageId'];
    }
    foreach ($changes as $change) if ($change['resource'] === 'contact_form') {
        foreach ($changes as $other) if ($other['resource'] === 'page' && $other['id'] === $change['pageId']) {
            return new WP_Error('conflicting_form_page', 'Review form delivery separately from changes to its public page.', array('status' => 422));
        }
    }
    $ids = array_values(array_unique($ids)); sort($ids);
    if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ||
        $wpdb->query('START TRANSACTION') === false) {
        return new WP_Error('transaction_unavailable', 'A checked transaction could not begin.', array('status' => 503));
    }
    try {
        // Lock in a stable order. Recheck the actual field after the lock, before any write.
        foreach ($ids as $id) {
            $wpdb->get_row($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id));
            // Lock the metadata range too, including the insertion gap for a missing alt value.
            $wpdb->get_results($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $id));
            clean_post_cache($id);
        }
        foreach ($changes as $change) {
            $post = appcafe_target($change);
            if (is_wp_error($post)) throw new RuntimeException('stale');
            $current = appcafe_value($change, $post);
            if (!hash_equals($change['beforeHash'], hash('sha256', $current)) && $current !== $change['after']) {
                throw new RuntimeException('stale');
            }
        }
        foreach ($changes as $change) {
            $current = appcafe_value($change, get_post($change['id']));
            if ($current === $change['after']) continue; // Safe retry after a lost response.
            if ($change['resource'] === 'contact_form') {
                $mail = get_post_meta($change['id'], '_mail', true);
                $mail['sender'] = $change['after'];
                $saved = update_post_meta($change['id'], '_mail', wp_slash($mail));
                if ($saved === false) throw new RuntimeException('write_failed');
            } elseif ($change['field'] === 'alt_text') {
                $saved = update_post_meta($change['id'], '_wp_attachment_image_alt', wp_slash($change['after']));
                if ($saved === false) throw new RuntimeException('write_failed');
            } else {
                $field = $change['field'] === 'title' ? 'post_title' : 'post_content';
                $saved = wp_update_post(wp_slash(array('ID' => $change['id'], $field => $change['after'])), true);
                if (is_wp_error($saved) || !$saved) throw new RuntimeException('write_failed');
            }
            clean_post_cache($change['id']);
            if (appcafe_value($change, get_post($change['id'])) !== $change['after']) throw new RuntimeException('verification_failed');
        }
        if ($wpdb->query('COMMIT') === false) throw new RuntimeException('commit_failed');
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        foreach ($ids as $id) clean_post_cache($id);
        return new WP_Error('patch_not_applied', 'The change could not be applied as reviewed. Refresh the website snapshot.', array('status' => 409));
    }
    foreach ($ids as $id) clean_post_cache($id);
    $warnings_refreshed = true;
    foreach ($changes as $change) if ($change['resource'] === 'contact_form' && class_exists('WPCF7_ConfigValidator')) {
        // Refresh derived warnings after commit; never re-save the form's other properties.
        try {
            $validator = new WPCF7_ConfigValidator(wpcf7_contact_form($change['id']));
            $validator->validate(); $validator->save();
        } catch (Throwable $error) { $warnings_refreshed = false; }
    }
    return appcafe_response(array('applied' => true, 'count' => count($changes), 'configurationWarningsRefreshed' => $warnings_refreshed));
}
function appcafe_render_preview($request) {
    $changes = $request->get_param('changes');
    $preview_id = $request->get_param('previewId');
    if (!is_string($preview_id) || !preg_match('/^[a-f0-9]{64}$/', $preview_id) || !is_array($changes) || count($changes) < 1 || count($changes) > 3) {
        return new WP_Error('invalid_preview', 'Choose one to three page fields and a repair fingerprint.', array('status' => 400));
    }
    $saved = array(); $pages = array(); $seen = array();
    foreach ($changes as $change) {
        $post = appcafe_target($change);
        if (is_wp_error($post)) return $post;
        if ($change['resource'] !== 'page' || $post->post_password) return new WP_Error('unsupported_preview', 'Only public, unprotected pages support a layout preview.', array('status' => 422));
        if (!isset($change['beforeHash'], $change['after']) || !is_string($change['beforeHash']) || !preg_match('/^[a-f0-9]{64}$/', $change['beforeHash']) || !is_string($change['after']) || strlen($change['after']) > 120000) {
            return new WP_Error('invalid_change', 'Invalid preview content.', array('status' => 400));
        }
        $before = appcafe_value($change, $post);
        if (!hash_equals(hash('sha256', $before), $change['beforeHash'])) return new WP_Error('stale_content', 'The page changed. Prepare a fresh repair.', array('status' => 409));
        $key = $post->ID . ':' . $change['field'];
        if (isset($seen[$key])) return new WP_Error('duplicate_target', 'Each field can appear only once.', array('status' => 400));
        $seen[$key] = true;
        if ($change['field'] === 'title' && ($change['after'] !== wp_strip_all_tags($change['after']) || strlen($change['after']) > 1000)) return new WP_Error('invalid_text', 'Titles must be plain text.', array('status' => 400));
        if ($change['field'] === 'content' && $change['after'] !== wp_kses_post($change['after']) && !appcafe_bounded_form_label_change($before, $change['after'])) return new WP_Error('unsafe_markup', 'The content contains unsupported markup.', array('status' => 422));
        $saved[] = array('id' => $post->ID, 'field' => $change['field'], 'beforeHash' => $change['beforeHash'], 'after' => $change['after']);
        $pages[$post->ID] = get_permalink($post->ID);
    }
    $token = bin2hex(random_bytes(32)); $expires = time() + 15 * MINUTE_IN_SECONDS;
    $context = array('user' => get_current_user_id(), 'changes' => $saved, 'expires' => $expires, 'previewId' => $preview_id);
    if (!set_transient('appcafe_preview_' . hash('sha256', $token), $context, 15 * MINUTE_IN_SECONDS)) return new WP_Error('preview_unavailable', 'The private preview could not be saved.', array('status' => 503));
    $links = array();
    foreach ($pages as $id => $url) $links[] = array('id' => $id, 'url' => $url,
        'beforeUrl' => add_query_arg(array('appcafe_preview' => $token, 'appcafe_view' => 'before'), $url),
        'afterUrl' => add_query_arg(array('appcafe_preview' => $token, 'appcafe_view' => 'after'), $url));
    return appcafe_response(array('previewId' => $preview_id, 'expiresAt' => gmdate('c', $expires), 'pages' => $links));
}
function appcafe_preview_context($token, $view) {
    $unavailable = new WP_Error('preview_unavailable', 'This preview expired or the website changed. Request a fresh preview.', array('status' => 410));
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token) || !in_array($view, array('before', 'after'), true)) return $unavailable;
    $context = get_transient('appcafe_preview_' . hash('sha256', $token));
    if (!is_array($context) || $context['expires'] <= time() || !user_can($context['user'], 'edit_pages')) return $unavailable;
    foreach ($context['changes'] as $change) {
        $post = get_post($change['id']);
        if (!$post || $post->post_type !== 'page' || $post->post_status !== 'publish' || $post->post_password || !user_can($context['user'], 'edit_post', $post->ID) || !hash_equals($change['beforeHash'], hash('sha256', appcafe_value($change, $post)))) return $unavailable;
    }
    return $context;
}
function appcafe_preview_posts($posts, $changes, $view) {
    if ($view !== 'after') return $posts;
    foreach ($posts as $index => $post) {
        $copy = clone $post;
        foreach ($changes as $change) if ($copy->ID === $change['id']) $copy->{$change['field'] === 'title' ? 'post_title' : 'post_content'} = $change['after'];
        $posts[$index] = $copy;
    }
    return $posts;
}
function appcafe_inert_preview_html($html) {
    $tags = new WP_HTML_Tag_Processor($html);
    while ($tags->next_tag()) {
        if ($tags->get_tag() === 'BODY') $tags->set_attribute('inert', true);
        if ($tags->get_tag() === 'META' && strtolower((string) $tags->get_attribute('http-equiv')) === 'refresh') {
            $tags->remove_attribute('http-equiv'); $tags->remove_attribute('content');
        }
    }
    return $tags->get_updated_html();
}
add_action('init', function () {
    if (!isset($_GET['appcafe_preview'])) return;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers(); header('Cache-Control: private, no-store'); header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    $ancestors = 'https://sitekindly.appcafe.com https://appcafe-care-staging.appcafe.workers.dev';
    if (wp_get_environment_type() === 'local') $ancestors .= ' http://127.0.0.1:8787';
    $policy = "sandbox; default-src 'none'; script-src 'none'; style-src 'unsafe-inline' https:; img-src https: data:; font-src https: data:; form-action 'none'; base-uri 'self'; frame-ancestors " . $ancestors;
    header('Content-Security-Policy: ' . $policy);
    $view = $_GET['appcafe_view'] ?? '';
    $context = appcafe_preview_context($_GET['appcafe_preview'], $view);
    if (!is_ssl() || is_wp_error($context)) wp_die('This preview is unavailable. Request a fresh preview from SiteKindly.', '', array('response' => 410));
    wp_set_current_user(0); show_admin_bar(false);
    // Render clones through the actual theme; never save an autosave, revision or changed post.
    add_filter('the_posts', function ($posts) use ($context, $view) { return appcafe_preview_posts($posts, $context['changes'], $view); }, PHP_INT_MAX);
    add_filter('the_title', function ($title, $id) use ($context, $view) {
        if ($view === 'after') foreach ($context['changes'] as $change) if ($change['id'] === $id && $change['field'] === 'title') return $change['after'];
        return $title;
    }, PHP_INT_MAX, 2);
    add_action('template_redirect', function () use ($context, $view, $policy) {
        $ids = array_column($context['changes'], 'id');
        if (!is_singular('page') || !in_array(get_queried_object_id(), $ids, true)) wp_die('This page is outside the preview.', '', array('response' => 404));
        remove_action('template_redirect', 'redirect_canonical');
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: ' . $policy);
        header('X-SiteKindly-Preview: ' . $context['previewId'] . ':' . $view);
        ob_start('appcafe_inert_preview_html');
    }, -100);
}, 1);
add_action('rest_api_init', function () {
    register_rest_route('appcafe/v1', '/status', array(
        'methods' => 'GET', 'permission_callback' => 'appcafe_permission',
        'callback' => function () { return appcafe_response(array('version' => '0.4.0', 'atomicPatch' => appcafe_transactional_tables(), 'canEdit' => current_user_can('edit_pages'), 'maxChanges' => 3, 'formLabelPreview' => true, 'renderedPreview' => true, 'formSenderDelivery' => appcafe_cf7_available())); },
    ));
    register_rest_route('appcafe/v1', '/snapshot', array('methods' => 'POST', 'permission_callback' => 'appcafe_permission', 'callback' => 'appcafe_snapshot'));
    register_rest_route('appcafe/v1', '/label-preview', array('methods' => 'POST', 'permission_callback' => 'appcafe_permission', 'callback' => 'appcafe_form_label_preview'));
    register_rest_route('appcafe/v1', '/render-preview', array('methods' => 'POST', 'permission_callback' => 'appcafe_permission', 'callback' => 'appcafe_render_preview'));
    register_rest_route('appcafe/v1', '/patch', array('methods' => 'POST', 'permission_callback' => 'appcafe_permission', 'callback' => 'appcafe_patch'));
    register_rest_route('appcafe/v1', '/form-info', array('methods' => 'POST', 'permission_callback' => 'appcafe_permission', 'callback' => 'appcafe_cf7_info'));
    register_rest_route('appcafe/v1', '/form-test', array('methods' => 'POST', 'permission_callback' => 'appcafe_permission', 'callback' => 'appcafe_cf7_test'));
});
