<?php

namespace App\Services;

use App\Traits\Resilience\HasCircuitBreaker;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Illuminate\Support\Facades\Cache;

class EduixScraperService
{
    use HasCircuitBreaker;
    protected array $mapping = [
        'نام'                  => 'name',
        'نام خانوادگی'        => 'family',
        'نام پدر'             => 'father_name',
        'کدملی'               => 'national_code',
        'شماره شناسنامه'      => 'id_number',
        'تاریخ تولد'          => 'birth_date',
        'دانشگاه'             => 'university',
        'رشته تحصیلی'         => 'field',
        'نوع دوره قبولی'      => 'course_type',
        'تاریخ شروع تحصیل'    => 'entry_date',
        'تاریخ فارغ التحصیلی' => 'graduate_date',
        'معدل'                => 'gpa',
        'وضعیت تحصیلی'        => 'status',
    ];

    protected $client;

    public function __construct()
    {
        $this->client = HttpClient::create([
            'headers'      => ['User-Agent' => 'Mozilla/5.0 (compatible; EduixScraper/1.0)'],
            'timeout'      => 5,
            'max_duration' => 5,
            'http_version' => '2.0',
        ]);

        $this->cbThreshold = 5;
        $this->cbTimeout = 300;
    }

    public function fetchData(string $nationalCode, string $traceCode): array
    {
        $cacheKey = "eduix:{$nationalCode}:{$traceCode}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($nationalCode, $traceCode) {

            return $this->executeProtected('eduix_scraper', function () use ($nationalCode, $traceCode) {

                $url = "https://inquiry.eduix.msrt.ir/show?nationalCode={$nationalCode}&traceCode={$traceCode}";

                $html = $this->client->request('GET', $url)->getContent();

                $crawler = new Crawler($html);
                $data = array_fill_keys(array_values($this->mapping), null);

                $crawler->filter('.card-body .mb-3')->each(function (Crawler $node) use (&$data) {
                    $label = trim($node->filter('label')->text(''));
                    if (! isset($this->mapping[$label])) {
                        return;
                    }
                    $key = $this->mapping[$label];
                    try {
                        $data[$key] = $node->filter('input')->attr('value');
                    } catch (\Exception $e) {
                        $data[$key] = null;
                    }
                });
                
                return $data;
            });
        });
    }
}
