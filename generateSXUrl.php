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

    // BATCH PROCESSING FOR WIKIDATA API
    public function getEnglishTitlesBatch($targetTitles, $targetLang) {
        // Join up to 50 titles with pipe separator
        $titlesBatch = implode('|', array_slice($targetTitles, 0, 50));

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
                // Find which original title this wikidata entity corresponds to
                foreach ($entity['sitelinks'] as $site => $sitelink) {
                    if (strpos($site, $targetLang) === 0) { // e.g., 'yowiki'
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
        // Using Wikipedia.org (more reliable than assuming language code pattern)
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

    // Improved SX URL generation
    public function generateSxUrl($sourceTitle, $fromLang, $toLang) {
        $encodedTitle = urlencode($sourceTitle);
        //Change url destination tO language wiki
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

        // Process articles in batches for wikidata
        $batchSize = 50;
        $allEnglishTitles = [];

        // Process Wikidata lookups in batches
        for ($i = 0; $i < count($articles); $i += $batchSize) {
            $batch = array_slice($articles, $i, $batchSize);
            $batchResults = $this->getEnglishTitlesBatch($batch, $targetLang);
            $allEnglishTitles = array_merge($allEnglishTitles, $batchResults);

            // Rate limiting between batches
            if ($i + $batchSize < count($articles)) {
                sleep(1);
            }
        }

        // Process each article with pre-fetched English titles
        foreach ($articles as $i => $article) {
            if ($urlCount >= $maxUrls) break;

            $num = $i + 1;
            $englishTitle = $allEnglishTitles[$article] ?? null;

            if (!$englishTitle) {
                $rows[] = $this->createRow($num, $article, null, $targetLang, $sourceLang, "❌ No {$sourceLang} equivalent");
                continue;
            }

            // Check if source article exists (individual calls for freshness)
            if ($this->articleExists($englishTitle, $sourceLang)) {
                $sxUrl = $this->generateSxUrl($englishTitle, $sourceLang, $targetLang);
                $rows[] = $this->createRow($num, $article, $englishTitle, $targetLang, $sourceLang, '✅ Ready', $sxUrl);
                $urlCount++;
            } else {
                $rows[] = $this->createRow($num, $article, $englishTitle, $targetLang, $sourceLang, '❌ No source');
            }
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

// Configuration
$config = [
    'targetLang' => 'yo',
    'sourceLang' => 'en',
    'maxUrls' => 15,
    'articles' => [
        'Ìjàmbá ìtúká ẹ̀búté Bèírùtù ọdún 2020',
        'Ìbàdàn'
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