<?php
namespace nntmux;

use app\models\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use nntmux\db\DB;
use nntmux\utility\Utility;
use nntmux\processing\tv\TraktTv;
use Tmdb\ApiToken;
use Tmdb\Client as TmdbClient;
use Tmdb\Exception\TmdbApiException;

/**
 * Class Movie
 */
class Movie
{

	const SRC_BOXOFFICE = 1;
	const SRC_INTHEATRE = 2;
	const SRC_OPENING = 3;
	const SRC_UPCOMING = 4;
	const SRC_DVD = 5;

	public $pdo;

	/**
	 * Current title being passed through various sites/api's.
	 * @var string
	 */
	protected $currentTitle = '';

	/**
	 * Current year of parsed search name.
	 * @var string
	 */
	protected $currentYear  = '';

	/**
	 * Current release id of parsed search name.
	 *
	 * @var string
	 */
	protected $currentRelID = '';

	/**
	 * @var Logger
	 */
	protected $debugging;

	/**
	 * @var bool
	 */
	protected $debug;

	/**
	 * Use search engines to find IMDB id's.
	 * @var bool
	 */
	protected $searchEngines;

	/**
	 * How many times have we hit google this session.
	 * @var int
	 */
	protected $googleLimit = 0;

	/**
	 * If we are temp banned from google, set time we were banned here, try again after 10 minutes.
	 * @var int
	 */
	protected $googleBan = 0;

	/**
	 * How many times have we hit bing this session.
	 *
	 * @var int
	 */
	protected $bingLimit = 0;

	/**
	 * How many times have we hit yahoo this session.
	 *
	 * @var int
	 */
	protected $yahooLimit = 0;

	/**
	 * @var string
	 */
	protected $showPasswords;

	/**
	 * @var ReleaseImage
	 */
	protected $releaseImage;

	/**
	 * @var \Tmdb\Client
	 */
	protected $tmdbclient;

	/**
	 * @var Client
	 */
	protected $client;

	/**
	 * Language to fetch from IMDB.
	 * @var string
	 */
	protected $lookuplanguage;

	/**
	 * @var array|bool|string
	 */
	public $fanartapikey;

	/**
	 * @var bool
	 */
	public $imdburl;

	/**
	 * @var array|bool|int|string
	 */
	public $movieqty;

	/**
	 * @var bool
	 */
	public $echooutput;

	/**
	 * @var string
	 */
	public $imgSavePath;

	/**
	 * @var string
	 */
	public $service;

	/**
	 * @var array|bool|int|string
	 */
	public $catWhere;

	/**
	 * @param array $options Class instances / Echo to CLI.
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Echo'         => false,
			'Logger'    => null,
			'ReleaseImage' => null,
			'Settings'     => null,
			'TMDb'         => null,
		];
		$options += $defaults;

		$this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
		$this->releaseImage = ($options['ReleaseImage'] instanceof ReleaseImage ? $options['ReleaseImage'] : new ReleaseImage($this->pdo));
		$this->client = new Client();
		$this->tmdbtoken = new ApiToken(Settings::value('APIs..tmdbkey'));
		$this->tmdbclient = new TmdbClient($this->tmdbtoken);

		$this->lookuplanguage = (Settings::value('indexer.categorise.imdblanguage') != '') ? (string)Settings::value('indexer.categorise.imdblanguage') : 'en';

		$this->fanartapikey = Settings::value('APIs..fanarttvkey');
		$this->imdburl = (Settings::value('indexer.categorise.imdburl') == 0 ? false : true);
		$this->movieqty = (Settings::value('..maximdbprocessed') != '') ? Settings::value('..maximdbprocessed') : 100;
		$this->searchEngines = true;
		$this->showPasswords = Releases::showPasswords();

		$this->debug = NN_DEBUG;
		$this->echooutput = ($options['Echo'] && NN_ECHOCLI && $this->pdo->cli);
		$this->imgSavePath = NN_COVERS . 'movies' . DS;
		$this->service = '';
		$this->catWhere = 'AND categories_id BETWEEN ' . Category::MOVIE_ROOT . ' AND ' . Category::MOVIE_OTHER;

		if (NN_DEBUG || NN_LOGGING) {
			$this->debug = true;
			try {
				$this->debugging = new Logger();
			} catch (LoggerException $error) {
				$this->_debug = false;
			}
		}
	}

	/**
	 * Get info for a IMDB id.
	 *
	 * @param int $imdbId
	 *
	 * @return array|bool
	 */
	public function getMovieInfo($imdbId)
	{
		return $this->pdo->queryOneRow(sprintf("SELECT * FROM movieinfo WHERE imdbid = %d", $imdbId));
	}

	/**
	 * Get info for multiple IMDB id's.
	 *
	 * @param array $imdbIDs
	 *
	 * @return array
	 */
	public function getMovieInfoMultiImdb($imdbIDs)
	{
		return $this->pdo->query(
			sprintf("
				SELECT DISTINCT movieinfo.*, releases.imdbid AS relimdb
				FROM movieinfo
				LEFT OUTER JOIN releases ON releases.imdbid = movieinfo.imdbid
				WHERE movieinfo.imdbid IN (%s)",
				str_replace(
					',,',
					',',
					str_replace(
						['(,', ' ,', ', )', ',)'],
						'',
						implode(',', $imdbIDs)
					)
				)
			), true, NN_CACHE_EXPIRY_MEDIUM
		);
	}

	/**
	 * Get movies for movie-list admin page.
	 *
	 * @param int $start
	 * @param int $num
	 *
	 * @return array
	 */
	public function getRange($start, $num)
	{
		return $this->pdo->query(
			sprintf('
				SELECT *
				FROM movieinfo
				ORDER BY createddate DESC %s',
				($start === false ? '' : ' LIMIT ' . $num . ' OFFSET ' . $start)
			)
		);
	}

	/**
	 * Get count of movies for movie-list admin page.
	 *
	 * @return int
	 */
	public function getCount()
	{
		$res = $this->pdo->queryOneRow('SELECT COUNT(id) AS num FROM movieinfo');
		return ($res === false ? 0 : $res['num']);
	}

	/**
	 * Get movie releases with covers for movie browse page.
	 *
	 * @param       $cat
	 * @param       $start
	 * @param       $num
	 * @param       $orderBy
	 * @param       $maxAge
	 * @param array $excludedCats
	 *
	 * @return bool|\PDOStatement
	 */
	public function getMovieRange($cat, $start, $num, $orderBy, $maxAge = -1, $excludedCats = [])
	{
		$catsrch = '';
		if (count($cat) > 0 && $cat[0] != -1) {
			$catsrch = (new Category(['Settings' => $this->pdo]))->getCategorySearch($cat);
		}

		$order = $this->getMovieOrder($orderBy);

		$movies = $this->pdo->queryCalc(
				sprintf("
					SELECT SQL_CALC_FOUND_ROWS
						m.imdbid,
						GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id
					FROM movieinfo m
					LEFT JOIN releases r USING (imdbid)
					WHERE r.nzbstatus = 1
					AND m.title != ''
					AND m.imdbid != '0000000'
					AND r.passwordstatus %s
					%s %s %s %s
					GROUP BY m.imdbid
					ORDER BY %s %s %s",
						$this->showPasswords,
						$this->getBrowseBy(),
						(!empty($catsrch) ? $catsrch : ''),
						($maxAge > 0
								? 'AND r.postdate > NOW() - INTERVAL ' . $maxAge . 'DAY '
								: ''
						),
						(count($excludedCats) > 0 ? ' AND r.categories_id NOT IN (' . implode(',', $excludedCats) . ')' : ''),
						$order[0],
						$order[1],
						($start === false ? '' : ' LIMIT ' . $num . ' OFFSET ' . $start)
				), true, NN_CACHE_EXPIRY_MEDIUM
		);

		$movieIDs = $releaseIDs = false;

		if (is_array($movies['result'])) {
			foreach ($movies['result'] AS $movie => $id) {
				$movieIDs[] = $id['imdbid'];
				$releaseIDs[] = $id['grp_release_id'];
			}
		}

		$sql = sprintf("
			SELECT
				GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id,
				GROUP_CONCAT(r.rarinnerfilecount ORDER BY r.postdate DESC SEPARATOR ',') AS grp_rarinnerfilecount,
				GROUP_CONCAT(r.haspreview ORDER BY r.postdate DESC SEPARATOR ',') AS grp_haspreview,
				GROUP_CONCAT(r.passwordstatus ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_password,
				GROUP_CONCAT(r.guid ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_guid,
				GROUP_CONCAT(rn.releases_id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_nfoid,
				GROUP_CONCAT(g.name ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grpname,
				GROUP_CONCAT(r.searchname ORDER BY r.postdate DESC SEPARATOR '#') AS grp_release_name,
				GROUP_CONCAT(r.postdate ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_postdate,
				GROUP_CONCAT(r.size ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_size,
				GROUP_CONCAT(r.totalpart ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_totalparts,
				GROUP_CONCAT(r.comments ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_comments,
				GROUP_CONCAT(r.grabs ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grabs,
				GROUP_CONCAT(df.failed ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_failed,
				GROUP_CONCAT(cp.title, ' > ', c.title ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_catname,
			m.*,
			g.name AS group_name,
			rn.releases_id AS nfoid
			FROM releases r
			LEFT OUTER JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			LEFT OUTER JOIN categories c ON c.id = r.categories_id
			LEFT OUTER JOIN categories cp ON cp.id = c.parentid
			INNER JOIN movieinfo m ON m.imdbid = r.imdbid
			WHERE m.imdbid IN (%s)
			AND r.id IN (%s) %s
			GROUP BY m.imdbid
			ORDER BY %s %s",
				(is_array($movieIDs) ? implode(',', $movieIDs) : -1),
				(is_array($releaseIDs) ? implode(',', $releaseIDs) : -1),
				(!empty($catsrch) ? $catsrch : ''),
				$order[0],
				$order[1]
		);
		$return = $this->pdo->query($sql, true, NN_CACHE_EXPIRY_MEDIUM);
		if (!empty($return)) {
			$return[0]['_totalcount'] = (isset($movies['total']) ? $movies['total'] : 0);
		}
		return $return;
	}

	/**
	 * Get the order type the user requested on the movies page.
	 *
	 * @param $orderBy
	 *
	 * @return array
	 */
	protected function getMovieOrder($orderBy)
	{
		$orderArr = explode('_', (($orderBy == '') ? 'MAX(r.postdate)' : $orderBy));
		switch ($orderArr[0]) {
			case 'title':
				$orderField = 'm.title';
				break;
			case 'year':
				$orderField = 'm.year';
				break;
			case 'rating':
				$orderField = 'm.rating';
				break;
			case 'posted':
			default:
				$orderField = 'MAX(r.postdate)';
				break;
		}

		return [$orderField, ((isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc')];
	}

	/**
	 * Order types for movies page.
	 *
	 * @return array
	 */
	public function getMovieOrdering()
	{
		return ['title_asc', 'title_desc', 'year_asc', 'year_desc', 'rating_asc', 'rating_desc'];
	}

	/**
	 * @return string
	 */
	protected function getBrowseBy()
	{
		$browseBy = ' ';
		$browseByArr = ['title', 'director', 'actors', 'genre', 'rating', 'year', 'imdb'];
		foreach ($browseByArr as $bb) {
			if (isset($_REQUEST[$bb]) && !empty($_REQUEST[$bb])) {
				$bbv = stripslashes($_REQUEST[$bb]);
				if ($bb === 'rating') {
					$bbv .= '.';
				}
				if ($bb === 'imdb') {
					$browseBy .= sprintf('AND m.%sid = %d', $bb, $bbv);
				} else {
					$browseBy .= 'AND m.' . $bb . ' ' . $this->pdo->likeString($bbv, true, true);
				}
			}
		}
		return $browseBy;
	}

	/**
	 * @var null|TraktTv
	 */
	public $traktTv = null;

	/**
	 * Get trailer using IMDB Id.
	 *
	 * @param int $imdbID
	 *
	 * @return bool|string
	 */
	public function getTrailer($imdbID)
	{
		if (!is_numeric($imdbID)) {
			return false;
		}

		$trailer = $this->pdo->queryOneRow("SELECT trailer FROM movieinfo WHERE imdbid = $imdbID and trailer != ''");
		if ($trailer) {
			return $trailer['trailer'];
		}

		if (is_null($this->traktTv)) {
			$this->traktTv = new TraktTv(['Settings' => $this->pdo]);
		}

		$data = $this->traktTv->client->movieSummary('tt' . $imdbID, 'full');
		if ($data) {
			$this->parseTraktTv($data);
			if (isset($data['trailer']) && !empty($data['trailer'])) {
				return $data['trailer'];
			}
		}

		$trailer = Utility::imdb_trailers($imdbID);
		if ($trailer) {
			$this->pdo->queryExec(
				'UPDATE movieinfo SET trailer = ' . $this->pdo->escapeString($trailer) . ' WHERE imdbid = ' . $imdbID
			);
			return $trailer;
		}
		return false;
	}

	/**
	 * Parse trakt info, insert into DB.
	 *
	 * @param array $data
	 *
	 * @return mixed|void
	 */
		public function parseTraktTv(&$data)
	{
		if (!isset($data['ids']['imdb']) || empty($data['ids']['imdb'])) {
			return false;
		}

		if (isset($data['trailer']) && !empty($data['trailer'])) {
			$data['trailer'] = str_ireplace(
				'http://', 'https://', str_ireplace('watch?v=', 'embed/', $data['trailer'])
			);
			return $data['trailer'];
		}
		$imdbid = (strpos($data['ids']['imdb'], 'tt') === 0) ? substr($data['ids']['imdb'], 2) : $data['ids']['imdb'];
		$cover = 0;
		if (is_file($this->imgSavePath . $imdbid) . '-cover.jpg') {
			$cover = 1;
		} else {
			$link = $this->checkTraktValue($data['images']['poster']['thumb']);
			if ($link) {
				$cover = $this->releaseImage->saveImage($imdbid . '-cover', $link, $this->imgSavePath);
			}
		}
		$this->update([
			'genres'   => $this->checkTraktValue($data['genres']),
			'imdbid'   => $this->checkTraktValue($imdbid),
			'language' => $this->checkTraktValue($data['language']),
			'plot'     => $this->checkTraktValue($data['overview']),
			'rating'   => round($this->checkTraktValue($data['rating']), 1),
			'tagline'  => $this->checkTraktValue($data['tagline']),
			'title'    => $this->checkTraktValue($data['title']),
			'tmdbid'   => $this->checkTraktValue($data['ids']['tmdb']),
			'trailer'  => $this->checkTraktValue($data['trailer']),
			'cover'    => $cover,
			'year'     => $this->checkTraktValue($data['year'])
		]);
	}

	/**
	 * Checks if the value is set and not empty, returns it, else empty string.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	private function checkTraktValue($value)
	{
		if (is_array($value) && !empty($value)) {
			$temp = '';
			foreach($value as $val) {
				if (!is_array($val) && !is_object($val)) {
					$temp .= (string)$val;
				}
			}
			$value = $temp;
		}
		return (isset($value) && !empty($value) ? $value : '');
	}

	/**
	 * Create click-able links to IMDB actors/genres/directors/etc..
	 *
	 * @param $data
	 * @param $field
	 *
	 * @return string
	 */
	public function makeFieldLinks($data, $field)
	{
		if (!isset($data[$field]) || $data[$field] == '') {
			return '';
		}

		$tmpArr = explode(', ', $data[$field]);
		$newArr = [];
		$i = 0;
		foreach ($tmpArr as $ta) {
			if (trim($ta) == '') {
				continue;
			}
			if ($i > 5) {
				break;
			} //only use first 6
			$newArr[] = '<a href="' . WWW_TOP . '/movies?' . $field . '=' . urlencode($ta) . '" title="' . $ta . '">' . $ta . '</a>';
			$i++;
		}
		return implode(', ', $newArr);
	}

	/**
	 * Get array of column keys, for inserting / updating.
	 * @return array
	 */
	public function getColumnKeys()
	{
		return [
			'actors','backdrop','cover','director','genre','imdbid','language',
			'plot','rating','tagline','title','tmdbid', 'trailer','type','year'
		];
	}

	/**
	 * Update movie on movie-edit page.
	 *
	 * @param array $values Array of keys/values to update. See $validKeys
	 * @return int|bool
	 */
	public function update(array $values) {
		if (!count($values)) {
			return false;
		}

		$validKeys = $this->getColumnKeys();

		$query = [
			'0' => 'INSERT INTO movieinfo (updateddate, createddate, ',
			'1' => ' VALUES (NOW(), NOW(), ',
			'2' => 'ON DUPLICATE KEY UPDATE updateddate = NOW(), '
		];
		$found = 0;
		foreach ($values as $key => $value) {
			if (in_array($key, $validKeys) && !empty($value)) {
				$found++;
				$query[0] .= "$key, ";
				if (in_array($key, ['genre', 'language'])) {
					$value = substr($value, 0, 64);
				}
				$value = $this->pdo->escapeString($value);
				$query[1] .= "$value, ";
				$query[2] .= "$key = $value, ";
			}
		}
		if (!$found) {
			return false;
		}
		foreach ($query as $key => $value) {
			$query[$key] = rtrim($value, ', ');
		}

		return $this->pdo->queryInsert($query[0] . ') ' . $query[1] . ') ' . $query[2]);
	}

	/**
	 * Check if a variable is set and not a empty string.
	 *
	 * @param $variable
	 *
	 * @return string
	 */
	protected function checkVariable(&$variable)
	{
		if (isset($variable) && $variable != '') {
			return true;
		}
		return false;
	}

	/**
	 * Returns a tmdb, imdb or trakt variable, the one that is set. Empty string if both not set.
	 *
	 * @param string $variable1
	 * @param string $variable2
	 * @param string $variable3
	 *
	 * @return string
	 */
	protected function setTmdbImdbTraktVar(&$variable1, &$variable2, &$variable3)
	{
		if ($this->checkVariable($variable1)) {
			return $variable1;
		} elseif ($this->checkVariable($variable2)) {
			return $variable2;
		} elseif ($this->checkVariable($variable3)) {
			return $variable3;
		}
		return '';
	}

	/**
	 * Fetch IMDB/TMDB/TRAKT info for the movie.
	 *
	 * @param $imdbId
	 *
	 * @return bool
	 */
	public function updateMovieInfo($imdbId)
	{
		if ($this->echooutput && $this->service !== '') {
			$this->pdo->log->doEcho($this->pdo->log->primary('Fetching IMDB info from TMDB and/or Trakt using IMDB id: ' . $imdbId));
		}

		// Check TMDB for IMDB info.
		$tmdb = $this->fetchTMDBProperties($imdbId);

		// Check IMDB for movie info.
		$imdb = $this->fetchIMDBProperties($imdbId);

		// Check TRAKT for movie info
		$trakt = $this->fetchTraktTVProperties($imdbId);
		if (!$imdb && !$tmdb && !$trakt) {
			return false;
		}

		// Check FanArt.tv for cover and background images.
		$fanart = $this->fetchFanartTVProperties($imdbId);

		$mov = [];

		$mov['cover'] = $mov['backdrop'] = $mov['banner'] = $movieID = 0;
		$mov['type'] = $mov['director'] = $mov['actors'] = $mov['language'] = '';

		$mov['imdbid'] = $imdbId;
		$mov['tmdbid'] = (!isset($tmdb['tmdbid']) || $tmdb['tmdbid'] == '') ? 0 : $tmdb['tmdbid'];

		// Prefer Fanart.tv cover over TMDB and TMDB over IMDB.
		if ($this->checkVariable($fanart['cover'])) {
			$mov['cover'] = $this->releaseImage->saveImage($imdbId . '-cover', $fanart['cover'], $this->imgSavePath);
		} else if ($this->checkVariable($tmdb['cover'])) {
			$mov['cover'] = $this->releaseImage->saveImage($imdbId . '-cover', $tmdb['cover'], $this->imgSavePath);
		} else if ($this->checkVariable($imdb['cover'])) {
			$mov['cover'] = $this->releaseImage->saveImage($imdbId . '-cover', $imdb['cover'], $this->imgSavePath);
		}

		// Backdrops.
		if ($this->checkVariable($fanart['backdrop'])) {
			$mov['backdrop'] = $this->releaseImage->saveImage($imdbId . '-backdrop', $fanart['backdrop'], $this->imgSavePath, 1920, 1024);
		} else if ($this->checkVariable($tmdb['backdrop'])) {
			$mov['backdrop'] = $this->releaseImage->saveImage($imdbId . '-backdrop', $tmdb['backdrop'], $this->imgSavePath, 1920, 1024);
		}

		// Banner
		if ($this->checkVariable($fanart['banner'])) {
			$mov['banner'] = $this->releaseImage->saveImage($imdbId . '-banner', $fanart['banner'], $this->imgSavePath);
		}
		$mov['title']   = $this->setTmdbImdbTraktVar($imdb['title']  , $tmdb['title'], $trakt['title']);
		$mov['rating']  = $this->setTmdbImdbTraktVar($imdb['rating'] , $tmdb['rating'], $trakt['rating']);
		$mov['plot']    = $this->setTmdbImdbTraktVar($imdb['plot']   , $tmdb['plot'], $trakt['overview']);
		$mov['tagline'] = $this->setTmdbImdbTraktVar($imdb['tagline'], $tmdb['tagline'], $trakt['tagline']);
		$mov['year']    = $this->setTmdbImdbTraktVar($imdb['year']   , $tmdb['year'], $trakt['year']);
		$mov['genre']   = $this->setTmdbImdbTraktVar($imdb['genre']  , $tmdb['genre'], $trakt['genres']);

		if ($this->checkVariable($imdb['type'])) {
			$mov['type'] = $imdb['type'];
		}

		if ($this->checkVariable($imdb['director'])) {
			$mov['director'] = (is_array($imdb['director'])) ? implode(', ', array_unique($imdb['director'])) : $imdb['director'];
		}

		if ($this->checkVariable($imdb['actors'])) {
			$mov['actors'] = (is_array($imdb['actors'])) ? implode(', ', array_unique($imdb['actors'])) : $imdb['actors'];
		}

		if ($this->checkVariable($imdb['language'])) {
			$mov['language'] = (is_array($imdb['language'])) ? implode(', ', array_unique($imdb['language'])) : $imdb['language'];
		}

		if (is_array($mov['genre'])) {
			$mov['genre'] = implode(', ', array_unique($mov['genre']));
		}

		if (is_array($mov['type'])) {
			$mov['type'] = implode(', ', array_unique($mov['type']));
		}

		$mov['title']    = html_entity_decode($mov['title']   , ENT_QUOTES, 'UTF-8');

		$mov['title'] = str_replace(['/', '\\'], '', $mov['title']);
		$movieID = $this->update([
			'actors'    => html_entity_decode($mov['actors']  , ENT_QUOTES, 'UTF-8'),
			'backdrop'  => $mov['backdrop'],
			'cover'     => $mov['cover'],
			'director'  => html_entity_decode($mov['director'], ENT_QUOTES, 'UTF-8'),
			'genre'     => html_entity_decode($mov['genre']   , ENT_QUOTES, 'UTF-8'),
			'imdbid'    => $mov['imdbid'],
			'language'  => html_entity_decode($mov['language'], ENT_QUOTES, 'UTF-8'),
			'plot'      => html_entity_decode(preg_replace('/\s+See full summary »/', ' ', $mov['plot']), ENT_QUOTES, 'UTF-8'),
			'rating'    => round($mov['rating'], 1),
			'tagline'   => html_entity_decode($mov['tagline'] , ENT_QUOTES, 'UTF-8'),
			'title'     => $mov['title'],
			'tmdbid'    => $mov['tmdbid'],
			'type'      => html_entity_decode(ucwords(preg_replace('/[\.\_]/', ' ', $mov['type'])), ENT_QUOTES, 'UTF-8'),
			'year'      => $mov['year']
		]);

		if ($this->echooutput && $this->service !== '') {
			$this->pdo->log->doEcho(
				$this->pdo->log->headerOver(($movieID !== 0 ? 'Added/updated movie: ' : 'Nothing to update for movie: ')) .
				$this->pdo->log->primary($mov['title'] .
					' (' .
					$mov['year'] .
					') - ' .
					$mov['imdbid']
				)
			);
		}

		return ($movieID === 0 ? false : true);
	}

	/**
	 * Fetch FanArt.tv backdrop / cover / title.
	 *
	 * @param $imdbId
	 *
	 * @return bool|array
	 */
	protected function fetchFanartTVProperties($imdbId)
	{
		if ($this->fanartapikey != '')
		{
			try {
				$buffer = $this->client->get('https://webservice.fanart.tv/v3/movies/' . 'tt' . $imdbId . '?api_key=' . $this->fanartapikey)->getBody()->getContents();
			} catch (RequestException $e) {
				if ($e->hasResponse()) {
					if($e->getCode() === 404) {
						$this->pdo->log->doEcho($this->pdo->log->notice('Data not available on server'));
					} else if ($e->getCode() === 503) {
						$this->pdo->log->doEcho($this->pdo->log->notice('Service unavailable'));
					} else {
						$this->pdo->log->doEcho($this->pdo->log->notice('Unable to fetch data, http error reported: ' . $e->getCode()));
					}
				}
			}

			if (isset($buffer) && $buffer !== false) {
				$art = json_decode($buffer, true);
				if (isset($art['status']) && $art['status'] === 'error') {
					return false;
				}
				$ret = [];
				if ($this->checkVariable($art['moviebackground'][0]['url'])) {
					$ret['backdrop'] = $art['moviebackground'][0]['url'];
				} else if ($this->checkVariable($art['moviethumb'][0]['url'])) {
					$ret['backdrop'] = $art['moviethumb'][0]['url'];
				}
				if ($this->checkVariable($art['movieposter'][0]['url'])) {
					$ret['cover'] = $art['movieposter'][0]['url'];
				}
				if ($this->checkVariable($art['moviebanner'][0]['url'])) {
					$ret['banner'] = $art['moviebanner'][0]['url'];
				}

				if (isset($ret['backdrop']) && isset($ret['cover'])) {
					$ret['title'] = $imdbId;
					if (isset($art['name'])) {
						$ret['title'] = $art['name'];
					}
					if ($this->echooutput) {
						$this->pdo->log->doEcho($this->pdo->log->alternateOver("Fanart Found ") . $this->pdo->log->headerOver($ret['title']), true);
					}
					return $ret;
				}
			}
		}
		return false;
	}

	/**
	 * Fetch info for IMDB id from TMDB.
	 *
	 * @param      $imdbId
	 * @param bool $text
	 *
	 * @return array|bool
	 */
	public function fetchTMDBProperties($imdbId, $text = false)
	{
		$lookupId = ($text === false ? 'tt' . $imdbId : $imdbId);

		try {
			$tmdbLookup = $this->tmdbclient->getMoviesApi()->getMovie($lookupId);
		} catch (TmdbApiException $e) {
			return false;
		}
		/*$status = $tmdbLookup['status_code'];
		if (!$status || (isset($status) && $status !== 1)) {
			return false;
		}*/

		$ret = [];
		$ret['title'] = $tmdbLookup['original_title'];

		if ($this->currentTitle !== '') {
			// Check the similarity.
			similar_text($this->currentTitle, $ret['title'], $percent);
			if ($percent < 40) {
				if ($this->debug) {
					$this->debugging->log(
						get_class(),
						__FUNCTION__,
						'Found (' .
						$ret['title'] .
						') from TMDB, but it\'s only ' .
						$percent .
						'% similar to (' .
						$this->currentTitle . ')',
						Logger::LOG_INFO
					);
				}
				return false;
			}
		}

		$ret['tmdbid'] = $tmdbLookup['id'];
		$ImdbID = str_replace('tt', '', $tmdbLookup['imdb_id']);
		$ret['imdb_id'] = $ImdbID;
		$vote = $tmdbLookup['vote_average'];
		if (isset($vote)) {
			$ret['rating'] = ($vote === 0) ? '' : $vote;
		}
		$overview = $tmdbLookup['overview'];
		if (!empty($overview)) {
			$ret['plot'] = $overview;
		}
		$tagline = $tmdbLookup['tagline'];
		if (!empty($tagline)) {
			$ret['tagline'] = $tagline;
		}
		$released = $tmdbLookup['release_date'];
		if (!empty($released)) {
			$ret['year'] = date('Y', strtotime($released));
		}
		$genresa = $tmdbLookup['genres'];
		if (!empty($genresa) && count($genresa) > 0) {
			$genres = [];
			foreach ($genresa as $genre) {
				$genres[] = $genre['name'];
			}
			$ret['genre'] = $genres;
		}
		$posterp = $tmdbLookup['poster_path'];
		if (!empty($posterp)) {
			$ret['cover'] = 'http://image.tmdb.org/t/p/w185' . $posterp;
		}
		$backdrop = $tmdbLookup['backdrop_path'];
		if (!empty($backdrop)) {
			$ret['backdrop'] = 'http://image.tmdb.org/t/p/original' . $backdrop;
		}
		if ($this->echooutput) {
			$this->pdo->log->doEcho($this->pdo->log->primaryOver('TMDb Found ') . $this->pdo->log->headerOver($ret['title']), true);
		}
		return $ret;
	}

	/**
	 * @param $imdbId
	 *
	 * @return array|bool
	 */
	protected function fetchIMDBProperties($imdbId)
	{
		$imdb_regex = [
			'title' => '/<title>(.*?)\s?\(.*?<\/title>/i',
			'tagline' => '/taglines:<\/h4>\s([^<]+)/i',
			'plot' => '/<p itemprop="description">\s*?(.*?)\s*?<\/p>/i',
			'rating' => '/"ratingValue">([\d.]+)<\/span>/i',
			'year' => '/<title>.*?\(.*?(\d{4}).*?<\/title>/i',
			'cover' => '/<link rel=\'image_src\' href="(http:\/\/ia\.media-imdb\.com.+\.jpg)">/'
		];

		$imdb_regex_multi = [
			'genre' => '/href="\/genre\/(.*?)\?/i',
			'language' => '/<a href="\/language\/.+?\'url\'>(.+?)<\/a>/s',
			'type' => '/<meta property=\'og\:type\' content=\"(.+)\" \/>/i'
		];

		try {
			$buffer =
				$this->client->get(
					'http://' . ($this->imdburl === false ? 'www' : 'akas') . '.imdb.com/title/tt' . $imdbId . '/',
					['headers' => [
						'Accept-Language' => ((Settings::value('indexer.categorise.imdblanguage') != '') ? Settings::value('indexer.categorise.imdblanguage') : 'en'),
						'useragent'       => 'Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) ' .
							'Version/4.0.4 Mobile/7B334b Safari/531.21.102011-10-16 20:23:10', 'foo=bar'
					]
					]
				)->getBody()->getContents();
		} catch (RequestException $e) {
			if ($e->hasResponse()) {
				if($e->getCode() === 404) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Data not available on server'));
				} else if ($e->getCode() === 503) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Service unavailable'));
				} else {
					$this->pdo->log->doEcho($this->pdo->log->notice('Unable to fetch data, http error reported: ' . $e->getCode()));
				}
			}
		}

		if (isset($buffer) && $buffer !== false) {
			$ret = [];
			foreach ($imdb_regex as $field => $regex) {
				if (preg_match($regex, $buffer, $matches)) {
					$match = $matches[1];
					$match1 = strip_tags(trim(rtrim($match)));
					$ret[$field] = $match1;
				}
			}

			$matches = [];
			foreach ($imdb_regex_multi as $field => $regex) {
				if (preg_match_all($regex, $buffer, $matches)) {
					$match2 = $matches[1];
					$match3 = array_map("trim", $match2);
					$ret[$field] = $match3;
				}
			}

			if ($this->currentTitle !== '' && isset($ret['title'])) {
				// Check the similarity.
				similar_text($this->currentTitle, $ret['title'], $percent);
				if ($percent < 40) {
					if ($this->debug) {
						$this->debugging->log(
							get_class(),
							__FUNCTION__,
							'Found (' .
							$ret['title'] .
							') from IMDB, but it\'s only ' .
							$percent .
							'% similar to (' .
							$this->currentTitle . ')',
							Logger::LOG_INFO
						);
					}
					return false;
				}
			}

			// Actors.
			if (preg_match('/<table class="cast_list">(.+?)<\/table>/s', $buffer, $hit)) {
				if (preg_match_all('/<span class="itemprop" itemprop="name">\s*(.+?)\s*<\/span>/i', $hit[0], $results, PREG_PATTERN_ORDER)) {
					$ret['actors'] = $results[1];
				}
			}

			// Directors.
			if (preg_match('/itemprop="directors?".+?<\/div>/s', $buffer, $hit)) {
				if (preg_match_all('/"name">(.*?)<\/span>/is', $hit[0], $results, PREG_PATTERN_ORDER)) {
					$ret['director'] = $results[1];
				}
			}
			if ($this->echooutput && isset($ret['title'])) {
				$this->pdo->log->doEcho($this->pdo->log->headerOver("IMDb Found ") . $this->pdo->log->primaryOver($ret['title']), true);
			}
			return $ret;
		}
		return false;
	}

	/**
	 * Fetch TraktTV backdrop / cover / title.
	 *
	 * @param $imdbId
	 *
	 * @return bool|array
	 */
	protected function fetchTraktTVProperties($imdbId)
	{
		if (is_null($this->traktTv)) {
			$this->traktTv = new TraktTv(['Settings' => $this->pdo]);
		}
		$resp = $this->traktTv->client->movieSummary('tt' . $imdbId, 'full');
		if ($resp !== false) {
			$ret = [];
			if (isset($resp['ids']['trakt'])) {
				$ret['id'] = $resp['ids']['trakt'];
			}

			if (isset($resp['title'])) {
				$ret['title'] = $resp['title'];
			} else {
				return false;
			}
			if ($this->echooutput) {
				$this->pdo->log->doEcho($this->pdo->log->alternateOver("Trakt Found ") . $this->pdo->log->headerOver($ret['title']), true);
			}
			return $ret;
		}
		return false;
	}

	/**
	 * Update a release with a IMDB id.
	 *
	 * @param string $buffer       Data to parse a IMDB id/Trakt Id from.
	 * @param string $service      Method that called this method.
	 * @param int    $id           id of the release.
	 * @param int    $processImdb  To get IMDB info on this IMDB id or not.
	 *
	 * @return string
	 */
	public function doMovieUpdate($buffer, $service, $id, $processImdb = 1)
	{
		$imdbID = false;
		if (is_string($buffer) && preg_match('/(?:imdb.*?)?(?:tt|Title\?)(?P<imdbid>\d{5,7})/i', $buffer, $matches)) {
			$imdbID = $matches['imdbid'];
		}

		if ($imdbID !== false) {
			$this->service = $service;
			if ($this->echooutput && $this->service !== '') {
				$this->pdo->log->doEcho($this->pdo->log->headerOver($service . ' found IMDBid: ') . $this->pdo->log->primary('tt' . $imdbID));
			}

			$this->pdo->queryExec(sprintf('UPDATE releases SET imdbid = %s WHERE id = %d %s', $this->pdo->escapeString($imdbID), $id, $this->catWhere));

			// If set, scan for imdb info.
			if ($processImdb == 1) {
				$movCheck = $this->getMovieInfo($imdbID);
				if ($movCheck === false || (isset($movCheck['updateddate']) && (time() - strtotime($movCheck['updateddate'])) > 2592000)) {
					if ($this->updateMovieInfo($imdbID) === false) {
						$this->pdo->queryExec(sprintf('UPDATE releases SET imdbid = %s WHERE id = %d %s', 0000000, $id, $this->catWhere));
					}
				}
			}
		}
		return $imdbID;
	}

	/**
	 * Process releases with no IMDB id's.
	 *
	 * @param string $groupID    (Optional) id of a group to work on.
	 * @param string $guidChar   (Optional) First letter of a release GUID to use to get work.
	 * @param int    $lookupIMDB (Optional) 0 Don't lookup IMDB, 1 lookup IMDB, 2 lookup IMDB on releases that were renamed.
	 */
	public function processMovieReleases($groupID = '', $guidChar = '', $lookupIMDB = 1)
	{
		if ($lookupIMDB == 0) {
			return;
		}

		// Get all releases without an IMDB id.
		$res = $this->pdo->query(
			sprintf("
				SELECT r.searchname, r.id
				FROM releases r
				WHERE r.imdbid IS NULL
				AND r.nzbstatus = 1
				%s %s %s %s
				LIMIT %d",
				$this->catWhere,
				($groupID === '' ? '' : ('AND r.groups_id = ' . $groupID)),
				($guidChar === '' ? '' : 'AND r.leftguid = ' . $this->pdo->escapeString($guidChar)),
				($lookupIMDB == 2 ? 'AND r.isrenamed = 1' : ''),
				$this->movieqty
			)
		);
		$movieCount = count($res);

		if ($movieCount > 0) {
			if (is_null($this->traktTv)) {
				$this->traktTv = new TraktTv(['Settings' => $this->pdo]);
			}
			if ($this->echooutput && $movieCount > 1) {
				$this->pdo->log->doEcho($this->pdo->log->header("Processing " . $movieCount . " movie releases."));
			}

			// Loop over releases.
			foreach ($res as $arr) {
				// Try to get a name/year.
				if ($this->parseMovieSearchName($arr['searchname']) === false) {
					//We didn't find a name, so set to all 0's so we don't parse again.
					$this->pdo->queryExec(sprintf("UPDATE releases SET imdbid = 0000000 WHERE id = %d %s", $arr["id"], $this->catWhere));
					continue;

				} else {
					$this->currentRelID = $arr['id'];

					$movieName = $this->currentTitle;
					if ($this->currentYear !== false) {
						$movieName .= ' (' . $this->currentYear . ')';
					}

					if ($this->echooutput) {
						$this->pdo->log->doEcho($this->pdo->log->primaryOver("Looking up: ") . $this->pdo->log->headerOver($movieName), true);
					}

					// Check local DB.
					$getIMDBid = $this->localIMDBsearch();

					if ($getIMDBid !== false) {
						$imdbID = $this->doMovieUpdate('tt' . $getIMDBid, 'Local DB', $arr['id']);
						if ($imdbID !== false) {
							continue;
						}
					}

					// Check OMDB api.
					try {
						$buffer =
							$this->client->get(
								'http://www.omdbapi.com/?t=' .
								urlencode($this->currentTitle) .
								($this->currentYear !== false ? ('&y=' . $this->currentYear) : '') .
								'&r=json'
							)->getBody()->getContents();
					} catch (RequestException $e) {
						if ($e->hasResponse()) {
							if($e->getCode() === 404) {
								$this->pdo->log->doEcho($this->pdo->log->notice('Data not available on server'));
							} else if ($e->getCode() === 503) {
								$this->pdo->log->doEcho($this->pdo->log->notice('Service unavailable'));
							} else {
								$this->pdo->log->doEcho($this->pdo->log->notice('Unable to fetch data, http error reported: ' . $e->getCode()));
							}
						}
					}

					if (isset($buffer) && $buffer !== false) {
						$getIMDBid = json_decode($buffer);

						if (isset($getIMDBid->imdbid)) {
							$imdbID = $this->doMovieUpdate($getIMDBid->imdbid, 'OMDbAPI', $arr['id']);
							if ($imdbID !== false) {
								continue;
							}
						}
					}

					// Check on trakt.
					$data = $this->traktTv->client->movieSummary($movieName, 'full');
					if ($data !== false) {
						$this->parseTraktTv($data);
						if (isset($data['ids']['imdb'])) {
							$imdbID = $this->doMovieUpdate($data['ids']['imdb'], 'Trakt', $arr['id']);
							if ($imdbID !== false) {
								continue;
							}
						}
					}

					// Try on search engines.
					if ($this->searchEngines && $this->currentYear !== false) {
						if ($this->imdbIDFromEngines() === true) {
							continue;
						}
					}

					// We failed to get an IMDB id from all sources.
					$this->pdo->queryExec(sprintf("UPDATE releases SET imdbid = 0000000 WHERE id = %d %s", $arr["id"], $this->catWhere));
				}
			}
		}
	}

	/**
	 * Try to fetch an IMDB id locally.
	 *
	 * @return int|bool   Int, the imdbid when true, Bool when false.
	 */
	protected function localIMDBsearch()
	{
		$query = 'SELECT imdbid FROM movieinfo';
		$andYearIn = '';

		//If we found a year, try looking in a 4 year range.
		if ($this->currentYear !== false) {
			$start = (int) $this->currentYear - 2;
			$end   = (int) $this->currentYear + 2;
			$andYearIn = 'AND year IN (';
			while ($start < $end) {
				$andYearIn .= $start . ',';
				$start++;
			}
			$andYearIn .= $end . ')';
		}
		$IMDBCheck = $this->pdo->queryOneRow(
			sprintf('%s WHERE title %s %s', $query, $this->pdo->likeString($this->currentTitle), $andYearIn));

		// Look by %word%word%word% etc..
		if ($IMDBCheck === false) {
			$pieces = explode(' ', $this->currentTitle);
			$tempTitle = '%';
			foreach ($pieces as $piece) {
				$tempTitle .= str_replace(["'", "!", '"'], '', $piece) . '%';
			}
			$IMDBCheck = $this->pdo->queryOneRow(
				sprintf("%s WHERE replace(replace(title, \"'\", ''), '!', '') %s %s",
					$query, $this->pdo->likeString($tempTitle), $andYearIn
				)
			);
		}

		// Try replacing er with re ?
		if ($IMDBCheck === false) {
			$tempTitle = str_replace('er', 're', $this->currentTitle);
			if ($tempTitle !== $this->currentTitle) {
				$IMDBCheck = $this->pdo->queryOneRow(
					sprintf('%s WHERE title %s %s',
						$query, $this->pdo->likeString($tempTitle), $andYearIn
					)
				);

				// Final check if everything else failed.
				if ($IMDBCheck === false) {
					$pieces = explode(' ', $tempTitle);
					$tempTitle = '%';
					foreach ($pieces as $piece) {
						$tempTitle .= str_replace(["'", "!", '"'], "", $piece) . '%';
					}
					$IMDBCheck = $this->pdo->queryOneRow(
						sprintf("%s WHERE replace(replace(replace(title, \"'\", ''), '!', ''), '\"', '') %s %s",
							$query, $this->pdo->likeString($tempTitle), $andYearIn
						)
					);
				}
			}
		}

		return (
		($IMDBCheck === false
			? false
			: (is_numeric($IMDBCheck['imdbid'])
				? (int)$IMDBCheck['imdbid']
				: false
			)
		)
		);
	}

	/**
	 * Try to get an IMDB id from search engines.
	 *
	 * @return bool
	 */
	protected function imdbIDFromEngines()
	{
		if ($this->googleLimit < 41 && (time() - $this->googleBan) > 600) {
			if ($this->googleSearch() === true) {
				return true;
			}
		}

		if ($this->yahooLimit < 41) {
			if ($this->yahooSearch() === true) {
				return true;
			}
		}

		// Not using this right now because bing's advanced search is not good enough.
		/*if ($this->bingLimit < 41) {
			if ($this->bingSearch() === true) {
				return true;
			}
		}*/

		return false;
	}

	/**
	 * Try to find a IMDB id on google.com
	 *
	 * @return bool
	 */
	protected function googleSearch()
	{
		try {
			$buffer = $this->client->get(
				'https://www.google.com/search?hl=en&as_q=&as_epq=' .
				urlencode(
					$this->currentTitle .
					' ' .
					$this->currentYear
				) .
				'&as_oq=&as_eq=&as_nlo=&as_nhi=&lr=&cr=&as_qdr=all&as_sitesearch=' .
				urlencode('www.imdb.com/title/') .
				'&as_occt=title&safe=images&tbs=&as_filetype=&as_rights='
			)->getBody()->getContents();
		} catch (RequestException $e) {
			if ($e->hasResponse()) {
				if($e->getCode() === 404) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Data not available on server'));
				} else if ($e->getCode() === 503) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Service unavailable'));
				} else {
					$this->pdo->log->doEcho($this->pdo->log->notice('Unable to fetch data, http error reported: ' . $e->getCode()));
				}
			}
		}

		// Make sure we got some data.
		if (isset($buffer) && $buffer !== false) {
			$this->googleLimit++;

			if (preg_match('/(To continue, please type the characters below)|(- did not match any documents\.)/i', $buffer, $matches)) {
				if (!empty($matches[1])) {
					$this->googleBan = time();
				}
			} else if ($this->doMovieUpdate($buffer, 'Google.com', $this->currentRelID) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Try to find a IMDB id on bing.com
	 *
	 * @return bool
	 */
	protected function bingSearch()
	{
		try {
			$buffer = $this->client->get(
				"http://www.bing.com/search?q=" .
				urlencode(
					'("' .
					$this->currentTitle .
					'" and "' .
					$this->currentYear .
					'") site:www.imdb.com/title/'
				) .
				'&qs=n&form=QBLH&filt=all'
			)->getBody()->getContents();
		} catch (RequestException $e) {
			if ($e->hasResponse()) {
				if($e->getCode() === 404) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Data not available on server'));
				} else if ($e->getCode() === 503) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Service unavailable'));
				} else {
					$this->pdo->log->doEcho($this->pdo->log->notice('Unable to fetch data, http error reported: ' . $e->getCode()));
				}
			}
		}

		if (isset($buffer) && $buffer !== false) {
			$this->bingLimit++;

			if ($this->doMovieUpdate($buffer, 'Bing.com', $this->currentRelID) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Try to find a IMDB id on yahoo.com
	 *
	 * @return bool
	 */
	protected function yahooSearch()
	{
		try {
			$buffer = $this->client->get(
				"http://search.yahoo.com/search?n=10&ei=UTF-8&va_vt=title&vo_vt=any&ve_vt=any&vp_vt=any&vf=all&vm=p&fl=0&fr=fp-top&p=" .
				urlencode(
					'' .
					implode('+',
						explode(
							' ',
							preg_replace(
								'/\s+/',
								' ',
								preg_replace(
									'/\W/',
									' ',
									$this->currentTitle
								)
							)
						)
					) .
					'+' .
					$this->currentYear
				) .
				'&vs=' .
				urlencode('www.imdb.com/title/')
			)->getBody()->getContents();
		} catch (RequestException $e) {
			if ($e->hasResponse()) {
				if($e->getCode() === 404) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Data not available on server'));
				} else if ($e->getCode() === 503) {
					$this->pdo->log->doEcho($this->pdo->log->notice('Service unavailable'));
				} else {
					$this->pdo->log->doEcho($this->pdo->log->notice('Unable to fetch data, http error reported: ' . $e->getCode()));
				}
			}
		}

		if (isset($buffer) && $buffer !== false) {
			$this->yahooLimit++;

			if ($this->doMovieUpdate($buffer, 'Yahoo.com', $this->currentRelID) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a movie name from a release search name.
	 *
	 * @param string $releaseName
	 *
	 * @return bool
	 */
	protected function parseMovieSearchName($releaseName)
	{
		$name = $year = '';
		$followingList = '[^\w]((1080|480|720)p|AC3D|Directors([^\w]CUT)?|DD5\.1|(DVD|BD|BR)(Rip)?|BluRay|divx|HDTV|iNTERNAL|LiMiTED|(Real\.)?Proper|RE(pack|Rip)|Sub\.?(fix|pack)|Unrated|WEB-DL|(x|H)[-._ ]?264|xvid)[^\w]';

		/* Initial scan of getting a year/name.
		 * [\w. -]+ Gets 0-9a-z. - characters, most scene movie titles contain these chars.
		 * ie: [61420]-[FULL]-[a.b.foreignEFNet]-[ Coraline.2009.DUTCH.INTERNAL.1080p.BluRay.x264-VeDeTT ]-[21/85] - "vedett-coralien-1080p.r04" yEnc
		 * Then we look up the year, (19|20)\d\d, so $matches[1] would be Coraline $matches[2] 2009
		 */
		if (preg_match('/(?P<name>[\w. -]+)[^\w](?P<year>(19|20)\d\d)/i', $releaseName, $matches)) {
			$name = $matches['name'];
			$year = $matches['year'];

			/* If we didn't find a year, try to get a name anyways.
			 * Try to look for a title before the $followingList and after anything but a-z0-9 two times or more (-[ for example)
			 */
		} else if (preg_match('/([^\w]{2,})?(?P<name>[\w .-]+?)' . $followingList . '/i', $releaseName, $matches)) {
			$name = $matches['name'];
		}

		// Check if we got something.
		if ($name !== '') {

			// If we still have any of the words in $followingList, remove them.
			$name = preg_replace('/' . $followingList . '/i', ' ', $name);
			// Remove periods, underscored, anything between parenthesis.
			$name = preg_replace('/\(.*?\)|[._]/i', ' ', $name);
			// Finally remove multiple spaces and trim leading spaces.
			$name = trim(preg_replace('/\s{2,}/', ' ', $name));
			// Check if the name is long enough and not just numbers.
			if (strlen($name) > 4 && !preg_match('/^\d+$/', $name)) {
				if ($this->debug && $this->echooutput) {
					$this->pdo->log->doEcho("DB name: {$releaseName}", true);
				}
				$this->currentTitle = $name;
				$this->currentYear  = ($year === '' ? false : $year);
				return true;
			}
		}
		return false;
	}

	/**
	 * Get IMDB genres.
	 *
	 * @return array
	 */
	public function getGenres()
	{
		return [
			'Action',
			'Adventure',
			'Animation',
			'Biography',
			'Comedy',
			'Crime',
			'Documentary',
			'Drama',
			'Family',
			'Fantasy',
			'Film-Noir',
			'Game-Show',
			'History',
			'Horror',
			'Music',
			'Musical',
			'Mystery',
			'News',
			'Reality-TV',
			'Romance',
			'Sci-Fi',
			'Sport',
			'Talk-Show',
			'Thriller',
			'War',
			'Western'
		];
	}

}