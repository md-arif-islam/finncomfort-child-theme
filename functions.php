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
