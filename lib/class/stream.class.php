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

class Stream
{
    public static $session;

    private function __construct()
    {
        // Static class, do nothing.
    }

    /**
     * set_session
     *
     * This overrides the normal session value, without adding
     * an additional session into the database, should be called
     * with care
     */
    public static function set_session($sid)
    {
        self::$session=$sid;
    } // set_session

    public static function get_allowed_bitrate($song)
    {
        $max_bitrate = AmpConfig::get('max_bit_rate');
        $min_bitrate = AmpConfig::get('min_bit_rate');
        // FIXME: This should be configurable for each output type
        $user_sample_rate = AmpConfig::get('sample_rate');

        // If the user's crazy, that's no skin off our back
        if ($user_sample_rate < $min_bitrate) {
            $min_bitrate = $user_sample_rate;
        }

        // Are there site-wide constraints? (Dynamic downsampling.)
        if ($max_bitrate > 1) {
            $sql = 'SELECT COUNT(*) FROM `now_playing` ' .
                'WHERE `user` IN ' .
                '(SELECT DISTINCT `user_preference`.`user` ' .
                'FROM `preference` JOIN `user_preference` ' .
                'ON `preference`.`id` = ' .
                '`user_preference`.`preference` ' .
                "WHERE `preference`.`name` = 'play_type' " .
                "AND `user_preference`.`value` = 'downsample')";

            $db_results = Dba::read($sql);
            $results = Dba::fetch_row($db_results);

            $active_streams = intval($results[0]) ?: 0;
            debug_event('stream', 'Active transcoding streams: ' . $active_streams, 5);

            // We count as one for the algorithm
            // FIXME: Should this reflect the actual bit rates?
            $active_streams++;
            $sample_rate = floor($max_bitrate / $active_streams);

            // Exit if this would be insane
            if ($sample_rate < ($min_bitrate ?: 8)) {
                debug_event('stream', 'Max transcode bandwidth already allocated. Active streams: ' . $active_streams, 2);
                header('HTTP/1.1 503 Service Temporarily Unavailable');
                exit();
            }

            // Never go over the user's sample rate
            if ($sample_rate > $user_sample_rate) {
                $sample_rate = $user_sample_rate;
            }
        } // end if we've got bitrates
        else {
            $sample_rate = $user_sample_rate;
        }

        return $sample_rate;
    }

    /**
     * start_transcode
     *
     * This is a rather complex function that starts the transcoding or
     * resampling of a song and returns the opened file handle.
     */
    public static function start_transcode($song, $type = null, $bitrate=0)
    {
        debug_event('stream.class.php', 'Starting transcode for {'.$song->file.'}. Type {'.$type.'}. Bitrate {'.$bitrate.'}...', 5);
        
        $transcode_settings = $song->get_transcode_settings($type);
        // Bail out early if we're unutterably broken
        if ($transcode_settings == false) {
            debug_event('stream', 'Transcode requested, but get_transcode_settings failed', 2);
            return false;
        }

        if ($bitrate == 0) {
            $sample_rate = self::get_allowed_bitrate($song);
            debug_event('stream', 'Configured bitrate is ' . $sample_rate, 5);
            // Validate the bitrate
            $sample_rate = self::validate_bitrate($sample_rate);
        } else {
            $sample_rate = $bitrate;
        }

        // Never upsample a song
        if ($song->type == $transcode_settings['format'] && ($sample_rate * 1000) > $song->bitrate) {
            debug_event('stream', 'Clamping bitrate to avoid upsampling to ' . $sample_rate, 5);
            $sample_rate = self::validate_bitrate($song->bitrate / 1000);
        }

        debug_event('stream', 'Final transcode bitrate is ' . $sample_rate, 5);

        $song_file = scrub_arg($song->file);

        // Finalise the command line
        $command = $transcode_settings['command'];

        $string_map = array(
            '%FILE%'   => $song_file,
            '%SAMPLE%' => $sample_rate
        );

        foreach ($string_map as $search => $replace) {
            $command = str_replace($search, $replace, $command, $ret);
            if (!$ret) {
                debug_event('downsample', "$search not in downsample command", 5);
            }
        }

        debug_event('downsample', "Downsample command: $command", 3);

        $descriptors = array(1 => array('pipe', 'w'));
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            // Windows doesn't like to provide stderr as a pipe
            $descriptors[2] = array('pipe', 'w');
        }

        $process = proc_open($command, $descriptors, $pipes);
        return array(
            'process' => $process,
            'handle' => $pipes[1],
            'stderr' => $pipes[2],
            'format' => $transcode_settings['format']
        );
    }

    /**
     * validate_bitrate
     * this function takes a bitrate and returns a valid one
     */
    public static function validate_bitrate($bitrate)
    {
        /* Round to standard bitrates */
        $sample_rate = 16*(floor($bitrate/16));

        return $sample_rate;
    }

    /**
     * gc_now_playing
     *
     * This will garbage collect the now playing data,
     * this is done on every play start.
     */
    public static function gc_now_playing()
    {
        // Remove any now playing entries for sessions that have been GC'd
        $sql = "DELETE FROM `now_playing` USING `now_playing` " .
            "LEFT JOIN `session` ON `session`.`id` = `now_playing`.`id` " .
            "WHERE `session`.`id` IS NULL OR `now_playing`.`expire` < '" . time() . "'";
        $db_results = Dba::write($sql);
    }

    /**
     * insert_now_playing
     *
     * This will insert the now playing data.
     */
    public static function insert_now_playing($oid, $uid, $length, $sid, $type)
    {
        $time = intval(time() + $length);
        $type = strtolower($type);

        // Ensure that this client only has a single row
        $sql = 'REPLACE INTO `now_playing` ' .
            '(`id`,`object_id`,`object_type`, `user`, `expire`, `insertion`) ' .
            'VALUES (?, ?, ?, ?, ?, ?)';
        $db_result = Dba::write($sql, array($sid, $oid, $type, $uid, $time, time()));
    }

     /**
      * clear_now_playing
      *
      * There really isn't anywhere else for this function, shouldn't have
      * deleted it in the first place.
      */
    public static function clear_now_playing()
    {
        $sql = 'TRUNCATE `now_playing`';
        $db_results = Dba::write($sql);

        return true;
    }

    /**
     * get_now_playing
     *
     * This returns the now playing information
     */
    public static function get_now_playing()
    {
        $maxsql = '';
        if (AmpConfig::get('now_playing_per_user')) {
            $maxsql = ', ';
        }
        $sql = 'SELECT `session`.`agent`, `np`.* FROM `now_playing` AS `np` ';
        $sql .= 'LEFT JOIN `session` ON `session`.`id` = `np`.`id` ';

        if (AmpConfig::get('now_playing_per_user')) {
            $sql .= 'INNER JOIN ( ' .
                'SELECT MAX(`insertion`) AS `max_insertion`, `user`, `id` ' .
                'FROM `now_playing` ' .
                'GROUP BY `user`' .
                ') `np2` ' .
                'ON `np`.`user` = `np2`.`user` ' .
                'AND `np`.`insertion` = `np2`.`max_insertion` ';
        }

        if (!Access::check('interface','100')) {
            // We need to check only for users which have allowed view of personnal info
            $personal_info_id = Preference::id_from_name('allow_personal_info_now');
            if ($personal_info_id) {
                $current_user = $GLOBALS['user']->id;
                $sql .= "WHERE (`np`.`user` IN (SELECT `user` FROM `user_preference` WHERE ((`preference`='$personal_info_id' AND `value`='1') OR `user`='$current_user'))) ";
            }
        }

        $sql .= 'ORDER BY `np`.`expire` DESC';
        $db_results = Dba::read($sql);

        $results = array();

        while ($row = Dba::fetch_assoc($db_results)) {
            $type = $row['object_type'];
            $media = new $type($row['object_id']);
            $media->format();
            $client = new User($row['user']);
            $results[] = array(
                'media' => $media,
                'client' => $client,
                'agent' => $row['agent'],
                'expire' => $row['expire']
            );
        } // end while

        return $results;

    } // get_now_playing

    /**
     * check_lock_media
     *
     * This checks to see if the media is already being played.
     */
    public static function check_lock_media($media_id, $type)
    {
        $sql = 'SELECT `object_id` FROM `now_playing` WHERE ' .
            '`object_id` = ? AND `object_type` = ?';
        $db_results = Dba::read($sql, array($media_id, $type));

        if (Dba::num_rows($db_results)) {
            debug_event('Stream', 'Unable to play media currently locked by another user', 3);
            return false;
        }

        return true;
    }

    /**
     * auto_init
     * This is called on class load it sets the session
     */
    public static function _auto_init()
    {
        // Generate the session ID.  This is slightly wasteful.
        $data = array();
        $data['type'] = 'stream';
        if (isset($_REQUEST['client'])) {
            $data['agent'] = $_REQUEST['client'];
        }
        self::$session = Session::create($data);
    }

    /**
     * run_playlist_method
     *
     * This takes care of the different types of 'playlist methods'. The
     * reason this is here is because it deals with streaming rather than
     * playlist mojo. If something needs to happen this will echo the
     * javascript required to cause a reload of the iframe.
     */
    public static function run_playlist_method()
    {
        // If this wasn't ajax included run away
        if (!defined('AJAX_INCLUDE')) { return false; }

        switch (AmpConfig::get('playlist_method')) {
            default:
            case 'clear':
            case 'default':
                return true;
            break;
            case 'send':
                $_SESSION['iframe']['target'] = AmpConfig::get('web_path') . '/stream.php?action=basket';
            break;
            case 'send_clear':
                $_SESSION['iframe']['target'] = AmpConfig::get('web_path') . '/stream.php?action=basket&playlist_method=clear';
            break;
        } // end switch on method

        // Load our javascript
        echo "<script type=\"text/javascript\">";
        echo "reloadUtil('".$_SESSION['iframe']['target']."');";
        echo "</script>";

    } // run_playlist_method

    /**
     * get_base_url
     * This returns the base requirements for a stream URL this does not include anything after the index.php?sid=????
     */
    public static function get_base_url()
    {
        if (AmpConfig::get('require_session')) {
            $session_string = 'ssid=' . self::$session . '&';
        }

        $web_path = AmpConfig::get('web_path');

        if (AmpConfig::get('force_http_play') OR !empty(self::$force_http)) {
            $web_path = str_replace("https://", "http://",$web_path);
        }
        if (AmpConfig::get('http_port') != '80') {
            if (preg_match("/:(\d+)/",$web_path,$matches)) {
                $web_path = str_replace(':' . $matches['1'],':' . AmpConfig::get('http_port'),$web_path);
            } else {
                $web_path = str_replace(AmpConfig::get('http_host'), AmpConfig::get('http_host') . ':' . AmpConfig::get('http_port'), $web_path);
            }
        }

        $url = $web_path . "/play/index.php?$session_string";

        return $url;

    } // get_base_url

} //end of stream class
