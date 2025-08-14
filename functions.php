<?php

// Defines
define('FL_CHILD_THEME_DIR', get_stylesheet_directory());
define('FL_CHILD_THEME_URL', get_stylesheet_directory_uri());

// Classes
require_once 'classes/class-fl-child-theme.php';

// Actions
add_action('wp_enqueue_scripts', 'FLChildTheme::enqueue_scripts', 1000);



function add_to_head()
{
    echo '<meta name="google-site-verification" content="LMGwQ8xVKNzuN0wLXuWktud09ViNeMXDBkYLTCdAts8" />';
}
add_action('wp_head', 'add_to_head');



function set_custom_edit_schoenen_columns($columns)
{
    unset($columns['date']);
    $columns['afbeelding_url'] = 'Afbeelding';
    $columns['merk'] = 'Merk';
    $columns['groep'] = 'Groep';
    $columns['schoentype'] = 'Schoenntype';
    $columns['seizoen'] = 'Seizoen';


    return $columns;
}
add_filter('manage_schoenen_posts_columns', 'set_custom_edit_schoenen_columns');

function custom_schoenen_column($column, $post_id)
{
    switch ($column) {

        case 'afbeelding_url':
            $afbeelding = get_field('afbeelding_url', $post_id);
            echo '<img style="width:90px;height:60px;object-fit:cover;" src="' . $afbeelding . '" alt="" />';
            break;

        case 'merk':
            $merk = get_the_terms($post_id, 'merken');
            echo $merk[0]->name;
            break;

        case 'groep':
            $groep = get_the_terms($post_id, 'groepen');
            echo $groep[0]->name;
            break;

        case 'schoentype':
            $schoentype = get_the_terms($post_id, 'schoentypes');
            echo $schoentype[0]->name;
            break;

        case 'seizoen':
            $seizoen = get_the_terms($post_id, 'seizoenen');
            echo $seizoen[0]->name;
            break;
    }
}

// Add the data to the custom columns for the schoenen post type:
add_action('manage_schoenen_posts_custom_column', 'custom_schoenen_column', 10, 2);



//Add filter to schoenen post type
function filter_schoenen_by_taxonomies($post_type, $which)
{

    // Apply this only on a specific post type
    if ('schoenen' !== $post_type)
        return;

    // A list of taxonomy slugs to filter by
    $taxonomies = array('merken', 'groepen', 'schoentypes', 'seizoenen');

    foreach ($taxonomies as $taxonomy_slug) {

        // Retrieve taxonomy data
        $taxonomy_obj = get_taxonomy($taxonomy_slug);
        $taxonomy_name = $taxonomy_obj->labels->name;

        // Retrieve taxonomy terms
        $terms = get_terms($taxonomy_slug);

        // Display filter HTML
        echo "<select name='{$taxonomy_slug}' id='{$taxonomy_slug}' class='postform'>";
        echo '<option value="">' . sprintf(esc_html__('Show All %s', 'text_domain'), $taxonomy_name) . '</option>';
        foreach ($terms as $term) {
            printf(
                '<option value="%1$s" %2$s>%3$s (%4$s)</option>',
                $term->slug,
                ((isset($_GET[$taxonomy_slug]) && ($_GET[$taxonomy_slug] == $term->slug)) ? ' selected="selected"' : ''),
                $term->name,
                $term->count
            );
        }
        echo '</select>';
    }
}
add_action('restrict_manage_posts', 'filter_schoenen_by_taxonomies', 10, 2);






add_action('pre_get_posts', 'uabb_filter_posts_by_taxonomy');

function uabb_filter_posts_by_taxonomy($query)
{
    if (! is_admin() && $query->is_main_query() && is_post_type_archive('post')) {
        if (isset($_GET['maten']) && ! empty($_GET['maten'])) {
            $query->set('tax_query', array(
                array(
                    'taxonomy' => 'maten',
                    'field'    => 'slug',
                    'terms'    => $_GET['maten'],
                ),
            ));
        }
        if (isset($_GET['wijdtematen']) && ! empty($_GET['wijdtematen'])) {
            $query->set('tax_query', array(
                array(
                    'taxonomy' => 'breedtematen',
                    'field'    => 'slug',
                    'terms'    => $_GET['breedtematen'],
                ),
            ));
        }
        if (isset($_GET['kleur']) && ! empty($_GET['kleur'])) {
            $query->set('tax_query', array(
                array(
                    'taxonomy' => 'kleuren',
                    'field'    => 'slug',
                    'terms'    => $_GET['kleuren'],
                ),
            ));
        }
    }
}




function search_only_products($query)
{
    if ($query->is_search && !is_admin()) {
        $query->set('post_type', 'schoenen');
    }
    return $query;
}
add_filter('pre_get_posts', 'search_only_products');


/**
 * === Finn Comfort — Season ordering for CPT "schoenen" ===
 * Uses ACF fields:
 *  - Season_tag (Text)           e.g. "25 voorjaar", "25 najaar", "NOOS"
 *  - season_order_index (Number) computed here
 * Falls back to taxonomy 'seizoenen' term name if Season_tag is empty.
 */

/** Convert a season tag -> order index (NOOS last) */
if (!function_exists('finn_season_order_index')) {
    function finn_season_order_index($season_tag)
    {
        $season_tag = trim((string) $season_tag);
        if ($season_tag === '') return PHP_INT_MAX; // unknown -> bottom

        // Push NOOS to the very end when sorting ASC
        if (stripos($season_tag, 'NOOS') !== false) return PHP_INT_MAX;

        // Expect: "YY seizoen" (e.g. "25 voorjaar" or "25 najaar")
        $parts = preg_split('/\s+/', $season_tag);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) return PHP_INT_MAX;

        $tag_year   = (int) $parts[0];
        $tag_season = mb_strtolower($parts[1]);

        $map = ['voorjaar' => 0, 'najaar' => 1, 'spring' => 0, 'herfst' => 1, 'fall' => 1];
        if (!isset($map[$tag_season])) return PHP_INT_MAX;

        $now          = current_time('timestamp');
        $current_year = (int) date('y', $now);                // two digits
        $current_mode = ((int) date('n', $now) <= 6) ? 0 : 1; // 0=voorjaar, 1=najaar

        $current_val = $current_year * 2 + $current_mode;
        $tag_val     = $tag_year   * 2 + $map[$tag_season];

        $index = $current_val - $tag_val;

        // Future seasons go to the bottom
        return ($index < 0) ? PHP_INT_MAX : $index;
    }
}


/** If Season_tag empty, derive from 'seizoenen' term name (must be like "25 voorjaar") */
function finn_guess_season_tag_from_terms($post_id)
{
    $terms = wp_get_post_terms($post_id, 'seizoenen', ['fields' => 'names']);
    if (is_wp_error($terms) || empty($terms)) return '';
    return trim((string) $terms[0]);
}

/** Compute + save index after each WP All Import row */
add_action('pmxi_saved_post', function ($post_id) {
    if (get_post_type($post_id) !== 'schoenen') return;

    // Your ACF field is "Season_tag" (capital S). Also check lowercase in case you rename later.
    $raw = get_post_meta($post_id, 'Season_tag', true);
    if ($raw === '' || $raw === null) {
        $raw = get_post_meta($post_id, 'season_tag', true);
    }
    if ($raw === '' || $raw === null) {
        $raw = finn_guess_season_tag_from_terms($post_id);
        if ($raw !== '') update_post_meta($post_id, 'Season_tag', $raw);
    }

    $idx = finn_season_order_index($raw);
    update_post_meta($post_id, 'season_order_index', $idx);
}, 10, 1);

/** (One-time) backfill: visit ?finn_reindex_schoenen=1 while logged in as admin */
add_action('init', function () {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    if (!isset($_GET['finn_reindex_schoenen'])) return;

    $q = new WP_Query([
        'post_type'      => 'schoenen',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    foreach ($q->posts as $id) {
        $raw = get_post_meta($id, 'Season_tag', true);
        if ($raw === '' || $raw === null) {
            $raw = get_post_meta($id, 'season_tag', true);
        }
        if ($raw === '' || $raw === null) {
            $raw = finn_guess_season_tag_from_terms($id);
            if ($raw !== '') update_post_meta($id, 'Season_tag', $raw);
        }
        $idx = finn_season_order_index($raw);
        update_post_meta($id, 'season_order_index', $idx);
    }
    wp_die('Reindex done for CPT "schoenen". Comment/remove this block after running once.');
});

/** Sort main queries that explicitly fetch schoenen (e.g. Home/Dames/Heren if they query this CPT) */
add_action('pre_get_posts', function (WP_Query $q) {
    if (is_admin() || !$q->is_main_query()) return;
    $pt = $q->get('post_type');
    $is_schoenen = (is_string($pt) && $pt === 'schoenen') || (is_array($pt) && in_array('schoenen', $pt, true));
    if ($is_schoenen) {
        $q->set('meta_key', 'season_order_index');
        $q->set('orderby', 'meta_value_num');
        $q->set('order', 'ASC');
    }
});

/** (Optional) Show Season_tag + season_order_index in the admin list */
add_filter('manage_schoenen_posts_columns', function ($cols) {
    $cols['Season_tag'] = 'Season tag';
    $cols['season_order_index'] = 'Season order';
    return $cols;
});
add_action('manage_schoenen_posts_custom_column', function ($col, $post_id) {
    if ($col === 'Season_tag') echo esc_html(get_post_meta($post_id, 'Season_tag', true));
    if ($col === 'season_order_index') echo esc_html(get_post_meta($post_id, 'season_order_index', true));
}, 10, 2);

/** Shortcode for the two extra pages (category row, sorted by season) */
add_shortcode('finn_shoe_row', function ($atts) {
    $a = shortcode_atts([
        'category'       => '',
        'taxonomy'       => 'schoentypes', // change if your terms live elsewhere
        'posts_per_page' => 12,
        'post_type'      => 'schoenen',
    ], $atts, 'finn_shoe_row');

    $args = [
        'post_type'      => $a['post_type'],
        'posts_per_page' => (int)$a['posts_per_page'],
        'meta_key'       => 'season_order_index',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];
    if (!empty($a['category'])) {
        $args['tax_query'] = [[
            'taxonomy' => $a['taxonomy'],
            'field'    => 'slug',
            'terms'    => $a['category'],
        ]];
    }

    $q = new WP_Query($args);
    ob_start();
    if ($q->have_posts()) {
        echo '<ul class="finn-shoe-row">';
        while ($q->have_posts()) {
            $q->the_post();
            $title = get_the_title();
            $perma = get_permalink();
            $thumb = get_the_post_thumbnail(null, 'medium');
            if (!$thumb) {
                $acf_img_url = get_post_meta(get_the_ID(), 'afbeelding_url', true);
                if ($acf_img_url) $thumb = '<img src="' . esc_url($acf_img_url) . '" alt="' . esc_attr($title) . '">';
            }
            echo '<li class="finn-shoe-item"><a href="' . esc_url($perma) . '">';
            if ($thumb) echo $thumb;
            echo '<h3 class="finn-shoe-title">' . esc_html($title) . '</h3>';
            echo '</a></li>';
        }
        echo '</ul>';
    }
    wp_reset_postdata();
    return ob_get_clean();
});


/**
 * Get meta value (ACF first, then post meta).
 */
function schoenen_get_value($post_id, $field)
{
    if (! $post_id || ! $field) return '';
    if (function_exists('get_field')) {
        $val = get_field($field, $post_id);
        if ($val !== null && $val !== '') return $val;
    }
    $val = get_post_meta($post_id, $field, true);
    return ($val !== '' && $val !== null) ? $val : '';
}

/**
 * [schoenen name="afbeelding_url" context="attr|text|html" post_id=""]
 *
 * - name    : (required) exact ACF/meta field slug
 * - context : attr (default) -> esc_attr(); text -> esc_html(); html -> wp_kses_post()
 * - post_id : optional; defaults to current post
 *
 * Safe to use inside HTML attributes, e.g.:
 *   <img src="[schoenen name='afbeelding_url']" alt="[schoenen name='naam']" />
 */
add_shortcode('schoenen', function ($atts) {
    $a = shortcode_atts([
        'name'    => '',
        'context' => 'attr',   // attr|text|html
        'post_id' => '',
    ], $atts, 'schoenen');

    $post_id = $a['post_id'] ? intval($a['post_id']) : get_the_ID();
    if (! $post_id || ! $a['name']) return '';

    $val = schoenen_get_value($post_id, $a['name']);
    if ($val === '') return '';

    switch ($a['context']) {
        case 'html':
            return wp_kses_post(is_string($val) ? $val : '');
        case 'text':
            return esc_html(is_scalar($val) ? (string)$val : '');
        case 'attr':
        default:
            return esc_attr(is_scalar($val) ? (string)$val : '');
    }
});

/**
 * [schoenen_terms taxonomy="merken" linked="yes" sep=", " before="" after="" post_id=""]
 *
 * Outputs native CPT UI taxonomy terms for the current (or given) post.
 */
add_shortcode('schoenen_terms', function ($atts) {
    $a = shortcode_atts([
        'taxonomy' => 'merken',
        'linked'   => 'yes',
        'sep'      => ', ',
        'before'   => '',
        'after'    => '',
        'post_id'  => '',
    ], $atts, 'schoenen_terms');

    $post_id = $a['post_id'] ? intval($a['post_id']) : get_the_ID();
    if (! $post_id) return '';

    $terms = get_the_terms($post_id, $a['taxonomy']);
    if (is_wp_error($terms) || empty($terms)) return '';

    $out = [];
    foreach ($terms as $t) {
        $name = esc_html($t->name);
        $out[] = ($a['linked'] === 'yes')
            ? '<a href="' . esc_url(get_term_link($t)) . '">' . $name . '</a>'
            : $name;
    }
    return $a['before'] . implode(esc_html($a['sep']), $out) . $a['after'];
});
