<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class NpoRadioService
{
    private const API_URL = 'https://android-luister.api.nporadio.nl/graphql';

    private const ITEMS_PER_PAGE = 10;

    private const CACHE_DIR = 'npo-radio';

    private const GRAPHQL_QUERY = <<<'QUERY'
query GetBroadcastsByChannelAndProgram($channel: String!, $programme: String!, $order_by: String!, $order_direction: String!, $page: Int!, $urns: [ID], $limit: Int!) {
  radio_broadcasts(channel: $channel, programme: $programme, order_by: $order_by, order_direction: $order_direction, with_audio_assets: true, page: $page, urns: $urns, limit: $limit) {
    has_more_pages
    data {
      __typename
      ...radio_broadcast_fields
    }
  }
}

fragment consecutive_radio_broadcast on radio_broadcasts {
  urn
  radio_audio_assets(type: "show") {
    url
  }
}

fragment radio_broadcast_fields on radio_broadcasts {
  id
  slug
  name
  from
  until
  url
  urn
  description
  published_at
  radio_photo_assets {
    url(width: 600, height: 600)
  }
  core_broadcasters {
    name
  }
  radio_programmes {
    id
    name
    slug
    urn
    url
    radio_photo_assets {
      url(width: 600, height: 600)
    }
  }
  radio_audio_assets(type: "show") {
    url
    duration
  }
  radio_presenters {
    name
  }
  player {
    mid
  }
  next_radio_broadcast {
    __typename
    ...consecutive_radio_broadcast
  }
  previous_radio_broadcast {
    __typename
    ...consecutive_radio_broadcast
  }
}
QUERY;

    public function fetchBroadcasts(string $channel, string $programme, int $page = 1): array
    {
        $response = Http::post(self::API_URL, [
            'operationName' => 'GetBroadcastsByChannelAndProgram',
            'variables' => [
                'channel' => $channel,
                'programme' => $programme,
                'order_by' => 'from',
                'order_direction' => 'desc',
                'page' => $page,
                'limit' => self::ITEMS_PER_PAGE,
            ],
            'query' => self::GRAPHQL_QUERY,
            'extensions' => [
                'clientLibrary' => [
                    'name' => 'apollo-kotlin',
                    'version' => '4.3.3',
                ],
            ],
        ]);

        return $response->json();
    }

    public function fetchAllBroadcasts(string $channel, string $programme): array
    {
        $cacheFile = $this->getCacheFilePath($channel, $programme);

        if (!Storage::exists($cacheFile)) {
            $history = $this->fetchBroadcastHistory($channel, $programme);
            $uniqueHistory = $this->mergeBroadcastsById($history, []);
            $this->storeBroadcasts($cacheFile, $uniqueHistory);

            return $uniqueHistory;
        }

        $cachedBroadcasts = $this->readCachedBroadcasts($cacheFile);
        $latestResponse = $this->fetchBroadcasts($channel, $programme, 1);
        $latestBroadcasts = $latestResponse['data']['radio_broadcasts']['data'] ?? [];
        if (!is_array($latestBroadcasts)) {
            $latestBroadcasts = [];
        }
        $mergedBroadcasts = $this->mergeBroadcastsById($latestBroadcasts, $cachedBroadcasts);

        $this->storeBroadcasts($cacheFile, $mergedBroadcasts);

        return $mergedBroadcasts;
    }

    private function getCacheFilePath(string $channel, string $programme): string
    {
        return sprintf('%s/%s/%s.json', self::CACHE_DIR, $channel, $programme);
    }

    private function fetchBroadcastHistory(string $channel, string $programme): array
    {
        $allBroadcasts = [];
        $page = 1;
        $hasMorePages = true;

        while ($hasMorePages) {
            $response = $this->fetchBroadcasts($channel, $programme, $page);

            $broadcasts = $response['data']['radio_broadcasts']['data'] ?? null;
            if (!is_array($broadcasts)) {
                break;
            }

            $allBroadcasts = array_merge($allBroadcasts, $broadcasts);
            $hasMorePages = $response['data']['radio_broadcasts']['has_more_pages'] ?? false;
            $page++;
        }

        return $allBroadcasts;
    }

    private function readCachedBroadcasts(string $cacheFile): array
    {
        if (!Storage::exists($cacheFile)) {
            return [];
        }

        $contents = Storage::get($cacheFile);
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']['radio_broadcasts']['data']) && is_array($decoded['data']['radio_broadcasts']['data'])) {
            return $decoded['data']['radio_broadcasts']['data'];
        }

        if (isset($decoded['broadcasts']) && is_array($decoded['broadcasts'])) {
            return $decoded['broadcasts'];
        }

        if ($this->isListArray($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function storeBroadcasts(string $cacheFile, array $broadcasts): void
    {
        Storage::put($cacheFile, json_encode($broadcasts, JSON_PRETTY_PRINT));
    }

    private function mergeBroadcastsById(array $incoming, array $existing): array
    {
        $mergedById = [];

        foreach ($incoming as $broadcast) {
            if (!is_array($broadcast)) {
                continue;
            }

            if (!isset($broadcast['id'])) {
                $mergedById[] = $broadcast;
                continue;
            }

            $mergedById[$broadcast['id']] = $broadcast;
        }

        foreach ($existing as $broadcast) {
            if (!is_array($broadcast)) {
                continue;
            }

            if (!isset($broadcast['id'])) {
                $mergedById[] = $broadcast;
                continue;
            }

            if (!isset($mergedById[$broadcast['id']])) {
                $mergedById[$broadcast['id']] = $broadcast;
            }
        }

        return array_values($mergedById);
    }

    private function isListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
