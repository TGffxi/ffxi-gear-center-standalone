const DEFAULT_BASE='http://127.0.0.1:8765';

export class GearCenterApiError extends Error{constructor(message,{status=null,code=null,detail=null}={}){super(message);this.name='GearCenterApiError';this.status=status;this.code=code;this.detail=detail;}}

async function readJsonResponse(r,label='Gear Center API'){
  const type=String(r.headers.get('content-type')||'').toLowerCase();
  const text=await r.text();
  if(!type.includes('application/json'))throw new GearCenterApiError(`${label}: erwartete JSON-Antwort, erhalten: ${type||'unbekannter Content-Type'}`,{status:r.status,detail:text.slice(0,240)});
  let d;try{d=JSON.parse(text)}catch(e){throw new GearCenterApiError(`${label}: ungültige JSON-Antwort`,{status:r.status,detail:String(e?.message||e)});}
  if(!d||typeof d!=='object'||Array.isArray(d))throw new GearCenterApiError(`${label}: unerwartetes Antwortformat`,{status:r.status});
  if(!r.ok||d.ok===false){const code=String(d.error||'api-error'),detail=String(d.detail||'').trim();throw new GearCenterApiError(detail?`${code}: ${detail}`:code,{status:r.status,code,detail});}
  return d;
}

async function getJson(path,baseUrl=DEFAULT_BASE){const r=await fetch(`${baseUrl}${path}`,{cache:'no-store'});return readJsonResponse(r,`Gear Center API ${path}`);}
export async function loadDashboard(b=DEFAULT_BASE){const d=await getJson('/api/v1/dashboard',b);if(d.schema!=='gearcenter.dashboard/1.0'||!d.status||!d.state||!d.plan||!d.farming)throw new GearCenterApiError('Gear Center API /dashboard: erwartetes Dashboard-Schema fehlt',{code:'schema-error'});return d;}
export async function updatePersonalGoal(taskId,{action='set',priority=null,note=null}={},baseUrl=DEFAULT_BASE){const payload={action,task_id:taskId};if(priority!==null&&priority!==undefined&&priority!=='')payload.priority=Number(priority);if(note!==null&&note!==undefined)payload.note=String(note);const r=await fetch(`${baseUrl}/api/v1/goals`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),cache:'no-store'});return readJsonResponse(r,'Gear Center API /goals');}
export async function refreshPerformance(baseUrl=DEFAULT_BASE){const r=await fetch(`${baseUrl}/api/v1/performance/refresh`,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}',cache:'no-store'});return readJsonResponse(r,'Gear Center API /performance/refresh');}

// Aggregate only for high-level counts. Physical inventory rendering uses
// physicalInventoryRows() below so different augmented copies never collapse.
export function indexOwned(state){const by=new Map();for(const item of state?.items||[]){const id=Number(item.item_id),e=by.get(id)||{itemId:id,name:item.name||null,count:0,locations:[]};e.count+=Number(item.count||0);e.locations.push({containerId:item.container_id,containerName:item.container_name,slot:item.slot,count:item.count,equippedSlots:item.equipped_slots||[],extraHex:item.extra_hex||null,decodedAugments:item.decoded_augments||[],augmentSource:item.augment_source||null});by.set(id,e);}return by;}

export function physicalInventoryRows(state){
  return (state?.items||[]).map((item,index)=>({
    key:`${Number(item.container_id??-1)}:${Number(item.slot??-1)}:${Number(item.item_id??0)}:${index}`,
    itemId:Number(item.item_id||0),
    name:item.name||null,
    count:Number(item.count||0),
    containerId:item.container_id,
    containerName:item.container_name||null,
    slot:item.slot,
    equippedSlots:item.equipped_slots||[],
    extraHex:item.extra_hex||null,
    decodedAugments:[...(item.decoded_augments||[])],
    augmentSource:item.augment_source||null,
  })).sort((a,b)=>(a.name||'').localeCompare(b.name||'','de')||Number(a.containerId)-Number(b.containerId)||Number(a.slot)-Number(b.slot));
}

export function currencyRows(state,catalog){const prov=state?.provenance?.currencies||{},values=state?.currencies||{},rows=[],seen=new Set();for(const [packet,fields] of Object.entries(catalog?.packets||{})){for(const f of fields||[]){const key=String(f.key||'');if(!key||seen.has(key))continue;seen.add(key);const known=values[key]!==null&&values[key]!==undefined;rows.push({key,label:f.label||key,packet,known,value:known?values[key]:null,source:known?(prov[key]||'GearplanerBridge'):'noch nicht erfasst'});}}if(!seen.has('gil')){const known=values.gil!==null&&values.gil!==undefined;rows.push({key:'gil',label:'Gil',packet:'Inventar',known,value:known?values.gil:null,source:known?(prov.gil||'GearplanerBridge'):'noch nicht erfasst'});}return rows.sort((a,b)=>a.label.localeCompare(b.label,'de'));}
export const fmt=v=>v===null||v===undefined?'—':(Number.isFinite(Number(v))?Number(v).toLocaleString('de-DE'):String(v));
export function age(seconds){if(seconds===null||seconds===undefined)return'—';const n=Number(seconds);if(!Number.isFinite(n))return String(seconds);if(n<60)return`${Math.round(n)} s`;if(n<3600)return`${Math.round(n/60)} min`;return`${(n/3600).toFixed(1)} h`;}
