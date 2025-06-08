<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class ExternalDataController extends Controller
{

  public function tester_failed()
  {
    $topic = "Entrepreneurship";
    $apiKey = config('services.newsapi.key'); // your Event Registry API key

    // Map your course topics/categories to keywords
    $keywords = [
      'Impact Investment 101'     => 'impact investing',
      'Entrepreneurship'          => 'entrepreneurship',
      'Cross-Sector Collaboration' => 'cross-sector collaboration',
      'Social Impact'             => 'social impact',
      'Financial Innovation'      => 'financial innovation',
    ];

    if (!isset($keywords[$topic])) {
      return response()->json(['error' => 'Invalid topic'], 400);
    }

    $query = $keywords[$topic];

    $response = Http::post('https://eventregistry.org/api/v1/article/getArticles', [
      'apiKey' => $apiKey,
      'keyword' => [
        'value' => $query,
        'operator' => 'AND'
      ],
      'articlesSortBy' => 'date',
      'articlesCount' => 5,
      'articlesArticleBodyLen' => 200,
      'articlesIncludeArticleImage' => true,
      'articlesIncludeSource' => true,
    ]);

    if ($response->failed()) {
      return response()->json(['error' => 'Failed to fetch news'], 500);
    }

    $articles = $response->json('articles.results', []);

    return response()->json([
      'topic' => $topic,
      'keyword' => $query,
      'news' => $articles
    ]);
  }

  public function temporary_external_data($topic)
  {
    $apiKey = config('services.newsapi.key');
    $query = $topic;

    $response = Http::withHeaders([
      'x-api-key' => $apiKey
    ])->get('https://newsapi.org/v2/everything', [
      'q' => $query,
      'language' => 'en',
      'sortBy' => 'publishedAt',
    ]);


    return response()->json($response->json());
  }
}
