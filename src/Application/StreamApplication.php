<?php

declare(strict_types=0);

/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright 2001 - 2020 Ampache.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Ampache\Application;

use Ampache\Module\Authorization\Access;
use Ampache\Model\Album;
use Ampache\Module\Util\InterfaceImplementationChecker;
use Ampache\Module\Util\ObjectTypeToClassNameMapper;
use AmpConfig;
use Artist;
use Core;
use Democratic;
use Playlist;
use Ampache\Module\System\Session;
use Ampache\Module\Playback\Stream;
use Ampache\Module\Playback\Stream_Playlist;
use Tmp_Playlist;
use Ampache\Module\Util\Ui;
use User;

final class StreamApplication implements ApplicationInterface
{
    public function run(): void
    {
        if (!Core::get_request('action')) {
            debug_event('stream', "Asked without action. Exiting...", 5);

            return;
        }

        if (!defined('NO_SESSION')) {
            /* If we are running a demo, quick while you still can! */
            if (AmpConfig::get('demo_mode') || (AmpConfig::get('use_auth')) && !Access::check('interface', 25)) {
                Ui::access_denied();

                return;
            }
        }

        $media_ids = array();
        $web_path  = AmpConfig::get('web_path');

        debug_event('stream', "Asked for {" . Core::get_request('action') . "}.", 5);

        // Switch on the actions
        switch ($_REQUEST['action']) {
            case 'basket':
                // Pull in our items (multiple types)
                $media_ids = Core::get_global('user')->playlist->get_items();

                // Check to see if 'clear' was passed if it was then we need to reset the basket
                if (($_REQUEST['playlist_method'] == 'clear' || AmpConfig::get('playlist_method') == 'clear')) {
                    Core::get_global('user')->playlist->clear();
                }
                break;
            /* This is run if we need to gather info based on a tmp playlist */
            case 'tmp_playlist':
                $tmp_playlist = new Tmp_Playlist($_REQUEST['tmpplaylist_id']);
                $media_ids    = $tmp_playlist->get_items();
                break;
            case 'play_favorite':
                $data      = Core::get_global('user')->get_favorites((string) filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS));
                $media_ids = array();
                switch ((string) filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS)) {
                    case 'artist':
                    case 'album':
                        foreach ($data as $value) {
                            $songs     = $value->get_songs();
                            $media_ids = array_merge($media_ids, $songs);
                        }
                        break;
                    case 'song':
                        foreach ($data as $value) {
                            $media_ids[] = $value->id;
                        }
                        break;
                } // end switch on type
                break;
            case 'play_item':
                $object_type = $_REQUEST['object_type'];
                $object_ids  = explode(',', Core::get_get('object_id'));

                if (InterfaceImplementationChecker::is_playable_item($object_type)) {
                    foreach ($object_ids as $object_id) {
                        $class_name = ObjectTypeToClassNameMapper::map($object_type);
                        $item       = new $class_name($object_id);
                        $media_ids  = array_merge($media_ids, $item->get_medias());

                        if ($_REQUEST['custom_play_action']) {
                            foreach ($media_ids as $media_id) {
                                if (is_array($media_id)) {
                                    $media_id['custom_play_action'] = $_REQUEST['custom_play_action'];
                                }
                            }
                        }
                    }
                }
                break;
            case 'artist_random':
                $artist    = new Artist($_REQUEST['artist_id']);
                $media_ids = $artist->get_random_songs();
                break;
            case 'album_random':
                $album     = new Album($_REQUEST['album_id']);
                $media_ids = $album->get_random_songs();
                break;
            case 'playlist_random':
                $playlist  = new Playlist($_REQUEST['playlist_id']);
                $media_ids = $playlist->get_random_items();
                break;
            case 'random':
                /**
                 * @deprecated Not in use (get_random_songs is undefined)
                 */
                $matchlist = array();
                if ($_REQUEST['genre'][0] != '-1') {
                    $matchlist['genre'] = $_REQUEST['genre'];
                }
                if (Core::get_request('catalog') != '-1') {
                    $matchlist['catalog'] = Core::get_request('catalog');
                }
                /* Setup the options array */
                $options   = array('limit' => $_REQUEST['random'], 'random_type' => $_REQUEST['random_type'], 'size_limit' => $_REQUEST['size_limit']);
                $media_ids = get_random_songs($options, $matchlist);
                break;
            case 'democratic':
                $democratic = new Democratic($_REQUEST['democratic_id']);
                $urls       = array($democratic->play_url());
                break;
            case 'download':
                if (isset($_REQUEST['song_id'])) {
                    $media_ids[] = array(
                        'object_type' => 'song',
                        'object_id' => scrub_in($_REQUEST['song_id'])
                    );
                } elseif (isset($_REQUEST['video_id'])) {
                    $media_ids[] = array(
                        'object_type' => 'video',
                        'object_id' => scrub_in($_REQUEST['video_id'])
                    );
                } elseif (isset($_REQUEST['podcast_episode_id'])) {
                    $media_ids[] = array(
                        'object_type' => 'podcast_episode',
                        'object_id' => scrub_in($_REQUEST['podcast_episode_id'])
                    );
                }
                break;
            default:
                break;
        } // end action switch

        // Switch on the actions
        switch ($_REQUEST['action']) {
            case 'download':
                $stream_type = 'download';
                break;
            case 'democratic':
                // Don't let them loop it
                // FIXME: This looks hacky
                if (AmpConfig::get('play_type') == 'democratic') {
                    AmpConfig::set('play_type', 'stream', true);
                }
            default:
                $stream_type = AmpConfig::get('play_type');

                if ($stream_type == 'stream') {
                    $stream_type = AmpConfig::get('playlist_type');
                }
                break;
        }

        debug_event('stream', 'Stream Type: ' . $stream_type . ' Media IDs: ' . json_encode($media_ids), 5);

        if (count($media_ids) || isset($urls)) {
            if ($stream_type != 'democratic') {
                if (!User::stream_control($media_ids)) {
                    debug_event('stream', 'Stream control failed for user ' . Core::get_global('user')->username, 3);
                    Ui::access_denied();

                    return;
                }
            }

            if (Core::get_global('user')->id > -1) {
                Session::update_username(Stream::get_session(), Core::get_global('user')->username);
            }

            $playlist = new Stream_Playlist();
            $playlist->add($media_ids);
            if (isset($urls)) {
                $playlist->add_urls($urls);
            }
            // Depending on the stream type, will either generate a redirect or actually do the streaming.
            $playlist->generate_playlist($stream_type, false);
        } else {
            debug_event('stream.php', 'No item. Ignoring...', 5);
        }
    }
}
