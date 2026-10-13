// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const between=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
// Tiny DOM model measures writes/identity. Real focus and tooltip behavior is
// additionally checked in the Unraid browser; no runtime DOM dependency needed.
let writes=0;
class Cell {
  constructor(html){this.content=html;this.className='';this.colSpan=1;}
  get innerHTML(){return this.content;}
  set innerHTML(value){writes++;this.content=value;}
}
class Row {
  constructor(key,cells){this.key=key;this.children=cells.map(x=>new Cell(x));}
  getAttribute(key){return key==='data-row-id'?this.key:null;}
  remove(){if(this.parent){this.parent.children.splice(this.parent.children.indexOf(this),1);this.parent=null;}}
}
class Body {
  constructor(){this.children=[];}
  set innerHTML(value){this.children=JSON.parse(value).map(([key,...cells])=>new Row(key,cells));this.children.forEach(r=>r.parent=this);}
  insertBefore(row,before){row.remove();const i=before?this.children.indexOf(before):this.children.length;this.children.splice(i,0,row);row.parent=this;}
}
const context={document:{createElement:()=>new Body()}};vm.createContext(context);
vm.runInContext(between('function reconcileTableRows(','function renderInventory()'),context);
const body=new Body(),render=rows=>context.reconcileTableRows([body],JSON.stringify(rows));
const data=[['a','Squid Proxy','off','<details>disk</details>','button'],['b','Other VM','off','disk','button']];
render(data);const row=body.children[0],name=row.children[0],state=row.children[1],detail=row.children[2],button=row.children[3];
detail.content='<details open>disk</details>';button.content='Checking…';writes=0;
render(data);assert.equal(writes,0,'Unchanged observations perform zero cell writes.');
assert.equal(body.children[0],row);assert.equal(row.children[2],detail);assert.equal(row.children[3],button);
assert.match(detail.innerHTML,/open/,'User-opened details survive identical polling.');assert.equal(button.innerHTML,'Checking…','Busy controls survive polling.');
render([['a','Squid Proxy','running','<details>disk</details>','button'],data[1]]);
assert.equal(writes,1);assert.equal(row.children[1],state);assert.equal(state.innerHTML,'running');assert.equal(row.children[0],name);
render([data[1],['a','Squid Proxy','running','<details>disk</details>','button']]);
assert.equal(body.children[1],row,'Reordering moves existing rows, not copies.');
render([['a','Squid Proxy','Error']]);assert.equal(body.children.length,1);assert.equal(body.children[0].children.length,2,'Changed row shape replaces only that row.');
render([]);assert.equal(body.children.length,0,'Removed rows leave the DOM.');
let optionWrites=0,selected='b',optionData={};
const select={length:1,val(value){if(value===undefined)return selected;selected=value;return this;},html(){optionWrites++;return this;},data(key,value){if(value===undefined)return optionData[key];optionData[key]=value;return this;}};
Object.assign(context,{$:()=>select,peers:[{id:'a',name:'Peer A',host:'a'},{id:'b',name:'Peer B',host:'b'}],peerHealth:{a:{status:'healthy'},b:{status:'healthy'}},esc:String,healthLabel:h=>h.status,renderSelectedPeerHealth(){}});
vm.runInContext(between('function renderPeersSelect()','function pollPeerHealth()'),context);
context.renderPeersSelect();context.renderPeersSelect();assert.equal(optionWrites,1,'Unchanged health does not replace an open destination selector.');assert.equal(selected,'b');
context.peerHealth.a.status='unreachable';context.renderPeersSelect();assert.equal(optionWrites,2,'Changed health labels still update.');assert.equal(selected,'b','Health updates preserve destination selection.');
// Log polling does not replace identical text or pull readers away from history.
let text='Loading log…',top=0,textWrites=0;const requests=[];
const element={scrollHeight:1500,clientHeight:300};
function $(){return {0:element,text(value){if(value===undefined)return text;text=value;textWrites++;return this;},scrollTop(value){if(value===undefined)return top;top=value;return this;}};}
Object.assign(context,{$,currentLogId:'a',currentLogType:'job',logRequest:null,logSerial:1,logFirstRead:true,
  post(action,data){const r={action,data,readyState:1,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;},abort(){this.failure();this.finish();}};requests.push(r);return r;}});
vm.runInContext(between('function refreshLog()','function showLog('),context);
vm.runInContext(between('function cancelLogRead()','function renderSshStatus('),context);
context.refreshLog();context.refreshLog();assert.equal(requests.length,1,'Log reads do not overlap.');
requests[0].success({log:'first log'});requests[0].finish();assert.equal(top,1500,'Initial read follows the tail.');
top=100;textWrites=0;context.refreshLog();requests[1].success({log:'first log'});requests[1].finish();
assert.equal(textWrites,0,'Unchanged logs preserve selection.');assert.equal(top,100,'Scrolled-up reader stays put.');
context.refreshLog();requests[2].success({log:'first log\nnew line'});requests[2].finish();assert.equal(top,100,'New text does not pull history readers to the tail.');
top=1200;context.refreshLog();requests[3].success({log:'first log\nnew line\nmore'});requests[3].finish();assert.equal(top,1500,'Readers at the end follow output.');
context.refreshLog();context.cancelLogRead();requests[4].success({log:'stale log'});
assert.doesNotMatch(text,/stale/,'Cancelled/previous log responses cannot overwrite the current view.');
assert.equal(context.logRequest,null);
console.log('Keyed table/cell reconciliation and log single-flight, scrolling, selection and response isolation passed.');
