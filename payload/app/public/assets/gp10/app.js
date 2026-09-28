const qs=new URLSearchParams(location.search),tab=qs.get('tab')||'overview';
const el=document.querySelector('ffxi-gear-center');
if(el)el.setAttribute('initial-tab',tab);
// Register/upgrade the custom element only after initial-tab exists. A static
// import executes before this module body and caused the 1.0.1 deep-link race.
await import('./embedded-panel.js');
el?.addEventListener('gearcenter-tab-change',e=>{const u=new URL(location.href);u.searchParams.set('tab',e.detail?.tab||'overview');history.replaceState(null,'',u)});
