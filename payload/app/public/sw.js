const CACHE='ffxi-gear-center-v1.0.2-rules1';
const SHELL=['/','/manifest.webmanifest','/icon.svg','/assets/gp10/app.js','/assets/gp10/embedded-panel.js','/assets/gp10/character-state.js'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(SHELL)).then(()=>self.skipWaiting())));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
  const req=e.request;if(req.method!=='GET')return;
  const url=new URL(req.url);
  const isApi=url.port==='8765'||url.pathname.startsWith('/api/');
  if(isApi){e.respondWith(fetch(req,{cache:'no-store'}));return;}
  if(req.mode==='navigate'){e.respondWith(fetch(req).then(r=>{if(r.ok){const copy=r.clone();caches.open(CACHE).then(c=>c.put(req,copy));}return r;}).catch(async()=>await caches.match(req)||await caches.match('/')));return;}
  e.respondWith(fetch(req).then(r=>{if(r.ok&&url.origin===self.location.origin){const copy=r.clone();caches.open(CACHE).then(c=>c.put(req,copy));}return r;}).catch(()=>caches.match(req).then(r=>r||Response.error())));
});
