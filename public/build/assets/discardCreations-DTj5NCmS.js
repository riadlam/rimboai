import{b as n}from"./api-BOqpZ6KE.js";async function s(a,e){const i=[...new Set(e.filter(t=>Number.isFinite(t)&&t>0))];i.length!==0&&await n("/lab/creations/discard",{type:a,ids:i})}export{s as d};
