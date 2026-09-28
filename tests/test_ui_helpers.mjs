import {physicalInventoryRows} from '../payload/app/public/assets/gp10/character-state.js';
const rows=physicalInventoryRows({items:[
  {item_id:26258,name:"Andartia's Mantle",count:1,container_id:8,container_name:'Wardrobe',slot:1,decoded_augments:['DEX+20']},
  {item_id:26258,name:"Andartia's Mantle",count:1,container_id:8,container_name:'Wardrobe',slot:2,decoded_augments:['STR+20']},
]});
if(rows.length!==2)throw new Error(`expected 2 physical rows, got ${rows.length}`);
if(rows[0].key===rows[1].key)throw new Error('physical keys collapsed');
const aug=rows.map(x=>x.decodedAugments.join('|')).sort();
if(aug.join(',')!=='DEX+20,STR+20')throw new Error(`augment instances collapsed: ${aug}`);
console.log('PASS: physical inventory keeps duplicate augmented copies separate');
