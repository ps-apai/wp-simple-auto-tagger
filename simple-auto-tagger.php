<?php
/**
 * Plugin Name: Simple Auto Tagger
 * Description: Automatically adds a tag to posts when configured whole-word triggers are found in the title and/or content.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

function sat_get_options() {
    $defaults = array(
        'title_trigger'   => '',
        'content_trigger' => '',
        'tag_slug'        => 'review',
    );

    $opts = get_option('sat_options', array());
    if (!is_array($opts)) {
        $opts = array();
    }

    return array_merge($defaults, $opts);
}

function sat_title_has_trigger($title, $trigger) {
    $trigger = trim((string) $trigger);
    if ($trigger === '') return false;

    // Whole-word, case-insensitive match.
    $pattern = '/\b' . preg_quote($trigger, '/') . '\b/i';
    return (bool) preg_match($pattern, (string) $title);
}

function sat_content_has_trigger($content, $trigger) {
    $trigger = trim((string) $trigger);
    if ($trigger === '') return false;

    // Whole-word, case-insensitive match.
    $pattern = '/\b' . preg_quote($trigger, '/') . '\b/i';
    return (bool) preg_match($pattern, (string) $content);
}

function sat_ensure_tag_exists($tag_slug) {
    $tag_slug = sanitize_title((string) $tag_slug);
    if ($tag_slug === '') return;

    if (!term_exists($tag_slug, 'post_tag')) {
        wp_insert_term($tag_slug, 'post_tag', array('slug' => $tag_slug));
    }
}

function sat_maybe_tag_post_on_save($post_id, $post, $update) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    if (!($post instanceof WP_Post)) return;
    if ($post->post_type !== 'post') return;
    if (!current_user_can('edit_post', $post_id)) return;

    $opts = sat_get_options();

    $tag_slug = sanitize_title($opts['tag_slug']);
    if ($tag_slug === '') return;

    $title_trigger = (string) $opts['title_trigger'];
    $content_trigger = (string) $opts['content_trigger'];

    $matched = false;

    if (sat_title_has_trigger($post->post_title, $title_trigger)) {
        $matched = true;
    }

    // Use post_content from $post (already provided) to avoid extra DB reads.
    if (!$matched && sat_content_has_trigger($post->post_content, $content_trigger)) {
        $matched = true;
    }

    if (!$matched) return;

    sat_ensure_tag_exists($tag_slug);

    // Add tag without removing existing tags.
    wp_set_post_terms($post_id, array($tag_slug), 'post_tag', true);
}
add_action('save_post', 'sat_maybe_tag_post_on_save', 10, 3);

function sat_register_settings() {
    register_setting('sat_settings', 'sat_options', array(
        'type'              => 'array',
        'sanitize_callback' => 'sat_sanitize_options',
        'default'           => array(),
    ));
}
add_action('admin_init', 'sat_register_settings');

function sat_sanitize_options($opts) {
    if (!is_array($opts)) {
        $opts = array();
    }

    $clean = array();
    $clean['title_trigger'] = isset($opts['title_trigger']) ? sanitize_text_field(wp_unslash($opts['title_trigger'])) : '';
    $clean['content_trigger'] = isset($opts['content_trigger']) ? sanitize_text_field(wp_unslash($opts['content_trigger'])) : '';
    $clean['tag_slug'] = isset($opts['tag_slug']) ? sanitize_title(wp_unslash($opts['tag_slug'])) : '';

    return $clean;
}

function sat_add_settings_page() {
    add_options_page(
        'Simple Auto Tagger',
        'Simple Auto Tagger',
        'manage_options',
        'simple-auto-tagger',
        'sat_render_settings_page'
    );
}
add_action('admin_menu', 'sat_add_settings_page');

function sat_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $opts = sat_get_options();
    $backfill_url = wp_nonce_url(
        admin_url('admin-post.php?action=sat_backfill'),
        'sat_backfill',
        'sat_nonce'
    );
    ?>
    <div class="wrap">
        <h1>Simple Auto Tagger</h1>

        <?php if (!empty($_GET['sat_backfill_done'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Backfill complete.</p>
            </div>
        <?php elseif (!empty($_GET['sat_backfill_progress'])) : ?>
            <div class="notice notice-info is-dismissible">
                <p>Backfill in progress...</p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('sat_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sat_title_trigger">Title trigger (whole word)</label></th>
                    <td>
                        <input type="text" id="sat_title_trigger" name="sat_options[title_trigger]" value="<?php echo esc_attr($opts['title_trigger']); ?>" class="regular-text" />
                        <p class="description">If set, the tag is applied when this whole word appears in the post title.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sat_content_trigger">Content trigger (whole word)</label></th>
                    <td>
                        <input type="text" id="sat_content_trigger" name="sat_options[content_trigger]" value="<?php echo esc_attr($opts['content_trigger']); ?>" class="regular-text" />
                        <p class="description">If set, the tag is applied when this whole word appears in the post content.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sat_tag_slug">Tag slug to apply</label></th>
                    <td>
                        <input type="text" id="sat_tag_slug" name="sat_options[tag_slug]" value="<?php echo esc_attr($opts['tag_slug']); ?>" class="regular-text" />
                        <p class="description">Example: <code>review</code>. The tag will be created automatically if it doesn’t exist.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr />

        <h2>Backfill existing posts</h2>
        <p>Runs the same rules against existing posts and applies the configured tag. This can take a while on large sites and runs in batches.</p>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url($backfill_url); ?>" onclick="return confirm('Run backfill on existing posts?');">Run backfill now</a>
        </p>
    </div>
    <?php
}

function sat_handle_backfill_request() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    $nonce = isset($_GET['sat_nonce']) ? sanitize_text_field(wp_unslash($_GET['sat_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'sat_backfill')) {
        wp_die('Invalid nonce.');
    }

    $opts = sat_get_options();
    $tag_slug = sanitize_title($opts['tag_slug']);
    if ($tag_slug === '') {
        wp_die('Tag slug is required.');
    }

    $title_trigger = (string) $opts['title_trigger'];
    $content_trigger = (string) $opts['content_trigger'];

    if (trim($title_trigger) === '' && trim($content_trigger) === '') {
        wp_die('Set at least one trigger before running backfill.');
    }

    sat_ensure_tag_exists($tag_slug);

    $paged = isset($_GET['sat_paged']) ? max(1, (int) $_GET['sat_paged']) : 1;
    $per_page = 200;

    $q = new WP_Query(array(
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'fields'         => 'ids',
        'no_found_rows'  => false,
    ));

    foreach ($q->posts as $post_id) {
        $p = get_post($post_id);
        if (!($p instanceof WP_Post)) {
            continue;
        }

        $matched = false;
        if (sat_title_has_trigger($p->post_title, $title_trigger)) {
            $matched = true;
        }
        if (!$matched && sat_content_has_trigger($p->post_content, $content_trigger)) {
            $matched = true;
        }

        if ($matched) {
            wp_set_post_terms($post_id, array($tag_slug), 'post_tag', true);
        }
    }

    $settings_url = admin_url('options-general.php?page=simple-auto-tagger');
    $max_pages = (int) $q->max_num_pages;

    if ($paged < $max_pages) {
        $next_url = add_query_arg(
            array(
                'action'               => 'sat_backfill',
                'sat_nonce'            => $nonce,
                'sat_paged'            => $paged + 1,
                'sat_backfill_progress' => 1,
            ),
            admin_url('admin-post.php')
        );
        wp_safe_redirect($next_url);
        exit;
    }

    $done_url = add_query_arg(array('sat_backfill_done' => 1), $settings_url);
    wp_safe_redirect($done_url);
    exit;
}
add_action('admin_post_sat_backfill', 'sat_handle_backfill_request');
