<?php

class SXUrlGenerator {
    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'SX-URL-Generator/1.0 (tayodele-ctr@wikimedia.org)'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 && $response) ? $response : null;
    }

    public function getEnglishTitles($targetTitles, $targetLang) {
        // Join all titles using | for small lists of (10-20) articles, we can do them all at once
        $titlesBatch = implode('|', $targetTitles);

        $url = "https://www.wikidata.org/w/api.php?" . http_build_query([
            'action' => 'wbgetentities',
            'sites' => $targetLang . 'wiki',
            'titles' => $titlesBatch,
            'props' => 'sitelinks',
            'format' => 'json'
        ]);

        $response = $this->httpGet($url);
        if (!$response) return [];

        $data = json_decode($response, true);
        if (isset($data['error']) || !isset($data['entities'])) return [];

        $results = [];
        foreach ($data['entities'] as $entity) {
            if (isset($entity['sitelinks']['enwiki'])) {
                // Find which original title this entity corresponds to
                foreach ($entity['sitelinks'] as $site => $sitelink) {
                    if (strpos($site, $targetLang) === 0) {
                        $originalTitle = $sitelink['title'];
                        $results[$originalTitle] = $entity['sitelinks']['enwiki']['title'];
                        break;
                    }
                }
            }
        }

        return $results;
    }

    public function articleExists($title, $lang = 'en') {
        $url = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
            'action' => 'query',
            'titles' => $title,
            'format' => 'json'
        ]);

        $response = $this->httpGet($url);
        if (!$response) return false;

        $data = json_decode($response, true);
        if (isset($data['error'])) return false;

        foreach ($data['query']['pages'] ?? [] as $page) {
            if (!isset($page['missing']) && !isset($page['invalid'])) {
                return true;
            }
        }

        return false;
    }

    public function generateSxUrl($sourceTitle, $fromLang, $toLang) {
        $encodedTitle = urlencode($sourceTitle);
        return "https://{$toLang}.wikipedia.org/w/index.php?" . http_build_query([
            'title' => 'Special:ContentTranslation',
            'page' => $sourceTitle,
            'from' => $fromLang,
            'to' => $toLang
        ]);
    }

    public function generateTable($articles, $targetLang, $sourceLang = 'en', $maxUrls = 20) {
        $rows = [];
        $urlCount = 0;

        // OPTIMIZED: Single Wikidata API call for all articles
        $englishTitles = $this->getEnglishTitles($articles, $targetLang);

        // Process each article with pre-fetched English titles
        foreach ($articles as $i => $article) {
            if ($urlCount >= $maxUrls) break;

            $num = $i + 1;
            $englishTitle = $englishTitles[$article] ?? null;

            if (!$englishTitle) {
                $rows[] = $this->createRow($num, $article, null, $targetLang, $sourceLang, "❌ No {$sourceLang} equivalent");
                continue;
            }

            // Individual checks for article existence/ new data exists
            if ($this->articleExists($englishTitle, $sourceLang)) {
                $sxUrl = $this->generateSxUrl($englishTitle, $sourceLang, $targetLang);
                $rows[] = $this->createRow($num, $article, $englishTitle, $targetLang, $sourceLang, '✅ Ready', $sxUrl);
                $urlCount++;
            } else {
                $rows[] = $this->createRow($num, $article, $englishTitle, $targetLang, $sourceLang, '❌ No source');
            }

            sleep(1); // Rate limiting API calls not to overload server
        }

        return $this->formatTable($rows, $sourceLang, $targetLang);
    }

    private function createRow($num, $targetArticle, $sourceArticle, $targetLang, $sourceLang, $status, $sxUrl = null) {
        $targetLink = "[[:{$targetLang}:{$targetArticle}|{$targetArticle}]]";
        $sourceLink = $sourceArticle ? "[[:{$sourceLang}:{$sourceArticle}|{$sourceArticle}]]" : "''Not found on Wikidata''";
        $translationLink = $sxUrl ? "[{$sxUrl} Start Translation]" : "<span style=\"color: #ccc;\">Source missing</span>";

        return compact('num', 'targetLink', 'sourceLink', 'translationLink', 'status');
    }

    private function formatTable($rows, $sourceLang, $targetLang) {
        $header = "{| class=\"wikitable sortable\"\n|+ Section Translation Articles (from {$sourceLang} to {$targetLang})\n|-\n! # !! Target Article !! Source Article !! SX Link !! Status";

        $tableRows = array_map(function($row) {
            return "|-\n| {$row['num']} || {$row['targetLink']} || {$row['sourceLink']} || {$row['translationLink']} || {$row['status']}";
        }, $rows);

        return $header . "\n" . implode("\n", $tableRows) . "\n|}";
    }
}

// Configuration - typical use case
$config = [
    'targetLang' => 'yo',
    'sourceLang' => 'en',
    'maxUrls' => 15,
    'articles' => [
        'Ìjàmbá ìtúká ẹ̀búté Bèírùtù ọdún 2020',
		'Ìbàdàn',
        'Lágós',
        'Sáyẹ́ǹsì',
        'Nàìjíríà',
    ]
];

// Execution
$generator = new SXUrlGenerator();
$wikiTable = $generator->generateTable(
    $config['articles'],
    $config['targetLang'],
    $config['sourceLang'],
    $config['maxUrls']
);

// Output
echo "=== GENERATED WIKI TABLE ===\n$wikiTable\n";

file_put_contents("sx_urls_{$config['sourceLang']}_to_{$config['targetLang']}.wiki", $wikiTable);
echo "Saved to: sx_urls_{$config['sourceLang']}_to_{$config['targetLang']}.wiki\n";

?>
