<?php

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Symfony\Component\DomCrawler\Crawler;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;

set_time_limit(0);
require_once 'vendor/autoload.php';
// the URL to the local Selenium Server
$host = 'http://127.0.0.1:4444/';

// to control a Chrome instance
$capabilities = DesiredCapabilities::chrome();

// define the browser options
$chromeOptions = new ChromeOptions();
// to run Chrome in headless mode
$chromeOptions->addArguments(['--headless']); // <- comment out for testing

// register the Chrome options
$capabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $chromeOptions);

// initialize a driver to control a Chrome instance
$driver = RemoteWebDriver::create($host, $capabilities);

// maximize the window to avoid responsive rendering
$driver->manage()->window()->maximize();

$url = 'https://popcornfilmz.com/tv/watch-3rd-rock-from-the-sun-online-155/season-1-episode-1';
$driver->get($url);
$driver->wait(20)->until(
    WebDriverExpectedCondition::presenceOfElementLocated(
        WebDriverBy::cssSelector('div.blurred-bg-img')
    )
);
$html = $driver->getPageSource();
echo $html;
die();
dd($html);
file_put_contents('test.html', $html);
$crawler = new Crawler($html);
$table = $crawler->filter('div.blurred-bg-img')->first();
$html = $table->html();

$records = [];
for ($i = 1; $i <= 514; $i++) {
    $currentUrl = sprintf($url, $i);
    $crawler = new Crawler($html);
    $table = $crawler->filter('table.expand')->first();
    $table->filter('tr')->each(function (Crawler $tr) use (&$records) {
        if ($tr->filter('td')->count() == 0) {
            return;
        }
        $name = $tr->filter('td')->eq(1)->text();
        $type = $tr->filter('td')->eq(2)->text();
        $year = $tr->filter('td')->eq(4)->text();
        $records[] = compact('name', 'type', 'year');
    });
    dd($records);
}
/*foreach ($urls as $url) {
    $crawler = new Crawler($html);
    $record = [];
    $crawler = $crawler->filter('div.c-product__wrap.c-product__wrap--layout-4');
    $title = $crawler->filter('h1.c-product__title');
    $sku = $crawler->filter('[data-hook="sku"]');
    $desc = $crawler->filter('div#tab-description');
    $notes = $crawler->filter('td.woocommerce-product-attributes-item__value');
    $images = $crawler->filter('.c-product__thumbs-item');
    $price = $crawler->filter('span.woocommerce-Price-amount.amount');
    if ($title->count() > 0) {
        $record['title'] = $title->first()->text();
    }
    if ($sku->count() > 0) {
        $record['sku'] = $sku->first()->text();
    }
    if ($desc->count() > 0) {
        $record['description'] = $desc->first()->text();
    }
    if ($notes->count() > 0) {
        $record['notes'] = $notes->first()->text();
    }


    if ($images->count() > 0) {
        $imageSources = $images->each(function (Crawler $imgWrap) {
            return $imgWrap->filter('img')->first()->attr('srcset');
        });
        foreach ($imageSources as $k => $img) {
            $img = explode(',', $img);
            $img = $img[count($img) - 1];
            $img = str_replace('800w', '', $img);
            $imageSources[$k] = trim($img);
        }
//        $image = $record['title'] . '.jpg';
//        $imageCode = get($images->first()->filter('img')->first()->attr('src'));
//        file_put_contents('reinvented/' . $image, $imageCode);
        $record['images'] = implode(',',$imageSources);
    }
    if ($price->count() > 0) {
        $price = str_replace('AED', '', $price->first()->text());
        $price = str_replace(',', '.', $price);
        $price *= 0.27227;
        $record['price'] = number_format($price, 1, '.', '');
    }
    $records[] = $record;
}*/

$driver->close();
