<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSFB_DB
{
    const CACHE_GROUP = 'msfb';

    public static function forms_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'msfb_forms';
    }

    public static function entries_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'msfb_entries';
    }

    public static function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $forms = self::forms_table();
        $entries = self::entries_table();

        dbDelta("CREATE TABLE {$forms} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            structure LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$entries} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            entry_data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charset};");
    }

    public static function save_form($title, $structure, $form_id = 0)
    {
        global $wpdb;
        $data = [
            'title'     => $title,
            'structure' => wp_json_encode($structure),
        ];

        if ($form_id) {
            $wpdb->update(self::forms_table(), $data, ['id' => $form_id], ['%s', '%s'], ['%d']);
            wp_cache_delete('form_' . $form_id, self::CACHE_GROUP);
            return $form_id;
        }

        $wpdb->insert(self::forms_table(), $data, ['%s', '%s']);
        return $wpdb->insert_id;
    }

    public static function get_form($form_id)
    {
        $form_id = absint($form_id);
        if (!$form_id) {
            return null;
        }

        $cached = wp_cache_get('form_' . $form_id, self::CACHE_GROUP);
        if (false !== $cached) {
            return $cached;
        }

        global $wpdb;
        $table = self::forms_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $form_id));

        if ($row) {
            $row->structure = json_decode($row->structure, true);
            if (!is_array($row->structure)) {
                $row->structure = [];
            }
            wp_cache_set('form_' . $form_id, $row, self::CACHE_GROUP, HOUR_IN_SECONDS);
        }

        return $row;
    }

    public static function get_forms()
    {
        global $wpdb;
        $table = self::forms_table();
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
    }

    public static function delete_form($form_id)
    {
        global $wpdb;
        $form_id = absint($form_id);
        $wpdb->delete(self::forms_table(), ['id' => $form_id], ['%d']);
        $wpdb->delete(self::entries_table(), ['form_id' => $form_id], ['%d']);
        wp_cache_delete('form_' . $form_id, self::CACHE_GROUP);
    }

    public static function save_entry($form_id, $entry_data)
    {
        global $wpdb;
        $wpdb->insert(self::entries_table(), [
            'form_id'    => absint($form_id),
            'entry_data' => wp_json_encode($entry_data),
        ], ['%d', '%s']);
        return $wpdb->insert_id;
    }

    public static function get_entries($form_id = 0)
    {
        global $wpdb;
        $table = self::entries_table();

        if ($form_id) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE form_id = %d ORDER BY id DESC", $form_id));
        } else {
            $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
        }

        foreach ($rows as $row) {
            $row->entry_data = json_decode($row->entry_data, true);
            if (!is_array($row->entry_data)) {
                $row->entry_data = [];
            }
        }
        return $rows;
    }

    public static function delete_entry($entry_id)
    {
        global $wpdb;
        $wpdb->delete(self::entries_table(), ['id' => absint($entry_id)], ['%d']);
    }
}
