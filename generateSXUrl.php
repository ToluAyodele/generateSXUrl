<?php

class SXUrlGenerator {

    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'SX-URL-Generator/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 && $response) ? $response : null;
    }

    public function getEnglishTitle($targetTitle, $targetLang) {
        $url = "https://www.wikidata.org/w/api.php?" . http_build_query([
            'action' => 'wbgetentities',
            'sites' => $targetLang . 'wiki',
            'titles' => $targetTitle,
            'props' => 'sitelinks',
            'format' => 'json'
        ]);

        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        if (isset($data['error']) || !isset($data['entities'])) return null;

        foreach ($data['entities'] as $entity) {
            if (isset($entity['sitelinks']['enwiki'])) {
                return $entity['sitelinks']['enwiki']['title'];
            }
        }

        return null;
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
        return "https://{$fromLang}.wikipedia.org/w/index.php?" . http_build_query([
            'title' => 'Special:ContentTranslation',
            'filter-type' => 'automatic',
            'filter-id' => 'previous-edits',
            'from' => $fromLang,
            'to' => $toLang,
            'active-list' => 'suggestions',
            'page' => $sourceTitle
        ]) . '#/sx/section-selector';
    }

    public function generateTable($articles, $targetLang, $sourceLang = 'en', $maxUrls = 20) {
        $rows = [];
        $urlCount = 0;

        foreach ($articles as $i => $article) {
            if ($urlCount >= $maxUrls) break;

            $num = $i + 1;
            $englishTitle = $this->getEnglishTitle($article, $targetLang);

            if (!$englishTitle) {
                $rows[] = $this->createRow($num, $article, null, $targetLang, $sourceLang, "❌ No {$sourceLang} equivalent");
                continue;
            }

            if ($this->articleExists($englishTitle, $sourceLang)) {
                $sxUrl = $this->generateSxUrl($englishTitle, $sourceLang, $targetLang);
                $rows[] = $this->createRow($num, $article, $englishTitle, '✅ Ready', $targetLang, $sourceLang, $sxUrl);
                $urlCount++;
            } else {
                $rows[] = $this->createRow($num, $article, $englishTitle, $targetLang, $sourceLang, '❌ No source');
            }

            sleep(1); // Rate limiting
        }

        return $this->formatTable($rows, $sourceLang, $targetLang);
    }

    private function createRow($num, $targetArticle, $sourceArticle, $status, $targetLang, $sourceLang, $sxUrl = null) {
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