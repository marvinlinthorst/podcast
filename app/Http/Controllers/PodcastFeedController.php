<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class PodcastFeedController extends Controller
{
    public function __invoke(Request $request, string $channel, string $programme)
    {
        $cachePath = storage_path('app/' . sprintf('npo-radio/%s/%s.json', $channel, $programme));

        if (!File::exists($cachePath)) {
            abort(404, 'Feed not found.');
        }

        $payload = json_decode(File::get($cachePath), true);
        $broadcasts = $this->normalizeBroadcasts($payload);

        if (empty($broadcasts)) {
            abort(404, 'No broadcasts available for this feed.');
        }

        $rss = $this->buildRss($request, $channel, $programme, $broadcasts);

        return response($rss, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    private function normalizeBroadcasts($payload): array
    {
        if (isset($payload['data']['radio_broadcasts']['data']) && is_array($payload['data']['radio_broadcasts']['data'])) {
            return $payload['data']['radio_broadcasts']['data'];
        }

        if (isset($payload['broadcasts']) && is_array($payload['broadcasts'])) {
            return $payload['broadcasts'];
        }

        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function buildRss(Request $request, string $channel, string $programme, array $broadcasts): string
    {
        $first = $broadcasts[0];
        $programmeName = $first['radio_programmes']['name'] ?? $first['name'] ?? $programme;
        $siteUrl = $first['radio_programmes']['url'] ?? $first['url'] ?? url('/');
        $description = $first['radio_programmes']['description'] ?? $first['description'] ?? sprintf('Episodes for %s', $programmeName);
        $selfUrl = $request->fullUrl();
        $language = 'nl-NL';
        $lastBuildDate = $this->formatRfc2822($first['from'] ?? null) ?? $this->formatRfc2822(now()->toDateTimeString());

        $items = [];

        foreach ($broadcasts as $broadcast) {

            $title = $broadcast['name'] ?? $programmeName;
            $link = $broadcast['url'] ?? $siteUrl;
            $guid = $broadcast['id'] ?? $broadcast['urn'] ?? md5($title.$link);
            $pubDate = $this->formatRfc2822($broadcast['from'] ?? null);
            $itemDescription = $broadcast['description'] ?? $description;
            $duration = $this->formatDuration($broadcast);
            $enclosureLength = 0;
            $audioUrl = $broadcast['radio_audio_assets'][0]['url'] ?? null;

            $item = "    <item>\n";
            $item .= '      <title>'.$this->xmlEscape($title)."</title>\n";
            $item .= '      <link>'.$this->xmlEscape($link)."</link>\n";
            $item .= '      <guid isPermaLink="false">'.$this->xmlEscape($guid)."</guid>\n";
            $item .= '      <description>'.$this->cdata($itemDescription)."</description>\n";

            if ($pubDate) {
                $item .= '      <pubDate>'.$pubDate."</pubDate>\n";
            }

            if ($audioUrl) {
                $item .= '      <enclosure url="'.$this->xmlEscape($audioUrl).'" length="'.$enclosureLength.'" type="audio/mpeg" />'."\n";
            }

            if ($duration) {
                $item .= '      <itunes:duration>'.$duration."</itunes:duration>\n";
            }

            $item .= "    </item>";
            $items[] = $item;
        }

        $itemsXml = implode("\n", $items);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
  <channel>
    <title>{$this->xmlEscape($programmeName)}</title>
    <link>{$this->xmlEscape($siteUrl)}</link>
    <description>{$this->cdata($description)}</description>
    <language>{$this->xmlEscape($language)}</language>
    <lastBuildDate>{$lastBuildDate}</lastBuildDate>
    <atom:link href="{$this->xmlEscape($selfUrl)}" rel="self" type="application/rss+xml" />
{$itemsXml}
  </channel>
</rss>
XML;
    }

    private function formatRfc2822(?string $dateTime): ?string
    {
        if (!$dateTime) {
            return null;
        }

        try {
            return date(DATE_RSS, strtotime($dateTime));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatDuration(array $broadcast): ?string
    {
        $durationMs = $broadcast['radio_audio_assets'][0]['duration'] ?? null;

        if ($durationMs === null || $durationMs <= 0) {
            return null;
        }

        $seconds = (int) round($durationMs / 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function cdata(string $value): string
    {
        $value = str_replace(']]>', ']]]]><![CDATA[>', $value);

        return '<![CDATA['.$value.']]>';
    }
}
