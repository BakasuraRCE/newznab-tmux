<?php

namespace Blacklight;

use App\Models\Release;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use sspat\ESQuerySanitizer\Sanitizer;

class ElasticSearchSiteSearch
{
    /**
     * @param  array|string  $phrases
     * @param  int  $limit
     * @return mixed
     */
    public function indexSearch(array|string $phrases, int $limit): mixed
    {
        $keywords = $this->sanitize($phrases);

        try {
            $search = [
                'scroll' => '30s',
                'index' => 'releases',
                'body' => [
                    'query' => [
                        'query_string' => [
                            'query' => $keywords,
                            'fields' => ['searchname', 'plainsearchname', 'fromname', 'filename', 'name', 'categories_id'],
                            'analyze_wildcard' => true,
                            'default_operator' => 'and',
                        ],
                    ],
                    'size' => $limit,
                    'sort' => [
                        'add_date' => [
                            'order' => 'desc',
                        ],
                        'post_date' => [
                            'order' => 'desc',
                        ],
                    ],
                ],
            ];

            return $this->search($search);
        } catch (BadRequest400Exception $request400Exception) {
            return [];
        }
    }

    /**
     * @param  array|string  $searchName
     * @param  int  $limit
     * @return array
     */
    public function indexSearchApi(array|string $searchName, int $limit): array
    {
        $keywords = $this->sanitize($searchName);
        try {
            $search = [
                'scroll' => '30s',
                'index' => 'releases',
                'body' => [
                    'query' => [
                        'query_string' => [
                            'query' => $keywords,
                            'fields' => ['searchname', 'plainsearchname', 'fromname', 'filename', 'name', 'categories_id'],
                            'analyze_wildcard' => true,
                            'default_operator' => 'and',
                        ],
                    ],
                    'size' => $limit,
                    'sort' => [
                        'add_date' => [
                            'order' => 'desc',
                        ],
                        'post_date' => [
                            'order' => 'desc',
                        ],
                    ],
                ],
            ];

            return $this->search($search);
        } catch (BadRequest400Exception $request400Exception) {
            return [];
        }
    }

    /**
     * Search function used in TV, TV API, Movies and Anime searches.
     *
     * @param  array|string  $name
     * @param  int  $limit
     * @return array
     */
    public function indexSearchTMA(array|string $name, int $limit): array
    {
        $keywords = $this->sanitize($name);
        try {
            $search = [
                'scroll' => '30s',
                'index' => 'releases',
                'body' => [
                    'query' => [
                        'query_string' => [
                            'query' => $keywords,
                            'fields' => ['searchname', 'plainsearchname'],
                            'analyze_wildcard' => true,
                            'default_operator' => 'and',
                        ],
                    ],
                    'size' => $limit,
                    'sort' => [
                        'add_date' => [
                            'order' =>'desc',
                        ],
                        'post_date' => [
                            'order' => 'desc',
                        ],
                    ],
                ],
            ];

            return $this->search($search);
        } catch (BadRequest400Exception $request400Exception) {
            return [];
        }
    }

    /**
     * @param  array|string  $search
     * @return array|\Illuminate\Support\Collection
     */
    public function predbIndexSearch(array|string $search): array|\Illuminate\Support\Collection
    {
        try {
            $search = [
                'scroll' => '30s',
                'index' => 'predb',
                'body' => [
                    'query' => [
                        'query_string' => [
                            'query' => $search,
                            'fields' => ['title'],
                            'analyze_wildcard' => true,
                            'default_operator' => 'and',
                        ],
                    ],
                    'size' => 1000,
                ],
            ];

            return $this->search($search);
        } catch (BadRequest400Exception $request400Exception) {
            return [];
        }
    }

    /**
     * @param  array  $parameters
     */
    public function insertRelease(array $parameters): void
    {
        $searchNameDotless = str_replace(['.', '-'], ' ', $parameters['searchname']);
        $data = [
            'body' => [
                'id' => $parameters['id'],
                'name' => $parameters['name'],
                'searchname' => $parameters['searchname'],
                'plainsearchname' => $searchNameDotless,
                'fromname' => $parameters['fromname'],
                'categories_id' => $parameters['categories_id'],
                'filename' => $parameters['filename'] ?? '',
                'add_date' => now()->format('Y-m-d H:i:s'),
                'post_date' => $parameters['postdate'],
            ],
            'index' => 'releases',
            'id' => $parameters['id'],
        ];

        \Elasticsearch::index($data);
    }

    /**
     * @param  int  $id
     */
    public function updateRelease(int $id): void
    {
        $new = Release::query()
            ->where('releases.id', $id)
            ->leftJoin('release_files as rf', 'releases.id', '=', 'rf.releases_id')
            ->select(['releases.id', 'releases.name', 'releases.searchname', 'releases.fromname', 'releases.categories_id', DB::raw('IFNULL(GROUP_CONCAT(rf.name SEPARATOR " "),"") filename')])
            ->groupBy('releases.id')
            ->first();
        if ($new !== null) {
            $searchNameDotless = str_replace(['.', '-'], ' ', $new->searchname);
            $data = [
                'body' => [
                    'doc' => [
                        'id' => $new->id,
                        'name' => $new->name,
                        'searchname' => $new->searchname,
                        'plainsearchname' => $searchNameDotless,
                        'fromname' => $new->fromname,
                        'categories_id' => $new->categories_id,
                        'filename' => $new->filename,
                    ],
                    'doc_as_upsert' => true,
                ],

                'index' => 'releases',
                'id' => $new->id,
            ];

            \Elasticsearch::update($data);
        }
    }

    /**
     * @param $searchTerm
     * @return array
     */
    public function searchPreDb($searchTerm): array
    {
        $search = [
            'index' => 'predb',
            'body' => [
                'query' => [
                    'query_string' => [
                        'query' => $searchTerm,
                        'fields' => ['title', 'filename'],
                        'analyze_wildcard' => true,
                        'default_operator' => 'and',
                    ],
                ],
            ],
        ];

        try {
            $primaryResults = \Elasticsearch::search($search);

            $results = [];
            foreach ($primaryResults['hits']['hits'] as $primaryResult) {
                $results[] = $primaryResult['_source'];
            }
        } catch (BadRequest400Exception $badRequest400Exception) {
            return [];
        }

        return $results;
    }

    /**
     * @param  array  $parameters
     */
    public function insertPreDb(array $parameters): void
    {
        $data = [
            'body' => [
                'id' => $parameters['id'],
                'title' => $parameters['title'],
                'source' => $parameters['source'],
                'filename' => $parameters['filename'],
            ],
            'index' => 'predb',
            'id' => $parameters['id'],
        ];

        \Elasticsearch::index($data);
    }

    /**
     * @param  array  $parameters
     */
    public function updatePreDb(array $parameters): void
    {
        $data = [
            'body' => [
                'doc' => [
                    'id' => $parameters['id'],
                    'title' => $parameters['title'],
                    'filename' => $parameters['filename'],
                    'source' => $parameters['source'],
                ],
                'doc_as_upsert' => true,
            ],

            'index' => 'predb',
            'id' => $parameters['id'],
        ];

        \Elasticsearch::update($data);
    }

    /**
     * @param  array|string  $phrases
     * @return string
     */
    private function sanitize(array|string $phrases): string
    {
        if (! is_array($phrases)) {
            $wordArray = explode(' ', str_replace('.', ' ', $phrases));
        } else {
            $wordArray = $phrases;
        }

        $keywords = [];
        $tempWords = [];
        foreach ($wordArray as $words) {
            $words = preg_split('/\s+/', $words);
            foreach ($words as $st) {
                if (Str::startsWith($st, ['!', '+', '-', '?', '*']) && Str::length($st) > 1 && ! preg_match('/(!|\+|\?|-|\*){2,}/', $st)) {
                    $str = $st;
                } elseif (Str::endsWith($st, ['+', '-', '?', '*']) && Str::length($st) > 1 && ! preg_match('/(!|\+|\?|-|\*){2,}/', $st)) {
                    $str = $st;
                } else {
                    $str = Sanitizer::escape($st);
                }
                $tempWords[] = $str;
            }

            $keywords = $tempWords;
        }

        return implode(' ', $keywords);
    }

    /**
     * @param  array  $search
     * @param  bool  $fullResults
     * @return array|\Illuminate\Support\Collection
     */
    protected function search(array $search, bool $fullResults = false): array|\Illuminate\Support\Collection
    {
        $results = \Elasticsearch::search($search);

        $searchResult = [];
        while (isset($results['hits']['hits']) && count($results['hits']['hits']) > 0) {
            foreach ($results['hits']['hits'] as $result) {
                if ($fullResults === true) {
                    $searchResult[] = $result['_source'];
                } else {
                    $searchResult[] = $result['_source']['id'];
                }
            }
            // When done, get the new scroll_id
            // You must always refresh your _scroll_id!  It can change sometimes
            $scroll_id = $results['_scroll_id'];

            // Execute a Scroll request and repeat
            $results = \Elasticsearch::scroll([
                'scroll_id' => $scroll_id,  //...using our previously obtained _scroll_id
                'scroll' => '30s',        // and the same timeout window
            ]
            );
        }
        if (empty($searchResult)) {
            return [];
        }

        return $searchResult;
    }
}
