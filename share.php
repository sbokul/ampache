<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2013 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

if (empty($action) || $action == 'stream' || $action == 'download') {
    define('NO_SESSION', '1');
}
require_once 'lib/init.php';

Preference::init();

if (!AmpConfig::get('share')) {
    debug_event('UI::access_denied', 'Access Denied: sharing features are not enabled.', '3');
    UI::access_denied();
    exit();
}

switch ($action) {
    case 'show_create':
        UI::show_header();

        $type = Share::format_type($_REQUEST['type']);
        if (!empty($type) && !empty($_REQUEST['id'])) {
            $oid = $_REQUEST['id'];
            if (is_array($oid)) {
                $oid = $oid[0];
            }
        
            $object = new $type($oid);
            if ($object->id) {
                $object->format();
                require_once AmpConfig::get('prefix') . '/templates/show_add_share.inc.php';
            }
        }
        UI::show_footer();
        exit();
    break;
    case 'create':
        if (AmpConfig::get('demo_mode')) {
            UI::access_denied();
            exit;
        }

        if (!Core::form_verify('add_share','post')) {
            UI::access_denied();
            exit;
        }

        UI::show_header();
        $id = Share::create_share($_REQUEST['type'], $_REQUEST['id'], $_REQUEST['allow_stream'], $_REQUEST['allow_download'], $_REQUEST['expire'], $_REQUEST['secret'], $_REQUEST['max_counter']);

        if (!$id) {
            require_once AmpConfig::get('prefix') . '/templates/show_add_share.inc.php';
        } else {
            $share = new Share($id);
            $body = T_('Share created.') . '<br />' .
                T_('You can now start sharing the following url:') . '<br />' .
                '<a href="' . $share->public_url . '" target="_blank">' . $share->public_url . '</a><br />' .
                '<div id="share_qrcode" style="text-align: center"></div>' .
                '<script language="javascript" type="text/javascript">$(\'#share_qrcode\').qrcode({text: "' . $share->public_url .'", width: 128, height: 128});</script>' .
                '<br /><br />' .
                T_('You can also embed this share as a web player into your website, with the following html code:') . '<br />' .
                '<i>' . htmlentities('<iframe style="width: 630px; height: 75px;" src="' . Share::get_url($share->id, $share->secret) . '&embed=true"></iframe>') . '</i><br />';

            $title = T_('Object Shared');
            show_confirmation($title, $body, AmpConfig::get('web_path') . '/stats.php?action=share');
        }
        UI::show_footer();
        exit();
    break;
    case 'show_delete':
        UI::show_header();
        $id = $_REQUEST['id'];

        $next_url = AmpConfig::get('web_path') . '/share.php?action=delete&id=' . scrub_out($id);
        show_confirmation(T_('Share Delete'), T_('Confirm Deletion Request'), $next_url, 1, 'delete_share');
        UI::show_footer();
        exit();
    break;
    case 'delete':
        if (AmpConfig::get('demo_mode')) {
            UI::access_denied();
            exit;
        }

        UI::show_header();
        $id = $_REQUEST['id'];
        if (Share::delete_share($id)) {
            $next_url = AmpConfig::get('web_path') . '/stats.php?action=share';
            show_confirmation(T_('Share Deleted'), T_('The Share has been deleted'), $next_url);
        }
        UI::show_footer();
        exit();
    break;
}

/**
 * If Access Control is turned on then we don't
 * even want them to be able to get to the login
 * page if they aren't in the ACL
 */
if (AmpConfig::get('access_control')) {
    if (!Access::check_network('interface', '', '5')) {
        debug_event('UI::access_denied', 'Access Denied:' . $_SERVER['REMOTE_ADDR'] . ' is not in the Interface Access list', '3');
        UI::access_denied();
        exit();
    }
} // access_control is enabled

$id = $_REQUEST['id'];
$secret = $_REQUEST['secret'];

$share = new Share($id);
if (empty($action) && $share->id) {
    if ($share->allow_stream) {
        $action = 'stream';
    } elseif ($share->allow_download) {
        $action = 'download';
    }
}

if (!$share->is_valid($secret, $action)) {
    UI::access_denied();
    exit();
}

$share->format();

$share->save_access();
if ($action == 'download') {
    if ($share->object_type == 'song' || $share->object_type == 'video') {
        $_REQUEST['action'] = 'download';
        $_REQUEST['type'] = $share->object_type;
        $_REQUEST[$share->object_type . '_id'] = $share->object_id;
        require AmpConfig::get('prefix') . '/stream.php';
    } else {
        $_REQUEST['action'] = $share->object_type;
        $_REQUEST['id'] = $share->object_id;
        require AmpConfig::get('prefix') . '/batch.php';
    }
} elseif ($action == 'stream') {
    require AmpConfig::get('prefix') . '/templates/show_share.inc.php';
} else {
    debug_event('UI::access_denied', 'Access Denied: unknown action.', '3');
    UI::access_denied();
    exit();
}
