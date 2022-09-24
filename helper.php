<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

/**
 * Class helper_plugin_discussion
 */
class helper_plugin_discussion extends DokuWiki_Plugin
{

    /**
     * @return array
     */
    public function getMethods()
    {
        $result = [];
        $result[] = [
            'name' => 'th',
            'desc' => 'returns the header of the comments column for pagelist',
            'return' => ['header' => 'string'],
        ];
        $result[] = [
            'name' => 'td',
            'desc' => 'returns the link to the discussion section with number of comments',
            'params' => [
                'id' => 'string',
                'number of comments (optional)' => 'integer'],
            'return' => ['link' => 'string'],
        ];
        $result[] = [
            'name' => 'getThreads',
            'desc' => 'returns pages with discussion sections, sorted by recent comments',
            'params' => [
                'namespace' => 'string',
                'number (optional)' => 'integer'],
            'return' => ['pages' => 'array'],
        ];
        $result[] = [
            'name' => 'getComments',
            'desc' => 'returns recently added or edited comments individually',
            'params' => [
                'namespace' => 'string',
                'number (optional)' => 'integer'],
            'return' => ['pages' => 'array'],
        ];
        $result[] = [
            'name' => 'isDiscussionModerator',
            'desc' => 'check if current user is member of moderator groups',
            'params' => [],
            'return' => ['isModerator' => 'boolean']
        ];
        return $result;
    }

    /**
     * Returns the column header for the Pagelist Plugin
     *
     * @return string
     */
    public function th()
    {
        return $this->getLang('discussion');
    }

    /**
     * Returns the link to the discussion section of a page
     *
     * @param string $id page id
     * @param string $col column name, used if more columns needed per plugin
     * @param string $class class name per cell set by reference
     * @param null|int $num number of visible comments -- internally used, not by pagelist plugin
     * @return string
     */
    public function td($id, $col = null, &$class = null, $num = null)
    {
        $section = '#discussion__section';

        if (!isset($num)) {
            $cfile = metaFN($id, '.comments');
            $comments = unserialize(io_readFile($cfile, false));

            $num = $comments['number'];
            if (!$comments['status'] || ($comments['status'] == 2 && !$num)) {
                return '';
            }
        }

        if ($num == 0) {
            $comment = '0&nbsp;' . $this->getLang('nocomments');
        } elseif ($num == 1) {
            $comment = '1&nbsp;' . $this->getLang('comment');
        } else {
            $comment = $num . '&nbsp;' . $this->getLang('comments');
        }

        return '<a href="' . wl($id) . $section . '" class="wikilink1" title="' . $id . $section . '">'
            . $comment
            . '</a>';
    }

    /**
     * Returns an array of pages with discussion sections, sorted by recent comments
     * Note: also used for content by Feed Plugin
     *
     * @param string $ns
     * @param null|int $num
     * @param string|bool $skipEmpty
     * @return array
     */
    public function getThreads($ns, $num = null, $skipEmpty = false)
    {
        global $conf;

        // returns the list of pages in the given namespace and it's subspaces
        $dir =  utf8_encodeFN(str_replace(':', '/', $ns));
        $opts = [
            'depth' => 0, // 0=all
            'skipacl' => true // is checked later
        ];
        $items = [];
        search($items, $conf['datadir'], 'search_allpages', $opts, $dir);

        // add pages with comments to result
        $result = [];
        foreach ($items as $item) {
            $id = $item['id'];

            // some checks
            $perm = auth_quickaclcheck($id);
            if ($perm < AUTH_READ) continue;    // skip if no permission
            $file = metaFN($id, '.comments');
            if (!@file_exists($file)) continue; // skip if no comments file
            $data = unserialize(io_readFile($file, false));
            $status = $data['status'];
            $number = $data['number'];

            if (!$status || ($status == 2 && !$number)) continue; // skip if comments are off or closed without comments
            if ($skipEmpty && $number == 0) continue; // skip if discussion is empty and flag is set

            //new comments are added to the end of array
            $date = false;
            if(isset($data['comments'])) {
                $latestcomment = end($data['comments']);
                $date = $latestcomment['date']['created'] ?? false;
            }
            //e.g. if no comments
            if(!$date) {
                $date = filemtime($file);
            }

            $meta = p_get_metadata($id);
            $result[$date . '_' . $id] = [
                'id' => $id,
                'file' => $file,
                'title' => $meta['title'],
                'date' => $date,
                'user' => $meta['creator'],
                'desc' => $meta['description']['abstract'],
                'num' => $number,
                'comments' => $this->td($id, null, $class, $number),
                'status' => $status,
                'perm' => $perm,
                'exists' => true,
                'anchor' => 'discussion__section',
            ];
        }

        // finally sort by time of last comment
        krsort($result);

        if (is_numeric($num)) {
            $result = array_slice($result, 0, $num);
        }

        return $result;
    }

    /**
     * Returns an array of recently added comments to a given page or namespace
     * Note: also used for content by Feed Plugin
     *
     * @param string $ns
     * @param int|null $num number of comment per page
     * @return array
     */
    public function getComments($ns, $num = null)
    {
        global $conf, $INPUT;

        $first = $INPUT->int('first');

        if (!$num || !is_numeric($num)) {
            $num = $conf['recent'];
        }

        $result = [];
        $count = 0;

        if (!@file_exists($conf['metadir'] . '/_comments.changes')) {
            return $result;
        }

        // read all recent changes. (kept short)
        $lines = file($conf['metadir'] . '/_comments.changes');

        $seen = []; //caches seen pages in order to skip them
        // handle lines
        $line_num = count($lines);
        for ($i = ($line_num - 1); $i >= 0; $i--) {
            $rec = $this->handleRecentComment($lines[$i], $ns, $seen);
            if ($rec !== false) {
                if (--$first >= 0) continue; // skip first entries

                $result[$rec['date']] = $rec;
                $count++;
                // break when we have enough entries
                if ($count >= $num) break;
            }
        }

        // finally sort by time of last comment
        krsort($result);

        return $result;
    }

    /* ---------- Changelog function adapted for the Discussion Plugin ---------- */

    /**
     * Internal function used by $this->getComments()
     *
     * don't call directly
     *
     * @param string $line comment changelog line
     * @param string $ns namespace (or id) to filter
     * @param array $seen array to cache seen pages
     * @return array|false with
     *  'type' => string,
     *  'extra' => string comment id,
     *  'id' => string page id,
     *  'perm' => int ACL permission
     *  'file' => string file path of wiki page
     *  'exists' => bool wiki page exists
     *  'name' => string name of user
     *  'desc' => string text of comment
     *  'anchor' => string
     *
     * @see getRecentComments()
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Ben Coburn <btcoburn@silicodon.net>
     * @author Esther Brunner <wikidesign@gmail.com>
     *
     */
    protected function handleRecentComment($line, $ns, &$seen)
    {
        if (empty($line)) return false;  //skip empty lines

        // split the line into parts
        $recent = parseChangelogLine($line);
        if ($recent === false) return false;

        $cid = $recent['extra'];
        $fullcid = $recent['id'] . '#' . $recent['extra'];

        // skip seen ones
        if (isset($seen[$fullcid])) return false;

        // skip 'show comment' log entries
        if ($recent['type'] === 'sc') return false;

        // remember in seen to skip additional sights
        $seen[$fullcid] = 1;

        // check if it's a hidden page or comment
        if (isHiddenPage($recent['id'])) return false;
        if ($recent['type'] === 'hc') return false;

        // filter namespace or id
        if ($ns && strpos($recent['id'] . ':', $ns . ':') !== 0) return false;

        // check ACL
        $recent['perm'] = auth_quickaclcheck($recent['id']);
        if ($recent['perm'] < AUTH_READ) return false;

        // check existance
        $recent['file'] = wikiFN($recent['id']);
        $recent['exists'] = @file_exists($recent['file']);
        if (!$recent['exists']) return false;
        if ($recent['type'] === 'dc') return false;

        // get discussion meta file name
        $data = unserialize(io_readFile(metaFN($recent['id'], '.comments'), false));

        // check if discussion is turned off
        if ($data['status'] === 0) return false;

        $parent_id = $cid;
        // Check for the comment and all parents if they exist and are visible.
        do {
            $tcid = $parent_id;

            // check if the comment still exists
            if (!isset($data['comments'][$tcid])) return false;
            // check if the comment is visible
            if ($data['comments'][$tcid]['show'] != 1) return false;

            $parent_id = $data['comments'][$tcid]['parent'];
        } while ($parent_id && $parent_id != $tcid);

        // okay, then add some additional info
        if (is_array($data['comments'][$cid]['user'])) {
            $recent['name'] = $data['comments'][$cid]['user']['name'];
        } else {
            $recent['name'] = $data['comments'][$cid]['name'];
        }
        $recent['desc'] = strip_tags($data['comments'][$cid]['xhtml']);
        $recent['anchor'] = 'comment_' . $cid;

        return $recent;
    }

    /**
     * Check if current user is member of the moderator groups
     *
     * @return bool is moderator?
     */
    public function isDiscussionModerator()
    {
        global $USERINFO, $INPUT;
        $groups = trim($this->getConf('moderatorgroups'));

        if (auth_ismanager()) {
            return true;
        }
        // Check if user is member of the moderator groups
        if (!empty($groups) && auth_isMember($groups, $INPUT->server->str('REMOTE_USER'), (array)$USERINFO['grps'])) {
            return true;
        }

        return false;
    }
}
