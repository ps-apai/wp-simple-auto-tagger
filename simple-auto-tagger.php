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
    ?>
    <div class="wrap">
        <h1>Simple Auto Tagger</h1>
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
    </div>
    <?php
}
