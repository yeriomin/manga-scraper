#!/usr/bin/env php
<?php
abstract class MangaProvider {

    const DOMAIN = '';

    protected $domain;

    protected $mangaName;

    protected $chapterFrom;

    protected $chapterTo;

    protected $directory = '.';

    protected $overwrite = false;

    abstract protected function getChapterList();

    abstract protected function getPageList($chapterUrl);

    abstract protected function getImageUrl($pageUrl);

    public function __construct($domain = null) {
        $this->domain = empty($domain) ? static::DOMAIN : $domain;
    }

    public function setMangaName($mangaName) {
        $this->mangaName = $mangaName;
        return $this;
    }

    public function setChapterFrom($chapterFrom) {
        $this->chapterFrom = $chapterFrom;
        return $this;
    }

    public function setChapterTo($chapterTo) {
        $this->chapterTo = (float) $chapterTo;
        return $this;
    }

    public function setDirectory($directory) {
        $this->directory = rtrim($directory, '/');
        return $this;
    }

    public function setOverwrite($overwrite = true) {
        $this->overwrite = (boolean) $overwrite;
        return $this;
    }

    public function doIt() {
        $chapters = array();
        $this->log('Getting available chapters list... ', false);
        try {
            $chapters = $this->getChapterList();
            $chapters = $this->filterChapters($chapters);
            $this->log(empty($chapters)
                ? 'no chapters match your selection'
                : 'done, ' . count($chapters) . ' will be downloaded'
            );
        } catch (Exception $e) {
            $this->log('failed: ' . $e->getMessage());
        }
        $this->downloadChapters($chapters);
        $this->log('All done');
    }

    protected function log($message, $newline = true) {
        echo $message . ($newline ? "\n" : '');
    }

    protected function downloadChapters(array $chapters) {
        foreach ($chapters as $chapter => $chapterUrl) {
            $this->log(
                'Getting page list for chapter ' . $chapter . '... ',
                false
            );
            $pages = array();
            try {
                $pages = $this->getPageList($chapterUrl);
                $this->log('done, ' . count($pages) . ' pages in chapter');
            } catch (Exception $e) {
                $this->log('error: ' . $e->getMessage() . ', skipping');
                continue;
            }
            $this->downloadPages($pages, $chapter);
        }
    }

    protected function downloadPages(array $pages, $chapter) {
        foreach ($pages as $page => $pageUrl) {
            $this->log(
                'Downloading page ' . $page . '... ',
                false
            );
            $filename = $this->directory . '/' . $chapter . '-'
                . sprintf('%03d', $page) . '.jpg'
            ;
            if (!$this->overwrite && file_exists($filename)) {
                $this->log('found locally, skipping');
                continue;
            }
            $imageUrl = '';
            try {
                $imageUrl = $this->getImageUrl($pageUrl);
            } catch (Exception $e) {
                $this->log('error: ' . $e->getMessage() . ', skipping');
                continue;
            }
            $this->log('downloading image... ', false);
            $content = @file_get_contents($imageUrl);
            if ($content === false) {
                $this->log('error, skipping');
            } else {
                file_put_contents($filename, $content);
                $this->log('done');
            }
        }
    }

    protected function getDom($pageUrl, $headers = array()) {
        $dom = new DOMDocument();
        $options = array(
            'http' => array(
                'method' => "GET",
                'header' =>
                    "Referer: http://mangafox.me/manga/bleach/vTBD/c635/1.html\r\n".
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.101 Safari/537.36\r\n"
            )
        );
        $context = stream_context_create($options);
        $content = @file_get_contents($pageUrl, false, $context);
        if (empty($content)) {
            $responseHeader = explode(' ', $http_response_header[0]);
            throw new Exception('could not download page ' . $pageUrl
                . ', HTTP code ' . $responseHeader[1]
            );
        }
        if (@$dom->loadHTML($content) === false) {
            $lastError = libxml_get_last_error();
            throw new Exception('could not load page into dom, '
                . ($lastError !== false ? $lastError->code . ' ' . $lastError->message : '')
            );
        }
        return $dom;
    }

    protected function getDomCurl($pageUrl) {
        $dom = new DOMDocument();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $pageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        $content = curl_exec($ch);
        if (empty($content)) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            throw new Exception('could not download page ' . $lastUrl
                . ', HTTP code ' . $httpCode
            );
        }
        curl_close($ch);
        if (@$dom->loadHTML($content) === false) {
            $lastError = libxml_get_last_error();
            throw new Exception('could not load page into dom, '
                . ($lastError !== false ? $lastError->code . ' ' . $lastError->message : '')
            );
        }
        return $dom;
    }

    private function filterChapters($chapters) {
        $filteredChapters = array();
        if (empty($this->chapterFrom)) {
            $keys = array_keys($chapters);
            $filteredChapters[array_pop($keys)] = array_pop($chapters);
        } else if (empty($this->chapterTo)) {
            if (isset($chapters[$this->chapterFrom])) {
                $filteredChapters[$this->chapterFrom] = $chapters[$this->chapterFrom];
            }
        } else {
            $fits = false;
            foreach ($chapters as $chapter => $url) {
                if (is_numeric($chapter) && $chapter >= $this->chapterFrom) {
                    $fits = true;
                }
                if ($chapter > $this->chapterTo) {
                    break;
                }
                if ($fits) {
                    $filteredChapters[$chapter] = $url;
                }
            }
        }
        return $filteredChapters;
    }

}

class Mangakaka extends MangaProvider {

    const DOMAIN = 'www.mangakaka.com';

    protected function getChapterList() {
        $chapterList = array();
        $listUrl = 'http://' . $this->domain . '/' . $this->mangaName;
        $dom = $this->getDom($listUrl);
        foreach ($dom->getElementsByTagName('ul') as $listElement) {
            if ($listElement->getAttribute('class') == 'lst') {
                foreach ($listElement->getElementsByTagName('a') as $chapterElement) {
                    $parts = explode(' ', $chapterElement->getAttribute('title'));
                    $chapterName = array_pop($parts);
                    $chapterUrl = rtrim($chapterElement->getAttribute('href'), '/');
                    $chapterList[$chapterName] = $chapterUrl;
                }
            }
        }
        return array_reverse($chapterList, true);
    }

    protected function getPageList($chapterUrl) {
        $pageList = array();
        $dom = $this->getDom($chapterUrl);
        $pageCount = 0;
        foreach ($dom->getElementsByTagName('select') as $selectElement) {
            if ($selectElement->getAttribute('class') == 'cbo_wpm_pag') {
                $pageCount = $selectElement->getElementsByTagName('option')->length;
            }
        }
        for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
            $pageList[$pageNum] = $chapterUrl . '/' . $pageNum;
        }
        return $pageList;
    }

    protected function getImageUrl($pageUrl) {
        $dom = $this->getDom($pageUrl);
        foreach ($dom->getElementsByTagName('img') as $imgElement) {
            if ($imgElement->getAttribute('class') == 'manga-page') {
                return $imgElement->getAttribute('src');
            }
        }
        throw new Exception('could not find image element');
    }
}

class Mangastream extends MangaProvider {

    const DOMAIN = 'mangastream.com';

    protected function getChapterList() {
        $chapterList = array();
        $listUrl = 'http://' . $this->domain . '/manga/' . $this->mangaName;
        $dom = $this->getDom($listUrl);
        foreach ($dom->getElementsByTagName('table') as $listElement) {
            foreach ($listElement->getElementsByTagName('a') as $chapterElement) {
                $parts = explode(' ', $chapterElement->nodeValue);
                $chapterName = array_shift($parts);
                $chapterUrl = rtrim($chapterElement->getAttribute('href'), '1/');
                $chapterList[$chapterName] = $chapterUrl;
            }
        }
        return array_reverse($chapterList, true);
    }

    protected function getPageList($chapterUrl) {
        $pageList = array();
        $dom = $this->getDom($chapterUrl);
        $pageCount = 0;
        foreach ($dom->getElementsByTagName('ul') as $selectElement) {
            if ($selectElement->getAttribute('class') == 'dropdown-menu') {
                $pageCount = $selectElement->getElementsByTagName('li')->length;
            }
        }
        $pageNum = 1;
        while ($pageNum <= $pageCount) {
            $pageList[$pageNum] = $chapterUrl . '/' . $pageNum;
            $pageNum++;
        }
        return $pageList;
    }

    protected function getImageUrl($pageUrl) {
        $imgElement = $this->getDom($pageUrl)->getElementById('manga-page');
        if (null === $imgElement) {
            throw new Exception('could not find image element');
        } else {
            return $imgElement->getAttribute('src');
        }
    }
}

class Mangafox extends MangaProvider {

    const DOMAIN = 'mangafox.me';

    protected function getChapterList() {
        $chapterList = array();
        $listUrl = 'http://' . $this->domain . '/manga/' . $this->mangaName;
        $dom = $this->getDom($listUrl);
        $listElement = $dom->getElementById('chapters');
        foreach ($listElement->getElementsByTagName('a') as $chapterElement) {
            if ($chapterElement->getAttribute('class') !== 'tips') {
                continue;
            }
            $parts = explode(' ', $chapterElement->nodeValue);
            $chapterName = array_pop($parts);
            $chapterUrl = substr($chapterElement->getAttribute('href'), 0, -7);
            $chapterList[$chapterName] = $chapterUrl;
        }
        return array_reverse($chapterList, true);
    }

    protected function getPageList($chapterUrl) {
        $pageList = array();
        $dom = $this->getDom($chapterUrl);
        $pageCount = 0;
        foreach ($dom->getElementsByTagName('select') as $selectElement) {
            if ($selectElement->getAttribute('class') == 'm') {
                $pageCount = $selectElement->getElementsByTagName('option')->length - 1;
            }
        }
        $pageNum = 1;
        while ($pageNum <= $pageCount) {
            $pageList[$pageNum] = $chapterUrl . '/' . $pageNum . '.html';
            $pageNum++;
        }
        return $pageList;
    }

    protected function getImageUrl($pageUrl) {
        $imgElement = $this->getDom($pageUrl)->getElementById('image');
        if (null === $imgElement) {
            throw new Exception('could not find image element');
        } else {
            return $imgElement->getAttribute('src');
        }
    }
}

class Mangafreak extends MangaProvider {

    const DOMAIN = 'mangafreak.co';

    protected function getChapterList() {
        $chapterList = array();
        $listUrl = 'http://' . $this->domain . '/series1/' . $this->mangaName;
        $dom = $this->getDom($listUrl);
        $listElement = $dom->getElementById('listing');
        foreach ($listElement->getElementsByTagName('a') as $chapterElement) {
            $parts = explode(' ', $chapterElement->nodeValue);
            $chapterName = $parts[1];
            $chapterUrl = 'http://' . $this->domain . $chapterElement->getAttribute('href');
            $chapterList[$chapterName] = $chapterUrl;
        }
        return array_reverse($chapterList, true);
    }

    protected function getPageList($chapterUrl) {
        $pageList = array();
        $dom = $this->getDom($chapterUrl);
        $pageCount = 0;
        $selectElement = $dom->getElementById('pageMenu');
        if (null === $selectElement) {
            throw new Exception('could not find page list element');
        } else {
            $pageCount = $selectElement->getElementsByTagName('option')->length;
        }
        $pageNum = 1;
        while ($pageNum <= $pageCount) {
            $pageList[$pageNum] = $chapterUrl . '/' . $pageNum;
            $pageNum++;
        }
        return $pageList;
    }

    protected function getImageUrl($pageUrl) {
        $imgElement = $this->getDom($pageUrl)->getElementById('img');
        if (null === $imgElement) {
            throw new Exception('could not find image element');
        } else {
            return $imgElement->getAttribute('src');
        }
    }
}

class Mangapanda extends Mangafreak {

    const DOMAIN = 'www.mangapanda.com';

    protected function getChapterList() {
        $chapterList = array();
        $listUrl = 'http://' . $this->domain . '/' . $this->mangaName;
        $dom = $this->getDom($listUrl);
        $listElement = $dom->getElementById('listing');
        foreach ($listElement->getElementsByTagName('a') as $chapterElement) {
            $parts = explode(' ', $chapterElement->nodeValue);
            $chapterName = $parts[1];
            $chapterUrl = 'http://' . $this->domain . $chapterElement->getAttribute('href');
            $chapterList[$chapterName] = $chapterUrl;
        }
        return array_reverse($chapterList, true);
    }

    protected function getPageList($chapterUrl) {
        $pageList = array();
        $dom = $this->getDom($chapterUrl);
        $pageCount = 0;
        $selectElement = $dom->getElementById('pageMenu');
        if (null === $selectElement) {
            throw new Exception('could not find page list element');
        } else {
            $pageCount = $selectElement->getElementsByTagName('option')->length;
        }
        $pageNum = 1;
        while ($pageNum <= $pageCount) {
            $pageList[$pageNum] = $chapterUrl . '/' . $pageNum;
            $pageNum++;
        }
        return $pageList;
    }

    protected function getImageUrl($pageUrl) {
        $imgElement = $this->getDom($pageUrl)->getElementById('img');
        if (null === $imgElement) {
            throw new Exception('could not find image element');
        } else {
            return $imgElement->getAttribute('src');
        }
    }
}

class MangaProviderFactory {

    const CLASSNAME = 'MangaProvider';

    static public function get() {
        try {
            $options = self::getopt();
        } catch (Exception $e) {
            echo $e->getmessage() . "\n\n" . self::getUsageMessage();
            exit(1);
        }
        $providerName = $options['s'];
        $provider = new $providerName();
        $provider
            ->setMangaName($options['m'])
            ->setOverwrite(isset($options['r']))
        ;
        if (isset($options['f'])) {
            $provider->setChapterFrom($options['f']);
        }
        if (isset($options['t'])) {
            $provider->setChapterTo($options['t']);
        }
        if (isset($options['d'])) {
            $provider->setDirectory($options['d']);
        }
        return $provider;
    }

    static private function getopt() {
        $options = getopt('m:f:t:d:s:r');
        if (empty($options['s']) || !is_subclass_of($options['s'], self::CLASSNAME)) {
            throw new Exception('Please select a manga site from the below list');
        }
        if (empty($options['m'])) {
            throw new Exception('Please provide the manga name');
        }
        if (isset($options['t']) && is_numeric($options['t']) && $options['t'] < $options['f']) {
            throw new Exception('Upper range chapter number has to be greater than ' . $chapterFrom);
        }
        $targetDirectory = empty($options['d']) ? '.' : rtrim($options['d'], '/');
        if (!file_exists($targetDirectory)
            || !is_dir($targetDirectory)
            || !is_writable($targetDirectory)
        ) {
            throw new Exception('Target directory does not exist or is not writable');
        }
        return $options;
    }

    static private function getUsageMessage() {
        $usageMessage = 'Usage: php ' . $_SERVER['PHP_SELF'] . ' -s <name> -m <name> [-f <number>] [-t <number>] [-d <directory>] [-r]' . "\n";
        $usageMessage .= '  php ' . $_SERVER['PHP_SELF'] . ' -m bleach-manga' . "\n";
        $usageMessage .= '  php ' . $_SERVER['PHP_SELF'] . ' -m bleach-manga -f 300 -t 320 -d "~/bleach-archive" -r' . "\n";
        $usageMessage .= "\n";
        $usageMessage .= ' -s <name>        Manga site name. See the supported sites list below' . "\n";
        $usageMessage .= ' -m <name>        Manga name as appears in manga site url' . "\n";
        $usageMessage .= ' -f <number>      Manga chapter number which you want to download' . "\n";
        $usageMessage .= ' -t <number>      Last manga chapter number to download. Lets you choose a range of chapters to download.' . "\n";
        $usageMessage .= '                  The range starts from "-f". Defaults to the value of "-f" meaning only one chapter is to be downloaded' . "\n";
        $usageMessage .= ' -d <directory>   Directory where the manga is going to be saved. Defaults to current directory' . "\n";
        $usageMessage .= ' -r               Rewrite pages found locally or not. Defaults to "not rewrite"' . "\n";
        $usageMessage .= "\n";
        $usageMessage .= 'Supported manga sites:' . "\n";
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, self::CLASSNAME)) {
                $providers[] = $class;
                $usageMessage .= ' ' . $class . "\n";
            }
        }
        $usageMessage .= "\n\n";
        return $usageMessage;
    }
}

$provider = MangaProviderFactory::get();
$provider->doIt();
