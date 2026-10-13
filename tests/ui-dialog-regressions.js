// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const between=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
const elements=new Map(),requests=[];
function $(key){
  if(!elements.has(key))elements.set(key,{length:1,value:'',content:'',props:{},attrs:{},classes:new Set(),
    val(v){if(v===undefined)return this.value;this.value=v;return this;},
    text(v){if(v===undefined)return this.content;this.content=v;return this;},
    html(v){this.content=v;return this;},show(){return this;},
    prop(k,v){this.props[k]=v;return this;},attr(k,v){this.attrs[k]=v;return this;},
    addClass(k){this.classes.add(k);return this;}});
  return elements.get(key);
}
$.extend=Object.assign;
const context={$,activePreflightSerial:0,activePreflightRequest:null,retryPreflight:null,activePreflight:null,
  activeMigrateButton:null,appSettings:{},esc:String,fmtBytes:String,fmtRamMib:String,flash(){},
  post(action,data){const request={action,data,readyState:1,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},always(fn){this.finish=fn;return this;},
    abort(){this.aborted=true;this.readyState=4;this.failure({statusText:'abort'});this.finish();},
    resolve(value){this.readyState=4;this.success(value);this.finish();},reject(value){this.readyState=4;this.failure(value);this.finish();}};requests.push(request);return request;}}
vm.createContext(context);
vm.runInContext(between('function cancelPreflight()','  function confirmRiskyMigration('),context);
$('#unm-peer-select').val('peer');$('#unm-default-iso').val('copy');
context.showPreflight('vm-1','Squid Proxy',null,{warm_seed_id:'seed-1',migration_mode:'warm-cutover',vcpu_count:2});
assert.ok($('#unm-modal').classes.has('open'),'Dialog opens before the network response.');
assert.match($('#unm-modal-content').content,/Checking VM storage/);
assert.equal($('#unm-confirm-migrate').props.disabled,true);
assert.equal($('#unm-recheck').props.disabled,true);
assert.equal(context.activePreflight,null,'An earlier ready preflight cannot authorize a new request.');
assert.equal(JSON.parse(requests[0].data.options).warm_seed_id,'seed-1');
requests[0].reject({responseText:JSON.stringify({error:'Destination unreachable'})});
assert.match($('#unm-modal-content').content,/Destination unreachable/);
assert.equal($('#unm-recheck').props.disabled,false);
context.recheckPreflight();
assert.deepEqual(requests[1].data,requests[0].data,'Retry retains original options after the form is replaced with an error.');
const ready={preflight:{ready:true,vm:{vcpus:1,memoryMiB:512},peer:{name:'Peer'}}};
requests[1].resolve(ready);
assert.equal(context.activePreflight,ready.preflight);assert.equal($('#unm-confirm-migrate').props.disabled,false);
assert.equal($('#unm-modal-content').attrs['aria-busy'],'false');
context.showPreflight('vm-2','Other VM');const old=requests.at(-1);
context.cancelPreflight();assert.ok(old.aborted);assert.equal(context.activePreflight,null);
const loading=$('#unm-modal-content').content;
old.success(ready);old.finish();assert.equal($('#unm-modal-content').content,loading,'Cancelled response cannot render or re-enable controls.');
assert.equal(context.activePreflight,null);
context.showPreflight('vm-3','Third VM');const superseded=requests.at(-1);
$('#unm-clone-customization').val('none-disconnected');
context.showClonePreflight('vm-4','Fourth VM','Fourth Clone');const clone=requests.at(-1);
assert.ok(superseded.aborted);superseded.success(ready);
assert.equal(context.activePreflight,null,'Out-of-order migration cannot overwrite the clone dialog.');
assert.match($('#unm-modal-title').content,/Clone Fourth VM/);
clone.reject({responseText:'not JSON'});context.recheckPreflight();
assert.equal(JSON.parse(requests.at(-1).data.options).guest_customization,'none-disconnected');
assert.equal(requests.at(-1).data.clone_name,'Fourth Clone');
requests.at(-1).resolve({preflight:{ready:false,errors:['Powered on'],clone:{customization:'none-disconnected'}}});
assert.equal($('#unm-confirm-migrate').props.disabled,true,'Blocked clone remains disabled.');
assert.match($('#unm-modal-content').content,/Powered on/);
assert.ok(source.includes("if(modal.is('#unm-modal'))cancelPreflight();"),'Closing the actual dialog invalidates read-only requests.');
assert.ok(requests.every(r=>['preflight','clonePreflight'].includes(r.action)),'No setup path submits a mutation.');
console.log('Immediate migration/clone dialogs, errors, retries, cancellation and late-response isolation passed.');
