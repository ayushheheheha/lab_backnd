<?php

namespace App\Http\Controllers;

use App\Models\VideoSolution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class VideoSolutionController extends Controller
{
    /**
     * Public list. Deliberately omits drive_file_id / youtube_id / embed_url so
     * the underlying video reference is never exposed through the list API.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $videos = VideoSolution::query()
            ->where('is_published', true)
            ->with('course:id,name,slug')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id', 'course_id', 'title', 'provider', 'description',
                'author', 'duration', 'thumbnail_url', 'chapters', 'is_pro', 'sort_order',
            ]);

        $payload = $videos->map(fn (VideoSolution $v) => [
            'id'            => $v->id,
            'course'        => $v->course
                ? ['id' => $v->course->id, 'name' => $v->course->name, 'slug' => $v->course->slug]
                : null,
            'title'         => $v->title,
            'provider'      => $v->provider,
            'description'   => $v->description,
            'author'        => $v->author,
            'duration'      => $v->duration,
            'thumbnail_url' => $v->thumbnail_url,
            'chapters'      => $v->chapters,
            'is_pro'        => $v->is_pro,
            'locked'        => ! $v->isAccessibleBy($user),
        ]);

        return response()->json($payload);
    }

    /**
     * Issues a short-lived signed URL to the hardened embed page — only for users
     * entitled to the video. The video reference itself never appears in this JSON.
     */
    public function play(Request $request, int $id): JsonResponse
    {
        $video = VideoSolution::query()->where('is_published', true)->findOrFail($id);

        if (! $video->isAccessibleBy($request->user())) {
            return response()->json(['error' => 'This video requires a Pro subscription.'], 403);
        }

        if (! $video->buildEmbedUrl()) {
            return response()->json(['error' => 'This video has no playable source.'], 422);
        }

        $embedSrc = URL::temporarySignedRoute('video.embed', now()->addMinutes(10), ['id' => $video->id]);

        return response()->json([
            'provider'  => $video->provider,
            'embed_src' => $embedSrc,
        ]);
    }

    /**
     * Server-rendered hardened player. Reached only via a valid signed URL (see
     * play()). Loaded inside an <iframe> in the SPA. The video id lives only in
     * this server-rendered markup, never in any JSON API.
     */
    public function embed(int $id): Response
    {
        $video = VideoSolution::query()->where('is_published', true)->findOrFail($id);

        $embedUrl = $video->buildEmbedUrl();
        abort_if($embedUrl === null, 404);

        $html = $video->provider === VideoSolution::PROVIDER_YOUTUBE
            ? $this->youtubeEmbedHtml($video->youtube_id, $video->title)
            : $this->driveEmbedHtml($embedUrl, $video->title);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')
            // Allow the SPA (any origin) to frame this player; access is gated by
            // the signed URL, not by origin.
            ->header('Content-Security-Policy', 'frame-ancestors *');
    }

    private function youtubeEmbedHtml(string $youtubeId, string $title): string
    {
        $idJson = json_encode($youtubeId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $template = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>__TITLE__</title>
<style>
  html,body{margin:0;height:100%;background:#000;overflow:hidden;font-family:system-ui,-apple-system,sans-serif}
  #stage{position:fixed;inset:0;background:#000}
  #player{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
  #shield{position:absolute;inset:0;z-index:1;cursor:pointer}
  #center{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;pointer-events:none}
  #bigplay{width:74px;height:74px;border-radius:50%;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;transition:opacity .2s}
  #bigplay.hidden{opacity:0}
  #bar{position:absolute;left:0;right:0;bottom:0;z-index:3;padding:9px 14px 11px;background:linear-gradient(transparent,rgba(0,0,0,.78));display:flex;align-items:center;gap:12px;opacity:0;transition:opacity .2s}
  #stage:hover #bar,#bar.show{opacity:1}
  .btn{background:none;border:0;color:#fff;cursor:pointer;display:flex;align-items:center;padding:0;line-height:0}
  #seek{flex:1;height:4px;-webkit-appearance:none;appearance:none;background:rgba(255,255,255,.28);border-radius:3px;cursor:pointer;outline:none}
  #seek::-webkit-slider-thumb{-webkit-appearance:none;width:13px;height:13px;border-radius:50%;background:#fff}
  #seek::-moz-range-thumb{width:13px;height:13px;border:0;border-radius:50%;background:#fff}
  #time{color:#fff;font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap}
</style>
</head>
<body oncontextmenu="return false">
<div id="stage">
  <div id="player"></div>
  <div id="shield"></div>
  <div id="center"><div id="bigplay">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="#fff"><polygon points="8,5 19,12 8,19"></polygon></svg>
  </div></div>
  <div id="bar">
    <button class="btn" id="pp" aria-label="Play/Pause">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="#fff"><polygon points="8,5 19,12 8,19"></polygon></svg>
    </button>
    <span id="time">0:00 / 0:00</span>
    <input id="seek" type="range" min="0" max="1000" value="0" step="1">
    <button class="btn" id="fs" aria-label="Fullscreen">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m13-5v3a2 2 0 0 0-2 2h-3"></path></svg>
    </button>
  </div>
</div>
<script>
(function(){
  var YT_ID = __YT_ID__;
  var player, ready=false, dragging=false;
  var ICON_PLAY='<svg width="22" height="22" viewBox="0 0 24 24" fill="#fff"><polygon points="8,5 19,12 8,19"></polygon></svg>';
  var ICON_PAUSE='<svg width="22" height="22" viewBox="0 0 24 24" fill="#fff"><rect x="6" y="5" width="4" height="14"></rect><rect x="14" y="5" width="4" height="14"></rect></svg>';

  function fmt(s){ s=Math.max(0,Math.floor(s||0)); var m=Math.floor(s/60), r=s%60; return m+':'+(r<10?'0':'')+r; }

  function isPlaying(){ return player && player.getPlayerState && player.getPlayerState()===1; }

  function toggle(){
    if(!ready||!player) return;
    if(isPlaying()){ player.pauseVideo(); } else { player.playVideo(); }
  }

  window.onYouTubeIframeAPIReady = function(){
    player = new YT.Player('player', {
      host: 'https://www.youtube-nocookie.com',
      width: '100%', height: '100%',
      videoId: YT_ID,
      playerVars: {controls:0,modestbranding:1,rel:0,disablekb:1,fs:0,iv_load_policy:3,playsinline:1,enablejsapi:1},
      events: { onReady: function(){ ready=true; }, onStateChange: onState }
    });
  };

  function onState(e){
    var big=document.getElementById('bigplay');
    var pp=document.getElementById('pp');
    if(e.data===1){ big.classList.add('hidden'); pp.innerHTML=ICON_PAUSE; }
    else { big.classList.remove('hidden'); pp.innerHTML=ICON_PLAY; }
  }

  document.getElementById('shield').addEventListener('click', toggle);
  document.getElementById('pp').addEventListener('click', toggle);

  var seek=document.getElementById('seek');
  seek.addEventListener('input', function(){ dragging=true; });
  seek.addEventListener('change', function(){
    if(ready&&player){ var d=player.getDuration()||0; player.seekTo(d*(seek.value/1000), true); }
    dragging=false;
  });

  document.getElementById('fs').addEventListener('click', function(){
    var el=document.getElementById('stage');
    if(document.fullscreenElement){ document.exitFullscreen(); }
    else if(el.requestFullscreen){ el.requestFullscreen(); }
  });

  setInterval(function(){
    if(!ready||!player||!player.getDuration) return;
    var d=player.getDuration()||0, c=player.getCurrentTime()||0;
    document.getElementById('time').textContent=fmt(c)+' / '+fmt(d);
    if(!dragging&&d>0){ seek.value=Math.round((c/d)*1000); }
  },250);
})();
</script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
HTML;

        return str_replace(['__TITLE__', '__YT_ID__'], [$titleSafe, $idJson], $template);
    }

    private function driveEmbedHtml(string $driveUrl, string $title): string
    {
        $urlSafe = htmlspecialchars($driveUrl, ENT_QUOTES, 'UTF-8');
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$titleSafe}</title>
<style>html,body{margin:0;height:100%;background:#000;overflow:hidden}iframe{position:fixed;inset:0;width:100%;height:100%;border:0}</style>
</head>
<body oncontextmenu="return false">
<iframe src="{$urlSafe}" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>
</body>
</html>
HTML;
    }
}
