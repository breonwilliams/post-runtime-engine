<?php
/**
 * Smoke: external identity + upsert (LOCAL only, loads WordPress).
 *
 *   php -d mysqli.default_socket=… tests/smoke-external-upsert.php
 *
 * @package PostRuntimeEngine\Tests
 */
$wp_load = ''; $dir = __DIR__;
for ($i = 0; $i < 8; $i++) { $dir = dirname($dir); if (file_exists($dir . '/wp-load.php')) { $wp_load = $dir . '/wp-load.php'; break; } }
if ('' === $wp_load) { fwrite(STDERR, "WordPress not found.\n"); exit(1); }
require_once $wp_load;

$pass = 0; $fail = 0;
function check($cond, $label, $detail = '') { global $pass, $fail; if ($cond) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n"; } }

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins[0]);
$plugin = pcptpages();
$cpt = 'upserttest';

if (!$plugin->cpts->exists($cpt)) {
    $plugin->cpts->register($cpt, ['slug' => $cpt, 'label_singular' => 'Upsert test', 'label_plural' => 'Upsert tests', 'public' => true, 'has_archive' => false, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'excerpt'], 'taxonomies' => ['category'], 'hero_layout' => 'stacked', 'default_icon' => 'mdi:calendar-star']);
    $plugin->post_fields->define($cpt, ['key' => 'event_start', 'label' => 'Starts', 'display_type' => 'date', 'card_position' => 'headline', 'single_position' => 'meta_strip', 'date_format' => 'custom', 'date_format_string' => 'M j, Y', 'all_day' => false, 'semantic_role' => 'event_start']);
    $plugin->post_fields->define($cpt, ['key' => 'event_location', 'label' => 'Location', 'display_type' => 'text', 'card_position' => 'meta_strip', 'single_position' => 'meta_strip', 'semantic_role' => 'event_location']);
    $plugin->cpts->register_all_with_wp();
}
$pd = $plugin->post_data;

echo "\nupsert_external\n";
$rec = ['title' => 'Youth Soccer', 'excerpt' => 'Ages 6-10', 'fields' => ['event_start' => '2026-10-04T09:00:00', 'event_location' => 'Wilson Park'], 'taxonomies' => ['category' => ['Upsert Youth']]];
$r1 = $pd->upsert_external($cpt, 'RecDesk', 4471, $rec);
check(!is_wp_error($r1) && $r1['action'] === 'created', 'first call creates', is_wp_error($r1) ? $r1->get_error_message() : json_encode($r1));
$id = (int) $r1['post_id'];
check(get_post_status($id) === 'publish', 'status defaults to publish on create');
check($pd->get_external($id)['source'] === 'recdesk' && $pd->get_external($id)['external_id'] === '4471', 'identity stored, source normalised, numeric id stringified', json_encode($pd->get_external($id)));
check($pd->find_external($cpt, 'recdesk', '4471') === $id, 'find_external resolves the identity');
$vals = $pd->get_field_values($id);
check(($vals['event_location'] ?? '') === 'Wilson Park', 'field values written', json_encode($vals));
check(has_term('Upsert Youth', 'category', $id), 'taxonomy term created and assigned');

$modified_before = get_post($id)->post_modified_gmt;
$r2 = $pd->upsert_external($cpt, 'recdesk', '4471', $rec);
check(!is_wp_error($r2) && $r2['action'] === 'unchanged' && $r2['post_id'] === $id, 'same payload again is unchanged, same post', json_encode($r2));
check(get_post($id)->post_modified_gmt === $modified_before, 'unchanged record is not rewritten (post_modified untouched)');

$reordered = ['taxonomies' => ['category' => ['Upsert Youth']], 'fields' => ['event_location' => 'Wilson Park', 'event_start' => '2026-10-04T09:00:00'], 'excerpt' => 'Ages 6-10', 'title' => 'Youth Soccer'];
$r3 = $pd->upsert_external($cpt, 'recdesk', '4471', $reordered);
check($r3['action'] === 'unchanged', 'key order does not change the hash');

// Local edit to a field NOT in the mapping survives; mapped field updates.
update_post_meta($id, '_pcptpages_field_event_location', 'Edited locally');
$pd->set_field_values($id, ['event_location' => 'Edited locally']);
$r4 = $pd->upsert_external($cpt, 'recdesk', '4471', ['title' => 'Youth Soccer (Fall)', 'fields' => ['event_start' => '2026-10-11T09:00:00']]);
check($r4['action'] === 'updated' && get_the_title($id) === 'Youth Soccer (Fall)', 'changed payload updates the same post', json_encode($r4));
$vals = $pd->get_field_values($id);
check(($vals['event_location'] ?? '') === 'Edited locally', 'a field the mapping does not name keeps its local edit', json_encode($vals));
check(get_post_status($id) === 'publish', 'status kept on update when not sent');
wp_update_post(['ID' => $id, 'post_status' => 'draft']);
$r5 = $pd->upsert_external($cpt, 'recdesk', '4471', ['title' => 'Youth Soccer (Fall)', 'fields' => ['event_start' => '2026-10-11T09:00:00'], 'excerpt' => 'x']);
check(get_post_status($id) === 'draft', 'an editor\'s draft status survives a sync that does not send status');

$bad = $pd->upsert_external($cpt, 'recdesk', '9999', ['title' => 'Broken', 'fields' => ['no_such_field' => 1]]);
check(is_wp_error($bad) && $bad->get_error_code() === 'pcptpages_unknown_field_key', 'unknown field key fails the call');
check($pd->find_external($cpt, 'recdesk', '9999') === 0, 'a failed create is rolled back');
check(is_wp_error($pd->upsert_external($cpt, '', '1', ['title' => 'x'])), 'missing source refused');
check(is_wp_error($pd->upsert_external($cpt, 'recdesk', '', ['title' => 'x'])), 'missing external_id refused');
check(is_wp_error($pd->upsert_external('nope', 'recdesk', '1', ['title' => 'x'])), 'unregistered type refused');

$r6 = $pd->upsert_external($cpt, 'recdesk', '4472', ['title' => 'Adult Tennis']);
$listed = $pd->list_external($cpt, 'recdesk');
check(isset($listed[$id]) && isset($listed[(int) $r6['post_id']]) && count($listed) === 2, 'list_external returns every record of the source with synced_at', json_encode($listed));

echo "\nconnector\n";
$req = new WP_REST_Request('POST', '/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE . '/posts/upsert');
$req->set_header('Content-Type', 'application/json');
$req->set_body(wp_json_encode(['post_type' => $cpt, 'source' => 'recdesk', 'external_id' => 4473, 'post_title' => 'Via connector', 'fields' => ['event_location' => 'Online']]));
$res = rest_get_server()->dispatch($req);
$d = $res->get_data();
check($res->get_status() === 201 && ($d['action'] ?? '') === 'created', 'POST /posts/upsert creates with 201', json_encode($d));
$res = rest_get_server()->dispatch($req);
check($res->get_status() === 200 && ($res->get_data()['action'] ?? '') === 'unchanged', 'second POST is 200 unchanged');
$req = new WP_REST_Request('GET', '/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE . '/posts');
$req->set_param('post_type', $cpt);
$res = rest_get_server()->dispatch($req);
$posts = $res->get_data()['posts'] ?? [];
$with = array_filter($posts, static fn($p) => !empty($p['external']));
check(count($with) === 3 && $with[array_key_first($with)]['external']['source'] === 'recdesk', 'list_posts shows the external identity', json_encode(array_column($posts, 'external')));

echo "\npurge removes identity\n";
$req = new WP_REST_Request('DELETE', '/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE . '/cpts/' . $cpt);
$req->set_url_params(['slug' => $cpt]);
$req->set_param('purge_data', true);
$res = (new PCPTPages_Connector_API())->handle_delete_cpt($req);
global $wpdb;
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s)", '_pcptpages_external_%', $cpt));
check($res->get_status() === 200 && $left === 0, 'purge removes external identity meta', "left {$left}");

// Cleanup.
foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $cpt)) as $pid) wp_delete_post((int) $pid, true);
foreach (get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'search' => 'Upsert Youth']) as $t) wp_delete_term($t->term_id, 'category');
$tomb = get_option(PCPTPages_Connector_API::DELETED_CPTS_OPTION, []);
if (is_array($tomb) && isset($tomb[$cpt])) { unset($tomb[$cpt]); update_option(PCPTPages_Connector_API::DELETED_CPTS_OPTION, $tomb, false); }

echo "\n{$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
