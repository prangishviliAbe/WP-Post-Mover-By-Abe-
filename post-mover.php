<?php
/**
 * Plugin Name: [Post mover]
 * Description: გადაადგილე პოსტები Custom Post Type-ებს შორის. შენარჩუნებულია taxonomies და custom fields.
 * Version: 1
 * Author: აბე ფრანგიშვილი
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        '[Post mover]',
        '[Post mover]',
        'manage_options',
        'post-mover',
        'post_mover_page',
        'dashicons-migrate',
        80
    );
});

function post_mover_page() {
    $args = ['public' => true, '_builtin' => false];
    $post_types = get_post_types($args, 'objects');

    $paged = isset($_GET['mover_page']) ? max(1, intval($_GET['mover_page'])) : 1;
    $posts_per_page = 50;

    $selected_from_type = isset($_GET['from_type']) ? sanitize_text_field($_GET['from_type']) : '';
    $posts = [];
    $total_posts = 0;

    if ($selected_from_type) {
        $query = new WP_Query([
            'post_type' => $selected_from_type,
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
            'post_status' => ['publish', 'draft', 'pending'],
        ]);
        $posts = $query->posts;
        $total_posts = $query->found_posts;
        $max_pages = $query->max_num_pages;
    }

    // ფორმის დამუშავება
    if (
        isset($_POST['move_posts']) &&
        isset($_POST['post_mover_nonce']) &&
        wp_verify_nonce($_POST['post_mover_nonce'], 'post_mover_action')
    ) {
        $from = sanitize_text_field($_POST['from_type_hidden']);
        $to = sanitize_text_field($_POST['to_type']);
        $delete_after = (isset($_POST['delete_after']) && $_POST['delete_after'] === '1') ? true : false;
        $post_ids = isset($_POST['selected_posts']) ? array_map('intval', $_POST['selected_posts']) : [];

        if (!empty($post_ids) && $from && $to && $from !== $to) {
            foreach ($post_ids as $post_id) {
                if (get_post_type($post_id) !== $from) continue;

                // დააკოპირეთ პოსტი ახალი ტიპით
                $original_post = get_post($post_id);
                if (!$original_post) continue;

                // ახალი პოსტის შექმნა სხვა ტიპით
                $new_post_args = [
                    'post_author' => $original_post->post_author,
                    'post_date' => $original_post->post_date,
                    'post_date_gmt' => $original_post->post_date_gmt,
                    'post_content' => $original_post->post_content,
                    'post_title' => $original_post->post_title,
                    'post_excerpt' => $original_post->post_excerpt,
                    'post_status' => $original_post->post_status,
                    'post_type' => $to,
                    'comment_status' => $original_post->comment_status,
                    'ping_status' => $original_post->ping_status,
                    'post_password' => $original_post->post_password,
                    'to_ping' => $original_post->to_ping,
                    'menu_order' => $original_post->menu_order,
                    'post_mime_type' => $original_post->post_mime_type,
                    'guid' => '', // გავუშვათ WP-მ დაამატოს
                ];

                $new_post_id = wp_insert_post($new_post_args);

                if ($new_post_id && !is_wp_error($new_post_id)) {
                    // ტაქსონომიების კოპირება
                    $taxonomies = get_object_taxonomies($from);
                    foreach ($taxonomies as $taxonomy) {
                        if (taxonomy_exists($taxonomy) && is_object_in_taxonomy($to, $taxonomy)) {
                            $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
                            wp_set_object_terms($new_post_id, $terms, $taxonomy, false);
                        }
                    }

                    // მეტა-ფილდების კოპირება
                    $custom_fields = get_post_custom($post_id);
                    foreach ($custom_fields as $key => $values) {
                        // ივიწყე _edit_lock და _edit_last თუ გინდა
                        if (in_array($key, ['_edit_lock', '_edit_last'])) continue;

                        foreach ($values as $value) {
                            update_post_meta($new_post_id, $key, maybe_unserialize($value));
                        }
                    }

                    // თუ მონიშნულია წაშლა - წაშალე ორიგინალი პოსტი
                    if ($delete_after) {
                        wp_delete_post($post_id, true);
                    }
                }
            }
            echo '<div class="notice notice-success"><p>✅ შერჩეული პოსტები წარმატებით გადატანილია!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>⚠️ გთხოვ აირჩიო მინიმუმ ერთი პოსტი და სწორი პოსტ ტიპები.</p></div>';
        }
    }
    ?>

    <div class="wrap">
        <h1>[Post mover]</h1>
        <form method="get">
            <input type="hidden" name="page" value="post-mover">
            <table class="form-table">
                <tr>
                    <th><label for="from_type">აირჩიე Post Type</label></th>
                    <td>
                        <select name="from_type" onchange="this.form.submit()" required>
                            <option value="">-- აირჩიე --</option>
                            <?php foreach ($post_types as $key => $pt): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_from_type, $key); ?>>
                                    <?php echo esc_html($pt->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
        </form>

        <?php if (!empty($posts)): ?>
            <form method="post" onsubmit="return confirm('დარწმუნებული ხარ, რომ გსურს გადატანა?');">
                <?php wp_nonce_field('post_mover_action', 'post_mover_nonce'); ?>
                <input type="hidden" name="from_type_hidden" value="<?php echo esc_attr($selected_from_type); ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="to_type">To Post Type</label></th>
                        <td>
                            <select name="to_type" required>
                                <?php foreach ($post_types as $key => $pt): ?>
                                    <?php if ($key !== $selected_from_type): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($pt->label); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>აირჩიე პოსტები</th>
                        <td>
                            <label><input type="checkbox" id="select_all"> მონიშვნა ყველა</label>
                            <div style="max-height:300px; overflow:auto; border:1px solid #ccc; padding:10px;">
                                <?php foreach ($posts as $post): ?>
                                    <label style="display:block;">
                                        <input type="checkbox" class="post-checkbox" name="selected_posts[]" value="<?php echo esc_attr($post->ID); ?>">
                                        <?php echo esc_html($post->post_title) . " (#" . $post->ID . ")"; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <?php
                            if ($total_posts > $posts_per_page) {
                                $max_pages = ceil($total_posts / $posts_per_page);
                                echo '<p>გვერდები: ';
                                for ($i = 1; $i <= $max_pages; $i++) {
                                    $url = admin_url('admin.php?page=post-mover&from_type=' . $selected_from_type . '&mover_page=' . $i);
                                    echo '<a href="' . esc_url($url) . '" style="margin-right:10px;' . ($i === $paged ? 'font-weight:bold;' : '') . '">' . $i . '</a>';
                                }
                                echo '</p>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="delete_after">Yes, delete original posts</label></th>
                        <td><input type="checkbox" name="delete_after" value="1"></td>
                    </tr>
                </table>
                <input type="submit" name="move_posts" class="button button-primary" value="Move Selected Posts">
            </form>
        <?php elseif ($selected_from_type): ?>
            <p>ამ პოსტ ტიპში პოსტები არ მოიძებნა.</p>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('select_all');
            const checkboxes = document.querySelectorAll('.post-checkbox');

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(cb => cb.checked = this.checked);
                });

                checkboxes.forEach(cb => {
                    cb.addEventListener('change', function () {
                        if (!this.checked) {
                            selectAll.checked = false;
                        } else {
                            const allChecked = Array.from(checkboxes).every(c => c.checked);
                            selectAll.checked = allChecked;
                        }
                    });
                });
            }
        });
    </script>
<?php
}
