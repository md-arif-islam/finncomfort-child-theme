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

    // Generate AI description with hash checking
    $general_description = schoenen_get_value($post_id, 'general_description');
    if (!empty($general_description)) {
        // Schedule AI generation with staggered timing to avoid rate limits
        $delay = rand(30, 180); // Random delay between 30-180 seconds
        wp_schedule_single_event(time() + $delay, 'generate_ai_description_hook', array($post_id));
    }
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

// AI Description Generation Function
function generate_ai_description($post_id, $force_regenerate = false)
{
    $general_description = schoenen_get_value($post_id, 'general_description');
    $post_title = get_the_title($post_id);

    if (empty($general_description)) {
        return '';
    }

    // Check if force regenerate is enabled globally
    if (defined('FORCE_AI_DESCRIPTION_REGENERATE') && FORCE_AI_DESCRIPTION_REGENERATE === true) {
        $force_regenerate = true;
    }

    // Create hash of source content to detect changes
    $content_hash = md5($post_title . $general_description);
    $stored_hash = get_post_meta($post_id, 'ai_description_hash', true);

    // Only generate if hash has changed, doesn't exist, or force regenerate
    if (!$force_regenerate && $content_hash === $stored_hash) {
        return get_post_meta($post_id, 'ai_description', true);
    }

    $prompt = <<<PROMPT
# Product Description Prompt

You are a product description writer. Write a new, unique description of **3 to 5 sentences** for each product in Dutch. Keep the text clear and informative, without exaggeration. **Output must be HTML only** — do not include anything before or after the HTML.

## 1. Research
- Search online for information about this specific product.  
- Preferably use sources in languages other than Dutch.  
- Carefully verify that the information you find is about the exact same product with the same name.

## 2. Processing
- Combine the original description with the new information you’ve found.  
- Fully rewrite the text: **do not** copy sentences or literal phrases from the sources.  
- Ensure the new description flows naturally, with a consistent style.

## 3. Output Structure Rules
- HTML only — no other text, explanations, or formatting outside the HTML.  
- Use only the following HTML tags: `<h2>`, `<p>`, `<ul>`, `<li>`.  
- `<h2>` for the product title, `<p>` for description sentences, `<ul><li>` for listing specific features.  
- No other HTML tags allowed.

---

**Original description:**  
{$general_description}

---

**Important:**  
- Final output **must** be HTML only.  
- Must be written in **Dutch**.  
- 3–5 sentences total.
PROMPT;

    $body = [
        'model' => 'gpt-5',
        // 'tools' => [
        //     ['type' => 'web_search']
        // ],
        'input' => $prompt,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/responses', [
        'timeout' => 90,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . FINNCOMFORT_OPENAI_API,
        ],
        'body' => wp_json_encode($body),
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        error_log('OpenAI API error: ' . $response->get_error_message());
        return '';
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    // Handle rate limiting
    if (isset($data['error']) && $data['error']['code'] === 'rate_limit_exceeded') {
        // Extract wait time from error message
        $wait_time = 2; // Default 2 seconds
        if (preg_match('/Please try again in ([\d.]+)s/', $data['error']['message'], $matches)) {
            $wait_time = (float)$matches[1] + 1; // Add 1 second buffer
        }

        // Reschedule for later
        wp_schedule_single_event(time() + ceil($wait_time), 'generate_ai_description_hook', array($post_id));
        return '';
    }

    // Find the message content in output array
    $ai_description = '';
    if (isset($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $output_item) {
            if (
                isset($output_item['type']) && $output_item['type'] === 'message' &&
                isset($output_item['content'][0]['text'])
            ) {
                $ai_description = trim($output_item['content'][0]['text']);
                break;
            }
        }
    }

    if (!empty($ai_description)) {
        update_post_meta($post_id, 'ai_description', $ai_description);
        update_post_meta($post_id, 'ai_description_hash', $content_hash);
        return $ai_description;
    } else {
        error_log('OpenAI API error: ' . print_r($data, true));
        return '';
    }
}

// Hook to handle scheduled AI description generation
add_action('generate_ai_description_hook', function ($post_id) {
    $post_title = get_the_title($post_id);
    error_log("AI Description: Starting generation for post '{$post_title}' (ID: {$post_id})");
    $result = generate_ai_description($post_id);
    if (!empty($result)) {
        error_log("AI Description: Successfully generated for post '{$post_title}' (ID: {$post_id})");
    } else {
        error_log("AI Description: Failed to generate for post '{$post_title}' (ID: {$post_id})");
    }
});

// Add admin menu for AI Description debugging
add_action('admin_menu', function() {
    add_management_page(
        'AI Description Debug',
        'AI Description Debug', 
        'manage_options',
        'ai-description-debug',
        'ai_description_debug_page'
    );
});

function ai_description_debug_page() {
    echo "<div class='wrap'>";
    echo "<h1>AI Description Debug Status</h1>";
    
    // Check WordPress cron
    $cron_jobs = get_option('cron');
    $ai_jobs = 0;
    
    if ($cron_jobs) {
        foreach ($cron_jobs as $timestamp => $jobs) {
            if (isset($jobs['generate_ai_description_hook'])) {
                $ai_jobs += count($jobs['generate_ai_description_hook']);
            }
        }
    }
    
    echo "<p>AI descriptions in queue: <strong>{$ai_jobs}</strong></p>";
    
    // Show last cron run time
    $cron_last_run = get_option('_transient_doing_cron');
    if ($cron_last_run) {
        $time_diff = time() - $cron_last_run;
        $seconds = round($time_diff);
        echo "<p>Last processing: <strong>{$seconds} seconds ago</strong></p>";
    } else {
        echo "<p>Last processing: <strong>Unknown</strong></p>";
    }
    
    // Check recent posts without AI descriptions
    $posts_without_ai = new WP_Query([
        'post_type' => 'schoenen',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'general_description',
                'value' => '',
                'compare' => '!='
            ],
            [
                'key' => 'ai_description',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ]);
    
    echo "<p>Posts without AI descriptions: <strong>{$posts_without_ai->found_posts}</strong></p>";
    
    // Check posts with AI descriptions
    $posts_with_ai = new WP_Query([
        'post_type' => 'schoenen',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => 'ai_description',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    echo "<p>Posts WITH AI descriptions: <strong>{$posts_with_ai->found_posts}</strong></p>";
    
    echo "<h3>Manual Processing</h3>";
    echo "<div style='margin-bottom: 20px;'>";
    echo "<button class='button button-primary' onclick='processDirectly()' style='margin-right: 10px;'>Process 3 Posts Directly</button>";
    echo "<button class='button button-secondary' onclick='forceCronRun()' style='margin-right: 10px;'>Force Run WP Cron</button>";
    echo "<button class='button' onclick='triggerManualGeneration()' style='margin-right: 10px;'>Trigger 5 Posts (Cron)</button>";
    echo "</div>";
    
    echo "<h3>Cleanup Actions</h3>";
    echo "<div style='margin-bottom: 20px;'>";
    echo "<button class='button button-secondary' onclick='clearCronQueue()' style='margin-right: 10px; background-color: #f56565; border-color: #f56565; color: white;'>Clear All AI Cron Jobs</button>";
    echo "<button class='button button-secondary' onclick='deleteAllAIDescriptions()' style='margin-right: 10px; background-color: #e53e3e; border-color: #e53e3e; color: white;'>Delete All AI Descriptions</button>";
    echo "<button class='button button-secondary' onclick='clearDebugLog()' style='background-color: #fd7f6f; border-color: #fd7f6f; color: white;'>Clear Debug Log</button>";
    echo "</div>";
    
    echo "<p><small>";
    echo "<strong>Direct processing:</strong> Works immediately, processes 3 posts with delays.<br>";
    echo "<strong>Force WP Cron:</strong> Attempts to run all queued cron jobs manually.<br>";
    echo "<strong>Trigger Cron:</strong> Adds more posts to cron queue.<br>";
    echo "<strong style='color: red;'>Clear Cron Jobs:</strong> Removes all AI generation jobs from queue.<br>";
    echo "<strong style='color: red;'>Delete Descriptions:</strong> Removes all AI descriptions from database.<br>";
    echo "<strong style='color: red;'>Clear Debug Log:</strong> Removes all entries from debug.log file.";
    echo "</small></p>";
    
    // Check recent debug log entries
    $debug_log_path = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($debug_log_path)) {
        echo "<h3>Recent AI Description Log Entries:</h3>";
        $log_content = file_get_contents($debug_log_path);
        $lines = explode("\n", $log_content);
        $ai_lines = array_filter($lines, function($line) {
            return strpos($line, 'AI Description:') !== false;
        });
        
        if (!empty($ai_lines)) {
            echo "<pre style='background: #f1f1f1; padding: 10px; max-height: 300px; overflow-y: scroll;'>";
            foreach (array_slice(array_reverse($ai_lines), 0, 20) as $line) {
                echo esc_html($line) . "\n";
            }
            echo "</pre>";
        } else {
            echo "<p>No AI Description log entries found.</p>";
        }
    }
    
    echo "<script>
    function processDirectly() {
        if (confirm('This will process 3 posts directly and may take 10-15 seconds. Continue?')) {
            var btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=process_ai_queue_direct'
            }).then(response => response.text()).then(data => {
                alert(data);
                location.reload();
            }).catch(error => {
                alert('Error: ' + error);
                btn.disabled = false;
                btn.textContent = 'Process 3 Posts Directly';
            });
        }
    }
    
    function forceCronRun() {
        if (confirm('This will attempt to run all queued WP Cron jobs. Continue?')) {
            var btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Running Cron...';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=force_cron_run'
            }).then(response => response.text()).then(data => {
                alert(data);
                location.reload();
            }).catch(error => {
                alert('Error: ' + error);
                btn.disabled = false;
                btn.textContent = 'Force Run WP Cron';
            });
        }
    }
    
    function clearCronQueue() {
        if (confirm('WARNING: This will remove ALL AI description jobs from the cron queue. They will stop processing. Continue?')) {
            var btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Clearing...';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=clear_ai_cron_queue'
            }).then(response => response.text()).then(data => {
                alert(data);
                location.reload();
            }).catch(error => {
                alert('Error: ' + error);
                btn.disabled = false;
                btn.textContent = 'Clear All AI Cron Jobs';
            });
        }
    }
    
    function deleteAllAIDescriptions() {
        if (confirm('DANGER: This will permanently delete ALL AI descriptions from the database. This cannot be undone! Are you absolutely sure?')) {
            if (confirm('Last chance! This will delete all AI-generated content. Type OK to confirm.')) {
                var btn = event.target;
                btn.disabled = true;
                btn.textContent = 'Deleting...';
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=delete_all_ai_descriptions'
                }).then(response => response.text()).then(data => {
                    alert(data);
                    location.reload();
                }).catch(error => {
                    alert('Error: ' + error);
                    btn.disabled = false;
                    btn.textContent = 'Delete All AI Descriptions';
                });
            }
        }
    }
    
    function clearDebugLog() {
        if (confirm('This will clear the entire debug.log file. All log entries will be permanently removed. Continue?')) {
            var btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Clearing...';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=clear_debug_log'
            }).then(response => response.text()).then(data => {
                alert(data);
                location.reload();
            }).catch(error => {
                alert('Error: ' + error);
                btn.disabled = false;
                btn.textContent = 'Clear Debug Log';
            });
        }
    }
    
    function triggerManualGeneration() {
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=trigger_manual_ai_generation'
        }).then(response => response.text()).then(data => {
            alert('Triggered generation for posts. Check debug log in a few minutes.');
            location.reload();
        });
    }
    
    function triggerAI(postId) {
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=trigger_single_ai_generation&post_id=' + postId
        }).then(response => response.text()).then(data => {
            alert('Triggered generation for post ' + postId + '. Check debug log in a few minutes.');
        });
    }
    </script>";
    
    echo "</div>";
}

// AJAX handlers for manual triggering
add_action('wp_ajax_trigger_manual_ai_generation', function() {
    $posts = new WP_Query([
        'post_type' => 'schoenen',
        'posts_per_page' => 5,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'general_description',
                'value' => '',
                'compare' => '!='
            ],
            [
                'key' => 'ai_description',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ]);
    
    $count = 0;
    if ($posts->have_posts()) {
        while ($posts->have_posts()) {
            $posts->the_post();
            wp_schedule_single_event(time() + ($count * 10), 'generate_ai_description_hook', array(get_the_ID()));
            $count++;
        }
    }
    wp_reset_postdata();
    
    wp_die("Triggered {$count} posts");
});

add_action('wp_ajax_trigger_single_ai_generation', function() {
    $post_id = intval($_POST['post_id']);
    if ($post_id) {
        wp_schedule_single_event(time() + 5, 'generate_ai_description_hook', array($post_id));
        wp_die("Triggered post {$post_id}");
    }
    wp_die("Invalid post ID");
});

// Alternative processing when WP Cron is disabled
add_action('wp_ajax_process_ai_queue_direct', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Process a few posts directly without cron
    $posts = new WP_Query([
        'post_type' => 'schoenen',
        'posts_per_page' => 3, // Process 3 at a time to avoid timeouts
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'general_description',
                'value' => '',
                'compare' => '!='
            ],
            [
                'key' => 'ai_description',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ]);
    
    $processed = 0;
    if ($posts->have_posts()) {
        while ($posts->have_posts()) {
            $posts->the_post();
            error_log("AI Description: Processing directly - Post ID " . get_the_ID());
            
            // Add delay between requests to avoid rate limits
            if ($processed > 0) {
                sleep(3); // 3 second delay between each request
            }
            
            $result = generate_ai_description(get_the_ID(), true); // Force generate
            if (!empty($result)) {
                error_log("AI Description: Direct processing successful for Post ID " . get_the_ID());
                $processed++;
            } else {
                error_log("AI Description: Direct processing failed for Post ID " . get_the_ID());
                break; // Stop on first failure to avoid rate limit cascade
            }
        }
    }
    wp_reset_postdata();
    
    wp_die("Processed {$processed} posts directly. Refresh debug page to see updated counts.");
});

// Force run WP Cron manually
add_action('wp_ajax_force_cron_run', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Trigger WP Cron manually
    $cron_url = site_url('wp-cron.php');
    $response = wp_remote_get($cron_url, [
        'timeout' => 30,
        'blocking' => false // Don't wait for response
    ]);
    
    // Also try to run cron directly
    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
    
    // Count AI description cron jobs before and after
    $cron_jobs = get_option('cron');
    $ai_jobs_after = 0;
    
    if ($cron_jobs) {
        foreach ($cron_jobs as $timestamp => $jobs) {
            if (isset($jobs['generate_ai_description_hook'])) {
                $ai_jobs_after += count($jobs['generate_ai_description_hook']);
            }
        }
    }
    
    if (is_wp_error($response)) {
        wp_die("Failed to trigger WP Cron: " . $response->get_error_message() . ". Remaining AI jobs: {$ai_jobs_after}");
    } else {
        wp_die("WP Cron triggered manually. Remaining AI jobs: {$ai_jobs_after}. Check debug log in a few minutes.");
    }
});

// Clear all AI description cron jobs
add_action('wp_ajax_clear_ai_cron_queue', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $cron_jobs = get_option('cron');
    $cleared_count = 0;
    
    if ($cron_jobs) {
        foreach ($cron_jobs as $timestamp => $jobs) {
            if (isset($jobs['generate_ai_description_hook'])) {
                $cleared_count += count($jobs['generate_ai_description_hook']);
                unset($cron_jobs[$timestamp]['generate_ai_description_hook']);
                
                // Remove timestamp entry if no other jobs
                if (empty($cron_jobs[$timestamp])) {
                    unset($cron_jobs[$timestamp]);
                }
            }
        }
        update_option('cron', $cron_jobs);
    }
    
    // Also clear any currently running spawned cron processes
    wp_clear_scheduled_hook('generate_ai_description_hook');
    
    wp_die("Cleared {$cleared_count} AI description cron jobs from queue and stopped any running processes.");
});

// Delete all AI descriptions from database
add_action('wp_ajax_delete_all_ai_descriptions', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    // Delete ai_description meta
    $deleted_descriptions = $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'ai_description'"
    );
    
    // Delete ai_description_hash meta
    $deleted_hashes = $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'ai_description_hash'"
    );
    
    wp_die("Deleted {$deleted_descriptions} AI descriptions and {$deleted_hashes} hash entries from database.");
});

// Clear AI description log entries only
add_action('wp_ajax_clear_debug_log', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $debug_log_path = WP_CONTENT_DIR . '/debug.log';
    
    if (file_exists($debug_log_path)) {
        $log_content = file_get_contents($debug_log_path);
        $lines = explode("\n", $log_content);
        
        $filtered_lines = [];
        $removed_count = 0;
        
        foreach ($lines as $line) {
            // Keep lines that don't contain AI description related messages
            if (strpos($line, 'AI Description') === false && 
                strpos($line, 'OpenAI API') === false &&
                strpos($line, 'generate_ai_description') === false &&
                strpos($line, 'ai_description') === false) {
                $filtered_lines[] = $line;
            } else {
                $removed_count++;
            }
        }
        
        // Write back the filtered content
        file_put_contents($debug_log_path, implode("\n", $filtered_lines));
        
        wp_die("Cleared {$removed_count} AI description log entries from debug log.");
    } else {
        wp_die("Debug log file not found.");
    }
});

// Shortcode to display AI description or fallback to general_description
add_shortcode('schoenen_smart_description', function($atts) {
    $atts = shortcode_atts([
        'name' => 'general_description',
        'context' => 'html'
    ], $atts);
    
    global $post;
    if (!$post) return '';
    
    // First try to get AI description
    $ai_description = get_post_meta($post->ID, 'ai_description', true);
    
    if (!empty($ai_description)) {
        return $ai_description;
    }
    
    // Fallback to ACF field
    $general_description = get_field($atts['name'], $post->ID);
    
    if ($atts['context'] === 'html' && !empty($general_description)) {
        return wpautop($general_description);
    }
    
    return $general_description ?: '';
});

?>
