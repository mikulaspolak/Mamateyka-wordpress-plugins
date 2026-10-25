<?php

/**
 * Google Drive API client with Service Account JWT authentication.
 * No external SDK required – uses only wp_remote_* and openssl_sign().
 */

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('Oznamy_GDrive_Client')) {
    return;
}

class Oznamy_GDrive_Client
{

    const DRIVE_BASE_URL = 'https://www.googleapis.com/drive/v3';
    const DOCS_BASE_URL = 'https://docs.googleapis.com/v1';
    private $access_token = null;
    private $sa_data = array();

    public function __construct($sa_json)
    {
        $this->sa_data = $sa_json;
    }

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

    /**
     * List Google Docs in $folder_id whose names match Oznamy_YYYY.MM.DD,
     * sorted newest-first.
     *
     * @return array<int, array{id: string, name: string, date: int}>
     */
    public function list_docs_sorted($folder_id)
    {
        $list_result = $this->list_folder_files($folder_id, "mimeType='application/vnd.google-apps.document' and trashed=false", true);
        $files       = $list_result['files'] ?? array();

        $docs = array();
        foreach ($files as $file) {
            $date = $this->parse_date_from_name($file['name']);
            if (null !== $date) {
                $docs[] = array(
                    'id'   => $file['id'],
                    'name' => $file['name'],
                    'date' => $date,
                );
            }
        }

        usort(
            $docs,
            static function ($a, $b) {
                return $b['date'] <=> $a['date'];
            }
        );

        return $docs;
    }

    /**
     * List the full folder hierarchy starting at $root_folder_id.
     *
     * @return array{id: string, name: string, type: string, children: array<int, array>}|array
     */
    public function list_folder_tree($root_folder_id)
    {
        $root_meta = $this->get_folder_metadata($root_folder_id);
        $root_name = is_array($root_meta) && ! empty($root_meta['name'])
            ? (string) $root_meta['name']
            : (string) $root_folder_id;

        $visited = array();

        return $this->build_folder_tree_node($root_folder_id, $root_name, $visited, true);
    }

    /**
     * List ALL files in $folder_id without filtering.
     * Returns all files (Google Docs + other files) with their details and regex match status.
     * Used for detailed diagnostic reporting and debugging.
     *
     * @return array{docs: array<int, array{id: string, name: string, mimeType: string, date: int|null, matches_pattern: bool}>, debug: array}
     */
    public function list_all_docs_for_diagnostics($folder_id)
    {
        $debug = array(
            'service_account_email' => $this->sa_data['client_email'] ?? 'unknown',
            'folder_id' => $folder_id,
            'api_calls' => array(),
            'errors' => array(),
            'hints' => array(),
        );

        $folder_meta = $this->get_folder_metadata($folder_id);
        if (isset($folder_meta['error'])) {
            $debug['errors'][] = 'Folder metadata check failed: ' . $folder_meta['error'];
            $debug['folder_meta'] = array();
        } else {
            $debug['folder_meta'] = $folder_meta;
        }

        $list_result = $this->list_folder_files($folder_id, 'trashed=false', true);
        $files       = $list_result['files'] ?? array();

        if (! empty($list_result['debug']['errors'])) {
            foreach ($list_result['debug']['errors'] as $error_text) {
                $debug['errors'][] = $error_text;
            }
        }
        if (! empty($list_result['debug']['api_calls'])) {
            $debug['api_calls'] = array_merge($debug['api_calls'], $list_result['debug']['api_calls']);
        }

        $docs = array();
        foreach ($files as $file) {
            $date            = $this->parse_date_from_name($file['name']);
            $matches_pattern = null !== $date;
            $is_google_doc   = 'application/vnd.google-apps.document' === ($file['mimeType'] ?? '');

            $docs[] = array(
                'id'               => $file['id'],
                'name'             => $file['name'],
                'mimeType'         => $file['mimeType'] ?? 'unknown',
                'is_google_doc'    => $is_google_doc,
                'date'             => $date,
                'matches_pattern'  => $matches_pattern,
            );
        }

        // Sort by name descending.
        usort(
            $docs,
            static function ($a, $b) {
                return strcmp($b['name'], $a['name']);
            }
        );

        $debug['total_files_found'] = count($docs);
        $debug['google_docs_only'] = count(array_filter($docs, static function ($d) {
            return $d['is_google_doc'];
        }));

        $name_probe = $this->search_docs_by_name_contains('Oznamy_');
        $debug['name_probe'] = $name_probe;

        if (0 === $debug['total_files_found'] && ! isset($folder_meta['error'])) {
            $debug['hints'][] = 'Folder is reachable, but listing returned 0 files. This is usually a sharing or location issue.';
            $debug['hints'][] = 'Verify the target document is directly inside this exact folder, not only in My Drive root or another folder.';
        }

        if (! empty($name_probe['count']) && 0 === $debug['total_files_found']) {
            $debug['hints'][] = 'Found Oznamy_* Google Docs elsewhere in Drive search. Folder ID likely points to a different folder.';
        }

        if (! empty($debug['api_calls'])) {
            $debug['api_call_count'] = count($debug['api_calls']);
        }

        return array('docs' => $docs, 'debug' => $debug);
    }

    /**
     * Get folder metadata for diagnostics (works with Shared Drives too).
     *
     * @return array
     */
    public function get_folder_metadata($folder_id)
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $folder_id)) {
            return array('error' => 'Invalid folder ID');
        }

        $args = array(
            'supportsAllDrives' => 'true',
            'fields'            => 'id,name,mimeType,trashed,driveId,capabilities(canListChildren)',
        );

        $result = $this->drive_get('/files/' . rawurlencode($folder_id), $args);
        if (isset($result['error'])) {
            return array('error' => $result['error']);
        }

        return $result['body'] ?? array();
    }

    /**
     * Search Google Docs by partial name across all available drives.
     *
     * @return array{count:int,files:array<int,array{id:string,name:string,driveId:string}>}
     */
    public function search_docs_by_name_contains($needle)
    {
        $safe_needle = str_replace("'", "\\'", (string) $needle);
        $query       = "name contains '{$safe_needle}' and mimeType='application/vnd.google-apps.document' and trashed=false";

        $args = array(
            'q'                        => $query,
            'fields'                   => 'nextPageToken,files(id,name,driveId)',
            'pageSize'                 => 25,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'        => 'true',
            'corpora'                  => 'allDrives',
        );

        $files = array();
        $result = $this->drive_get('/files', $args);
        if (! isset($result['error'])) {
            $body = $result['body'] ?? array();
            if (! empty($body['files']) && is_array($body['files'])) {
                $files = $body['files'];
            }
        }

        return array(
            'count' => count($files),
            'files' => $files,
        );
    }

    /**
     * Find exactly one Google Doc with the requested name in a specific folder.
     *
     * @return array{id:string,name:string,modifiedTime?:string}|null|WP_Error
     */
    public function find_doc_by_exact_name_in_folder_result($folder_id, $name)
    {
        $list_result = $this->list_folder_files(
            $folder_id,
            "mimeType='application/vnd.google-apps.document' and trashed=false",
            true
        );

        if (! empty($list_result['debug']['errors'])) {
            return new WP_Error('oznamy_doc_list_failed', implode(' ', $list_result['debug']['errors']));
        }

        $matches = array();
        foreach ($list_result['files'] as $file) {
            if (trim((string) ($file['name'] ?? '')) === trim((string) $name)) {
                $matches[] = $file;
            }
        }

        if (count($matches) > 1) {
            return new WP_Error(
                'oznamy_duplicate_doc_name',
                sprintf('V priečinku sa nachádza viac Google Docs s názvom „%s".', $name)
            );
        }

        if (empty($matches)) {
            return null;
        }

        return $matches[0];
    }

    /**
     * Get folder permissions (who has access).
     * Returns array of permissions for diagnostic purposes.
     */
    public function get_folder_permissions($folder_id)
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $folder_id)) {
            return array('error' => 'Invalid folder ID');
        }

        $result = $this->drive_get(
            '/files/' . rawurlencode($folder_id) . '/permissions',
            array(
                'supportsAllDrives' => 'true',
                'fields' => 'permissions(id,emailAddress,displayName,role,type)',
            )
        );

        if (isset($result['error'])) {
            return array('error' => $result['error']);
        }

        $body = $result['body'] ?? array();
        return $body['permissions'] ?? array();
    }

    /**
     * List files in a specific folder with optional additional query expression.
     *
     * @return array{files: array<int, array>, debug: array{api_calls: array, errors: array}}
     */
    private function list_folder_files($folder_id, $additional_query, $with_debug)
    {
        $debug = array(
            'api_calls' => array(),
            'errors'    => array(),
        );

        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $folder_id)) {
            $error_text = 'Invalid folder ID format.';
            if ($with_debug) {
                $debug['errors'][] = $error_text;
            }
            error_log('[Oznamy] ' . $error_text);
            return array('files' => array(), 'debug' => $debug);
        }

        $query_parts = array("'{$folder_id}' in parents");
        if (! empty($additional_query)) {
            $query_parts[] = (string) $additional_query;
        }
        $query = implode(' and ', $query_parts);

        $args = array(
            'q'                         => $query,
            'fields'                    => 'nextPageToken,files(id,name,mimeType,modifiedTime,driveId)',
            'orderBy'                   => 'name_natural desc',
            'pageSize'                  => 200,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'         => 'true',
            'corpora'                   => 'allDrives',
        );

        $files = array();
        $page  = 0;
        do {
            $page++;
            $result = $this->drive_get('/files', $args);

            if ($with_debug) {
                $debug['api_calls'][] = array(
                    'endpoint' => self::DRIVE_BASE_URL . '/files',
                    'query'    => $query,
                    'page'     => $page,
                    'params'   => $args,
                );
            }

            if (isset($result['error'])) {
                $error_text = 'Drive list failed: ' . $result['error'];
                error_log('[Oznamy] ' . $error_text);
                if ($with_debug) {
                    $debug['errors'][] = $error_text;
                }
                break;
            }

            $body = $result['body'] ?? array();
            if (! empty($body['files']) && is_array($body['files'])) {
                $files = array_merge($files, $body['files']);
            }

            $next_page_token = $body['nextPageToken'] ?? '';
            if (! empty($next_page_token)) {
                $args['pageToken'] = $next_page_token;
            } else {
                unset($args['pageToken']);
            }
        } while (! empty($next_page_token));

        return array(
            'files' => $files,
            'debug' => $debug,
        );
    }

    /**
     * Perform authenticated GET request against Drive API.
     *
     * @return array{body?:array,error?:string,http_status?:int}
     */
    private function drive_get($path, $args)
    {
        return $this->api_get(self::DRIVE_BASE_URL, $path, $args);
    }

    /**
     * Perform authenticated GET request against Google APIs.
     *
     * @return array{body?:array,error?:string,http_status?:int}
     */
    private function api_get($base_url, $path, $args)
    {
        $attempt = 0;
        do {
            $attempt++;

            $token = $this->get_access_token();
            if (! $token) {
                return array('error' => 'No access token');
            }

            $url = add_query_arg($args, rtrim((string) $base_url, '/') . $path);

            $response = wp_remote_get(
                $url,
                array(
                    'timeout' => 15,
                    'headers' => array('Authorization' => 'Bearer ' . $token),
                )
            );

            if (is_wp_error($response)) {
                return array('error' => $response->get_error_message());
            }

            $http_status = wp_remote_retrieve_response_code($response);
            if (200 !== $http_status) {
                $raw_body = wp_remote_retrieve_body($response);
                $decoded  = json_decode($raw_body, true);
                $api_msg  = '';
                if (is_array($decoded) && ! empty($decoded['error']['message'])) {
                    $api_msg = ': ' . $decoded['error']['message'];
                }

                if (
                    1 === $attempt
                    && in_array($http_status, array(401, 403), true)
                    && false !== stripos((string) $api_msg, 'insufficient')
                ) {
                    $this->invalidate_access_token();
                    continue;
                }

                return array('error' => 'HTTP ' . $http_status . $api_msg, 'http_status' => $http_status);
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (! is_array($body)) {
                return array('error' => 'Invalid JSON response from Google API', 'http_status' => $http_status);
            }

            return array('body' => $body, 'http_status' => $http_status);
        } while ($attempt < 2);

        return array('error' => 'Google API request failed after retry.');
    }

    /**
     * Find the doc ID for a specific date string (DD.MM.YYYY) in the subfolder structure,
     * or null if not found.
     */
    public function find_doc_by_date($folder_id, $date_str)
    {
        $subfolder = $this->find_subfolder_by_date($folder_id, $date_str);
        if ($subfolder) {
            return $this->find_doc_in_subfolder($subfolder['id']);
        }
        return null;
    }

    // =========================================================================
    // Subfolder structure (Oznamy_YYYY.MM.DD)
    // =========================================================================

    /**
     * Parse Oznamy_YYYY.MM.DD from a subfolder name.
     * Returns Unix timestamp (start of that day in WP local timezone) or null.
     */
    public function parse_subfolder_date_from_name($name)
    {
        $validation = $this->validate_announcement_folder_name($name);
        return $validation['valid'] ? $validation['date'] : null;
    }

    /**
     * Validate the exact name and Sunday date required for an announcement folder.
     *
     * @return array{valid:bool,date:int|null,error_code:string}
     */
    public function validate_announcement_folder_name($name)
    {
        $name = (string) $name;
        if (preg_match('/^Oznamy_(\d{2})\.(\d{2})\.(\d{4})\z/', $name)) {
            return array('valid' => false, 'date' => null, 'error_code' => 'legacy_format');
        }
        if (! preg_match('/^Oznamy_(\d{4})\.(\d{2})\.(\d{2})\z/', $name, $matches)) {
            return array('valid' => false, 'date' => null, 'error_code' => 'invalid_format');
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if (! checkdate($month, $day, $year)) {
            return array('valid' => false, 'date' => null, 'error_code' => 'invalid_date');
        }

        try {
            $tz = wp_timezone();
            $dt = new DateTimeImmutable(
                sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day),
                $tz
            );
            if ('7' !== $dt->format('N')) {
                return array('valid' => false, 'date' => null, 'error_code' => 'not_sunday');
            }

            return array('valid' => true, 'date' => $dt->getTimestamp(), 'error_code' => '');
        } catch (Exception $e) {
            return array('valid' => false, 'date' => null, 'error_code' => 'invalid_date');
        }
    }

    /**
     * List direct subfolders and separate valid announcement folders from invalid names.
     *
     * @return array{valid:array,invalid:array}|WP_Error
     */
    public function list_subfolders_with_validation($root_folder_id, $with_debug = false)
    {
        $list_result = $this->list_folder_files(
            $root_folder_id,
            "mimeType='application/vnd.google-apps.folder' and trashed=false",
            $with_debug
        );
        if ($with_debug && ! empty($list_result['debug']['errors'])) {
            return new WP_Error('oznamy_drive_folder_list_failed', implode(' ', $list_result['debug']['errors']));
        }
        $files = $list_result['files'] ?? array();

        $valid_folders = array();
        $invalid_folders = array();
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            $validation = $this->validate_announcement_folder_name($name);
            if ($validation['valid']) {
                $valid_folders[] = array(
                    'id' => (string) ($file['id'] ?? ''),
                    'name' => $name,
                    'date' => (int) $validation['date'],
                );
            } else {
                $invalid_folders[] = array(
                    'id' => (string) ($file['id'] ?? ''),
                    'name' => $name,
                    'error_code' => $validation['error_code'],
                );
            }
        }

        usort($valid_folders, static function ($a, $b) {
            return $b['date'] <=> $a['date'];
        });
        usort($invalid_folders, static function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return array('valid' => $valid_folders, 'invalid' => $invalid_folders);
    }

    /**
     * List valid announcement folders, sorted newest-first.
     *
     * @return array<int, array{id: string, name: string, date: int}>|WP_Error
     */
    public function list_subfolders_sorted($root_folder_id, $with_debug = false)
    {
        $result = $this->list_subfolders_with_validation($root_folder_id, $with_debug);
        return is_wp_error($result) ? $result : $result['valid'];
    }

    /**
    * List current or past announcement folders and an optional published future date.
     *
     * @param int $limit
    * @param string|null $include_future_date_key A single YYYY-MM-DD date currently published early.
     * @return array<int, array{id: string, name: string, date: int, date_key: string, ambiguous: bool}>|WP_Error
     */
    public function list_archive_subfolders($root_folder_id, $limit = 120, $include_future_date_key = null)
    {
        $list_result = $this->list_folder_files(
            $root_folder_id,
            "mimeType='application/vnd.google-apps.folder' and trashed=false",
            true
        );

        if (! empty($list_result['debug']['errors'])) {
            return new WP_Error('oznamy_archive_list_failed', implode(' ', $list_result['debug']['errors']));
        }

        try {
            $tz       = wp_timezone();
            $today_ts = (new DateTimeImmutable('today midnight', $tz))->getTimestamp();
        } catch (Exception $e) {
            $tz       = new DateTimeZone('UTC');
            $today_ts = (new DateTimeImmutable('today midnight', $tz))->getTimestamp();
        }

        $by_date = array();
        foreach ($list_result['files'] as $file) {
            $name = (string) ($file['name'] ?? '');
            $date = $this->parse_subfolder_date_from_name($name);
            if (null === $date) {
                continue;
            }

            $date_key = (new DateTimeImmutable('@' . $date))->setTimezone($tz)->format('Y-m-d');
            if ($date > $today_ts && $date_key !== $include_future_date_key) {
                continue;
            }

            if (isset($by_date[$date_key])) {
                $by_date[$date_key]['ambiguous'] = true;
                continue;
            }

            $by_date[$date_key] = array(
                'id'        => (string) ($file['id'] ?? ''),
                'name'      => $name,
                'date'      => $date,
                'date_key'  => $date_key,
                'ambiguous' => false,
            );
        }

        $folders = array_values($by_date);
        usort($folders, static function ($a, $b) {
            if ($a['date'] === $b['date']) {
                return strcmp($a['name'], $b['name']);
            }
            return $b['date'] <=> $a['date'];
        });

        return array_slice($folders, 0, max(1, absint($limit)));
    }

    /**
     * Build a recursive representation of the folder hierarchy.
     *
     * @return array{id: string, name: string, type: string, children: array<int, array>, is_root: bool}
     */
    private function build_folder_tree_node($folder_id, $folder_name, array &$visited, $is_root = false)
    {
        $folder_id = (string) $folder_id;
        $folder_name = (string) $folder_name;

        if (isset($visited[$folder_id])) {
            return array(
                'id'       => $folder_id,
                'name'     => $folder_name,
                'type'     => 'folder',
                'children' => array(),
                'is_root'  => (bool) $is_root,
                'loop'     => true,
            );
        }

        $visited[$folder_id] = true;

        $list_result = $this->list_folder_files($folder_id, 'trashed=false', false);
        $files       = $list_result['files'] ?? array();

        $children = array();
        foreach ($files as $file) {
            $mime_type = (string) ($file['mimeType'] ?? '');
            $child_id  = (string) ($file['id'] ?? '');
            $child_name = (string) ($file['name'] ?? '');

            if ('application/vnd.google-apps.folder' === $mime_type) {
                $children[] = $this->build_folder_tree_node($child_id, $child_name, $visited, false);
                continue;
            }

            $children[] = array(
                'id'       => $child_id,
                'name'     => $child_name,
                'type'     => 'file',
                'mimeType' => $mime_type,
            );
        }

        usort(
            $children,
            static function ($a, $b) {
                $type_a = $a['type'] ?? 'file';
                $type_b = $b['type'] ?? 'file';

                if ($type_a !== $type_b) {
                    return ('folder' === $type_a) ? -1 : 1;
                }

                return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }
        );

        return array(
            'id'       => $folder_id,
            'name'     => $folder_name,
            'type'     => 'folder',
            'children' => $children,
            'is_root'  => (bool) $is_root,
            'date'     => $this->parse_subfolder_date_from_name($folder_name),
        );
    }

    /**
     * Return the subfolder with the newest date that is today or in the past (WP local time).
     * Returns null if no qualifying subfolder is found.
     *
     * @return array{id: string, name: string, date: int}|null
     */
    public function find_best_subfolder($root_folder_id)
    {
        $subfolders = $this->list_subfolders_sorted($root_folder_id);
        if (empty($subfolders)) {
            return null;
        }

        try {
            $tz       = wp_timezone();
            $today_ts = (new DateTimeImmutable('today midnight', $tz))->getTimestamp();
        } catch (Exception $e) {
            $today_ts = strtotime('today midnight');
        }

        foreach ($subfolders as $folder) {
            // Subfolder date is start-of-day in local TZ; today == today_midnight → valid.
            if ($folder['date'] <= $today_ts) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Return the nearest future subfolder (first one strictly after today in WP local time).
     * Returns null if no future subfolder exists.
     *
     * @return array{id: string, name: string, date: int}|null|WP_Error
     */
    public function find_next_future_subfolder($root_folder_id)
    {
        $subfolders = $this->list_subfolders_sorted($root_folder_id, true);
        if (is_wp_error($subfolders)) {
            return $subfolders;
        }
        if (empty($subfolders)) {
            return null;
        }

        try {
            $tz       = wp_timezone();
            $today_ts = (new DateTimeImmutable('today midnight', $tz))->getTimestamp();
        } catch (Exception $e) {
            $today_ts = strtotime('today midnight');
        }

        $nearest = null;
        foreach ($subfolders as $folder) {
            if ($folder['date'] > $today_ts && (null === $nearest || $folder['date'] < $nearest['date'])) {
                $nearest = $folder;
            }
        }

        return $nearest;
    }

    /**
     * Find the subfolder matching $date_str (format DD.MM.YYYY), or null.
     *
     * @return array{id: string, name: string, date: int}|null
     */
    public function find_subfolder_by_date($root_folder_id, $date_str)
    {
        try {
            $tz = wp_timezone();
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        foreach ($this->list_subfolders_sorted($root_folder_id) as $folder) {
            $dt = (new DateTimeImmutable('@' . $folder['date']))->setTimezone($tz);
            if ($dt->format('d.m.Y') === $date_str) {
                return $folder;
            }
        }
        return null;
    }

    /**
     * Find the Google Doc named "Oznamy" inside $subfolder_id.
     * Falls back to the first Google Doc in the folder if no exact match is found.
     *
     * @return string|null Doc ID, or null if none found.
     */
    public function find_doc_in_subfolder($subfolder_id)
    {
        $list_result = $this->list_folder_files(
            $subfolder_id,
            "mimeType='application/vnd.google-apps.document' and trashed=false",
            false
        );
        $files = $list_result['files'] ?? array();

        foreach ($files as $file) {
            if (0 === strcasecmp(trim((string) ($file['name'] ?? '')), 'Oznamy')) {
                return (string) $file['id'];
            }
        }

        if (! empty($files)) {
            return (string) $files[0]['id'];
        }

        return null;
    }

    /**
     * Find a Google Doc while preserving Drive API errors for archive requests.
     *
     * @return string|null|WP_Error
     */
    public function find_doc_in_subfolder_result($subfolder_id)
    {
        $list_result = $this->list_folder_files(
            $subfolder_id,
            "mimeType='application/vnd.google-apps.document' and trashed=false",
            true
        );

        if (! empty($list_result['debug']['errors'])) {
            return new WP_Error('oznamy_archive_doc_list_failed', implode(' ', $list_result['debug']['errors']));
        }

        $files = $list_result['files'] ?? array();
        foreach ($files as $file) {
            if (0 === strcasecmp(trim((string) ($file['name'] ?? '')), 'Oznamy')) {
                return (string) $file['id'];
            }
        }

        return ! empty($files) ? (string) $files[0]['id'] : null;
    }

    /**
     * List image files (JPEG, PNG, GIF, WEBP) in $folder_id.
     *
     * @return array<int, array{id: string, name: string, mimeType: string}>
     */
    public function list_images_in_folder($folder_id)
    {
        $list_result = $this->list_folder_files($folder_id, 'trashed=false', false);
        $files       = $list_result['files'] ?? array();

        $image_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');

        $images = array();
        foreach ($files as $file) {
            if (in_array($file['mimeType'] ?? '', $image_mimes, true)) {
                $images[] = array(
                    'id'       => (string) $file['id'],
                    'name'     => (string) $file['name'],
                    'mimeType' => (string) $file['mimeType'],
                );
            }
        }

        return $images;
    }

    /**
     * List images and preserve Drive API errors for archive requests.
     *
     * @return array<int, array{id: string, name: string, mimeType: string, modifiedTime: string}>|WP_Error
     */
    public function list_images_in_folder_result($folder_id)
    {
        $list_result = $this->list_folder_files($folder_id, 'trashed=false', true);
        if (! empty($list_result['debug']['errors'])) {
            return new WP_Error('oznamy_archive_image_list_failed', implode(' ', $list_result['debug']['errors']));
        }

        $images = array();
        $image_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        foreach ($list_result['files'] as $file) {
            if (in_array($file['mimeType'] ?? '', $image_mimes, true)) {
                $images[] = array(
                    'id'           => (string) ($file['id'] ?? ''),
                    'name'         => (string) ($file['name'] ?? ''),
                    'mimeType'     => (string) ($file['mimeType'] ?? ''),
                    'modifiedTime' => (string) ($file['modifiedTime'] ?? ''),
                );
            }
        }

        return $images;
    }

    /**
     * Download an image from Google Drive and stream it directly to $destination_path.
     *
     * @return true|WP_Error
     */
    public function fetch_image_to_file($file_id, $destination_path)
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', (string) $file_id)) {
            return new WP_Error('oznamy_invalid_id', 'Neplatné ID súboru.');
        }

        $token = $this->get_access_token();
        if (! $token) {
            return new WP_Error('oznamy_no_token', 'Nepodarilo sa overiť pripojenie ku Google.');
        }

        if (! wp_mkdir_p(dirname($destination_path))) {
            return new WP_Error('oznamy_mkdir_failed', 'Nepodarilo sa vytvoriť priečinok medzipamäte pre obrázky.');
        }

        $url = add_query_arg(
            array(
                'alt'               => 'media',
                'supportsAllDrives' => 'true',
            ),
            self::DRIVE_BASE_URL . '/files/' . rawurlencode($file_id)
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout'  => 30,
                'headers'  => array('Authorization' => 'Bearer ' . $token),
                'stream'   => true,
                'filename' => $destination_path,
            )
        );

        if (is_wp_error($response)) {
            @unlink($destination_path);
            return new WP_Error('oznamy_fetch_failed', $response->get_error_message());
        }

        $http_status = wp_remote_retrieve_response_code($response);
        if (200 !== $http_status) {
            @unlink($destination_path);
            return new WP_Error('oznamy_fetch_http', 'HTTP ' . $http_status . ' pri sťahovaní obrázka.');
        }

        return true;
    }

    /**
     * Return a valid access token (for use by Oznamy_GDocs_Fetcher).
     */
    public function get_token()
    {
        return $this->get_access_token();
    }

    /**
     * Fetch Google Docs structured payload used by the rich renderer.
     *
     * @return array|WP_Error
     */
    public function get_doc_structure($doc_id)
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', (string) $doc_id)) {
            return new WP_Error('oznamy_invalid_id', 'Neplatné ID dokumentu.');
        }

        $result = $this->api_get(
            self::DOCS_BASE_URL,
            '/documents/' . rawurlencode($doc_id),
            array(
                'fields' => 'title,body/content,lists,namedStyles,inlineObjects',
            )
        );

        if (isset($result['error'])) {
            return new WP_Error('oznamy_docs_api_http_error', $result['error']);
        }

        if (! isset($result['body']) || ! is_array($result['body'])) {
            return new WP_Error('oznamy_docs_api_parse_error', 'Neplatné telo odpovede z Docs API.');
        }

        return $result['body'];
    }

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

    /**
     * Parse Oznamy_YYYY.MM.DD from a filename. Returns Unix timestamp or null.
     */
    public function parse_date_from_name($name)
    {
        // Accept only exact file names like "Oznamy_2026.07.17".
        if (preg_match('/^Oznamy_(\d{4})\.(\d{2})\.(\d{2})$/i', trim((string) $name), $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            $ts = gmmktime(0, 0, 0, $month, $day, $year);
            return false !== $ts ? $ts : null;
        }
        return null;
    }

    /**
     * Return a cached or freshly fetched access token.
     */
    private function get_access_token()
    {
        if (null !== $this->access_token) {
            return $this->access_token;
        }

        $cached = get_transient('oznamy_gtoken');
        if (false !== $cached) {
            $this->access_token = $cached;
            return $this->access_token;
        }

        $token = $this->fetch_access_token();
        if ($token) {
            // Tokens last 60 min; cache for 55 min to give a safety margin.
            set_transient('oznamy_gtoken', $token, 55 * MINUTE_IN_SECONDS);
            $this->access_token = $token;
        }

        return $this->access_token;
    }

    /**
     * Build a RS256-signed JWT and exchange it for an OAuth 2.0 access token.
     */
    private function fetch_access_token()
    {
        $sa = $this->sa_data;

        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            error_log('[Oznamy] Service Account JSON missing client_email or private_key.');
            return null;
        }

        $now   = time();
        $scope = 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/documents.readonly';

        $header  = $this->base64url_encode((string) wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $payload = $this->base64url_encode(
            (string) wp_json_encode(
                array(
                    'iss'   => $sa['client_email'],
                    'scope' => $scope,
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                )
            )
        );

        $signing_input = $header . '.' . $payload;
        $signature     = '';

        if (! openssl_sign($signing_input, $signature, $sa['private_key'], 'SHA256')) {
            error_log('[Oznamy] JWT signing failed: ' . openssl_error_string());
            return null;
        }

        $jwt = $signing_input . '.' . $this->base64url_encode($signature);

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 15,
                'body'    => array(
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ),
            )
        );

        if (is_wp_error($response)) {
            error_log('[Oznamy] Token request failed: ' . $response->get_error_message());
            return null;
        }

        $http_status = wp_remote_retrieve_response_code($response);
        if (200 !== $http_status) {
            error_log('[Oznamy] Token HTTP ' . $http_status . ': ' . wp_remote_retrieve_body($response));
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            error_log('[Oznamy] access_token missing in response: ' . wp_remote_retrieve_body($response));
            return null;
        }

        return $body['access_token'];
    }

    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Drop cached token so a subsequent request uses fresh scopes.
     */
    private function invalidate_access_token()
    {
        $this->access_token = null;
        delete_transient('oznamy_gtoken');
    }
}
