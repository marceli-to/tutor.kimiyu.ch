<!DOCTYPE html>
<html lang="de-CH" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<style>
{!! $fontFaces !!}
:root{ {!! $paletteLight !!} }
:root[data-theme="dark"]{ {!! $paletteDark !!} }
{!! $baseCss !!}
</style>
<style>
{!! $hero['css'] !!}
</style>
<script>
/* Brücke zur Lernseite: Theme übernehmen, Höhe und Fehler melden. */
(function(){
  var root=document.documentElement;
  var setTheme=function(t){root.setAttribute('data-theme',t==='dark'?'dark':'light');};
  setTheme(location.hash.slice(1));
  var post=function(msg){msg.source='lernseite-hero';parent.postMessage(msg,'*');};
  window.addEventListener('message',function(e){
    if(e.source===parent&&e.data&&e.data.type==='theme')setTheme(e.data.theme);
  });
  window.addEventListener('error',function(e){post({type:'error',message:String(e.message||'Fehler')});});
  var last=0;
  var report=function(){var h=Math.ceil(document.body.scrollHeight);if(h!==last){last=h;post({type:'height',height:h});}};
  window.addEventListener('DOMContentLoaded',function(){new ResizeObserver(report).observe(document.body);});
  window.addEventListener('load',report);
})();
</script>
</head>
<body>
{!! $hero['markup'] !!}
<script>
{!! $hero['script'] !!}
</script>
</body>
</html>
