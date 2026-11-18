<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>VNC · {{ $instance->display_name }}</title>
<style>body{margin:0;background:#182230;color:#fff;font:16px system-ui}header{padding:12px;display:flex;flex-wrap:wrap;gap:16px;align-items:center}button{font:inherit;padding:6px}#screen{height:calc(100vh - 110px);overflow:hidden}a{color:#9ac9ff}</style>
</head><body data-fingerprint="{{ $fingerprint }}">
<header><a href="/console/instances/{{ $instance->id }}">返回服务</a><span id="vnc-status" role="status">正在准备直连控制台…</span><button id="disconnect" type="button">断开连接</button><button id="send-cad" type="button" disabled>发送 Ctrl+Alt+Del</button></header>
<p>本站提供页面，浏览器直接连接远端控制台。仅打开页面不会重装、重启或关闭机器。</p>
<div id="screen"></div><script type="module" src="/js/direct-vnc.js"></script>
</body></html>
